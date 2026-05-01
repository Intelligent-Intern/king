<?php

declare(strict_types=1);

/**
 * Subordinate llama.cpp server process owned by the King inference node.
 *
 * King does NOT ship its own inference engine. It owns every surface around
 * the engine — hardware profile, model-fit selection, artifact storage,
 * transport, routing, failover, telemetry — while llama.cpp is the real
 * execution engine behind the King-native inference contract. That is a
 * deliberate scope fence (tracker sections V and Z) and is called out in
 * documentation/dev/model-inference.md.
 *
 * Lifecycle states:
 *   stopped   — no process active; start() transitions to starting
 *   starting  — proc_open() succeeded, /health not yet 200
 *   ready     — /health returned 200; stop() / drain() transitions out
 *   draining  — SIGTERM sent, waiting for clean exit
 *   error     — process died unexpectedly; inspect logTail() for reason
 *
 * Honesty rules:
 *  - No mock mode. If the llama.cpp binary is missing, start() throws.
 *    Callers MUST decide what to do (skip the test, surface an error).
 *  - Health state reflects the live /health probe, not cached folklore.
 *  - drain() always ends in state=stopped with the child process reaped;
 *    a hung child is SIGKILLed after the configured drain deadline.
 *  - No TCP port is picked by this class; callers pass an ephemeral port
 *    so fleet-level port coordination stays explicit.
 */
final class LlamaCppWorker
{
    public const STATE_STOPPED = 'stopped';
    public const STATE_STARTING = 'starting';
    public const STATE_READY = 'ready';
    public const STATE_DRAINING = 'draining';
    public const STATE_ERROR = 'error';

    /** @var resource|null */
    private $process = null;
    private ?int $pid = null;
    private int $port = 0;
    private string $state = self::STATE_STOPPED;
    private string $binaryPath;
    private string $libraryPath;
    private ?string $ggufPath = null;
    private ?string $logPath = null;
    private ?string $startedAt = null;

    public function __construct(string $binaryPath, string $libraryPath)
    {
        if (!is_file($binaryPath) || !is_executable($binaryPath)) {
            throw new RuntimeException("llama.cpp binary is not executable: {$binaryPath}");
        }
        if (!is_dir($libraryPath)) {
            throw new RuntimeException("llama.cpp library path is not a directory: {$libraryPath}");
        }
        $this->binaryPath = $binaryPath;
        $this->libraryPath = $libraryPath;
    }

    /**
     * Spawn llama-server against the given GGUF on the given loopback port.
     *
     * @param array<string, mixed> $options supported keys:
     *   - context_tokens: int (llama.cpp -c, default 512)
     *   - max_new_tokens: int (llama.cpp -n cap, default 64)
     *   - log_path:       string (stdout+stderr merge target)
     *   - extra_argv:     array<int, string> appended verbatim
     */
    public function start(string $ggufPath, int $port, array $options = []): void
    {
        if ($this->state !== self::STATE_STOPPED) {
            throw new RuntimeException("cannot start worker in state {$this->state}; call stop() first");
        }
        if (!is_file($ggufPath)) {
            throw new RuntimeException("GGUF artifact not found: {$ggufPath}");
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("invalid port {$port}");
        }

        $contextTokens = (int) ($options['context_tokens'] ?? 512);
        $maxNewTokens = (int) ($options['max_new_tokens'] ?? 64);
        $logPath = (string) ($options['log_path'] ?? (sys_get_temp_dir() . '/llama-worker-' . $port . '.log'));
        $extraArgv = (array) ($options['extra_argv'] ?? []);

        $argv = [
            $this->binaryPath,
            '-m', $ggufPath,
            '--host', '127.0.0.1',
            '--port', (string) $port,
            '-c', (string) $contextTokens,
            '-n', (string) $maxNewTokens,
            '--no-webui',
        ];
        foreach ($extraArgv as $arg) {
            $argv[] = (string) $arg;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logPath, 'a'],
            2 => ['file', $logPath, 'a'],
        ];
        $env = [
            'LD_LIBRARY_PATH' => $this->libraryPath,
            'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];
        $process = @proc_open($argv, $descriptors, $pipes, null, $env, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('proc_open failed to spawn llama-server');
        }
        $status = proc_get_status($process);
        if (!isset($status['pid']) || !$status['running']) {
            proc_close($process);
            $this->state = self::STATE_ERROR;
            throw new RuntimeException('llama-server died before observation; inspect ' . $logPath);
        }

        $this->process = $process;
        $this->pid = (int) $status['pid'];
        $this->port = $port;
        $this->ggufPath = $ggufPath;
        $this->logPath = $logPath;
        $this->state = self::STATE_STARTING;
        $this->startedAt = gmdate('c');
    }

    /**
     * Poll the worker's /health endpoint through the King HTTP/1 client.
     *
     * @return array{state: string, http_status: int, body: string, pid: ?int, port: int}
     */
    public function health(int $timeoutMs = 500): array
    {
        $this->reconcileState();

        if ($this->state === self::STATE_STOPPED || $this->state === self::STATE_ERROR) {
            return [
                'state' => $this->state,
                'http_status' => 0,
                'body' => '',
                'pid' => $this->pid,
                'port' => $this->port,
            ];
        }

        $url = sprintf('http://127.0.0.1:%d/health', $this->port);
        $probeResult = $this->probeHealth($url, $timeoutMs);

        if ($probeResult['http_status'] === 200 && $this->state === self::STATE_STARTING) {
            $this->state = self::STATE_READY;
        }

        return [
            'state' => $this->state,
            'http_status' => $probeResult['http_status'],
            'body' => $probeResult['body'],
            'pid' => $this->pid,
            'port' => $this->port,
        ];
    }

    /**
     * Block until health probe returns 200 or the deadline expires.
     */
    public function waitForReady(int $overallTimeoutMs = 10000, int $pollIntervalMs = 250): void
    {
        if ($this->state === self::STATE_STOPPED) {
            throw new RuntimeException('cannot wait on a stopped worker; call start() first');
        }
        $deadline = microtime(true) + ($overallTimeoutMs / 1000.0);
        while (microtime(true) < $deadline) {
            $snapshot = $this->health(500);
            if ($snapshot['state'] === self::STATE_READY) {
                return;
            }
            if ($snapshot['state'] === self::STATE_ERROR || $snapshot['state'] === self::STATE_STOPPED) {
                throw new RuntimeException("worker exited before reaching ready; state={$snapshot['state']} tail=" . $this->logTail(40));
            }
            usleep($pollIntervalMs * 1000);
        }
        throw new RuntimeException("worker did not reach /health=200 within {$overallTimeoutMs} ms");
    }

    /**
     * Send SIGTERM and wait for the child to exit cleanly; SIGKILL after
     * the deadline. Always transitions to state=stopped.
     */
    public function drain(int $timeoutMs = 5000): void
    {
        if (!is_resource($this->process)) {
            $this->state = self::STATE_STOPPED;
            return;
        }
        $this->state = self::STATE_DRAINING;
        @proc_terminate($this->process, 15);

        $deadline = microtime(true) + ($timeoutMs / 1000.0);
        while (microtime(true) < $deadline) {
            $status = @proc_get_status($this->process);
            if (!$status['running']) {
                $this->finalizeTeardown();
                return;
            }
            usleep(50_000);
        }
        @proc_terminate($this->process, 9);
        $finalStatus = @proc_get_status($this->process);
        if ($finalStatus['running']) {
            // Last-resort SIGKILL via kernel; proc_close will reap.
            usleep(200_000);
        }
        $this->finalizeTeardown();
    }

    public function stop(): void
    {
        $this->drain(2_000);
    }

    public function state(): string
    {
        $this->reconcileState();
        return $this->state;
    }

    public function pid(): ?int
    {
        return $this->pid;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function ggufPath(): ?string
    {
        return $this->ggufPath;
    }

    public function logPath(): ?string
    {
        return $this->logPath;
    }

    public function startedAt(): ?string
    {
        return $this->startedAt;
    }

    public function logTail(int $lines = 20): string
    {
        if ($this->logPath === null || !is_file($this->logPath)) {
            return '';
        }
        $contents = @file_get_contents($this->logPath);
        if (!is_string($contents)) {
            return '';
        }
        $pieces = preg_split('/\R/', $contents) ?: [];
        $tail = array_slice($pieces, -$lines);
        return implode("\n", $tail);
    }

    /**
     * Snapshot the worker state for /api/worker diagnostics.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $snapshot = $this->health();
        return [
            'state' => $snapshot['state'],
            'pid' => $this->pid,
            'port' => $this->port,
            'gguf_path' => $this->ggufPath,
            'binary_path' => $this->binaryPath,
            'library_path' => $this->libraryPath,
            'log_path' => $this->logPath,
            'started_at' => $this->startedAt,
            'health' => [
                'http_status' => $snapshot['http_status'],
                'body' => $snapshot['body'],
            ],
        ];
    }

    /**
     * Observe whether the child is still running. If not, mark as stopped
     * or error depending on the prior state.
     */
    private function reconcileState(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        $status = @proc_get_status($this->process);
        if ($status['running']) {
            return;
        }
        $exitCode = (int) $status['exitcode'];
        if ($this->state === self::STATE_DRAINING) {
            $this->finalizeTeardown();
            return;
        }
        // Unplanned exit.
        $this->state = self::STATE_ERROR;
        $this->finalizeTeardown($exitCode);
    }

    private function finalizeTeardown(?int $exitCode = null): void
    {
        if (is_resource($this->process)) {
            @proc_close($this->process);
        }
        $this->process = null;
        $this->pid = null;
        $this->port = 0;
        $this->ggufPath = null;
        if ($this->state !== self::STATE_ERROR) {
            $this->state = self::STATE_STOPPED;
        }
        $this->startedAt = null;
        unset($exitCode); // reserved for future diagnostic capture
    }

    /**
     * @return array{http_status: int, body: string}
     */
    private function probeHealth(string $url, int $timeoutMs): array
    {
        // Prefer King's HTTP/1 client (dogfoods the native transport); fall
        // back to a short PHP stream probe when the King client is not
        // available (e.g. extension not loaded inside a unit test).
        if (function_exists('king_http1_request_send')) {
            try {
                $response = king_http1_request_send($url, 'GET', null, null, [
                    'timeout_ms' => $timeoutMs,
                ]);
                if (is_array($response)) {
                    return [
                        'http_status' => (int) ($response['status'] ?? $response['status_code'] ?? 0),
                        'body' => (string) ($response['body'] ?? ''),
                    ];
                }
            } catch (Throwable $ignored) {
                // fall through to the stream probe
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutMs / 1000.0,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }
        return [
            'http_status' => $status,
            'body' => is_string($body) ? $body : '',
        ];
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            $this->drain(2_000);
        }
    }
}
