<?php
declare(strict_types=1);

namespace King\Flow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class McpHostRequest
{
    public function __construct(
        private string $operation,
        private string $service,
        private string $method,
        private ?string $payload,
        private ?string $streamIdentifier,
        private int $timeoutBudgetMs,
        private int $deadlineBudgetMs
    ) {
        if (!in_array($operation, ['request', 'upload', 'download'], true)) {
            throw new InvalidArgumentException('operation must be request, upload, or download.');
        }

        if ($timeoutBudgetMs < 0 || $deadlineBudgetMs < 0) {
            throw new InvalidArgumentException('timeout and deadline budgets must be zero or greater.');
        }
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function service(): string
    {
        return $this->service;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function payload(): ?string
    {
        return $this->payload;
    }

    public function streamIdentifier(): ?string
    {
        return $this->streamIdentifier;
    }

    public function timeoutBudgetMs(): int
    {
        return $this->timeoutBudgetMs;
    }

    public function deadlineBudgetMs(): int
    {
        return $this->deadlineBudgetMs;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'service' => $this->service,
            'method' => $this->method,
            'payload' => $this->payload,
            'stream_identifier' => $this->streamIdentifier,
            'timeout_budget_ms' => $this->timeoutBudgetMs,
            'deadline_budget_ms' => $this->deadlineBudgetMs,
        ];
    }
}

final class McpHostResponse
{
    private const STATUS_OK = 'ok';
    private const STATUS_MISS = 'miss';
    private const STATUS_ERROR = 'error';

    private function __construct(private string $status, private ?string $payload = null)
    {
    }

    public static function ok(?string $payload = null): self
    {
        return new self(self::STATUS_OK, $payload);
    }

    public static function miss(): self
    {
        return new self(self::STATUS_MISS);
    }

    public static function error(string $message): self
    {
        if ($message === '') {
            throw new InvalidArgumentException('error message must not be empty.');
        }

        return new self(self::STATUS_ERROR, $message);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function payload(): ?string
    {
        return $this->payload;
    }

    public function toFrame(): string
    {
        if ($this->status === self::STATUS_MISS) {
            return 'MISS';
        }

        if ($this->status === self::STATUS_ERROR) {
            return "ERR\t" . base64_encode((string) $this->payload);
        }

        if ($this->payload === null) {
            return 'OK';
        }

        return "OK\t" . base64_encode($this->payload);
    }
}

final class McpHostServeResult
{
    public function __construct(
        private int $connectionsAccepted,
        private int $commandsHandled,
        private int $protocolErrors,
        private int $handlerErrors,
        private string $stopReason
    ) {
    }

    public function connectionsAccepted(): int
    {
        return $this->connectionsAccepted;
    }

    public function commandsHandled(): int
    {
        return $this->commandsHandled;
    }

    public function protocolErrors(): int
    {
        return $this->protocolErrors;
    }

    public function handlerErrors(): int
    {
        return $this->handlerErrors;
    }

    public function stopReason(): string
    {
        return $this->stopReason;
    }

    /**
     * @return array<string,int|string>
     */
    public function toArray(): array
    {
        return [
            'connections_accepted' => $this->connectionsAccepted,
            'commands_handled' => $this->commandsHandled,
            'protocol_errors' => $this->protocolErrors,
            'handler_errors' => $this->handlerErrors,
            'stop_reason' => $this->stopReason,
        ];
    }
}

final class McpHost
{
    private const DEFAULT_MAX_LINE_BYTES = 8 * 1024 * 1024;
    private const STOP_REASON_MAX_COMMANDS = 'max_commands';
    private const STOP_REASON_STOP_COMMAND = 'stop_command';
    private const STOP_REASON_SHUTDOWN = 'shutdown';

    /** @var resource|null */
    private $server = null;

    private bool $running = false;
    private bool $stopRequested = false;
    private int $boundPort;
    private int $connectionsAccepted = 0;
    private int $commandsHandled = 0;
    private int $protocolErrors = 0;
    private int $handlerErrors = 0;

    public function __construct(
        private string $host = '127.0.0.1',
        int $port = 0,
        private int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES
    ) {
        if ($port < 0 || $port > 65535) {
            throw new InvalidArgumentException('port must be between 0 and 65535.');
        }

        if ($this->maxLineBytes <= 0) {
            throw new InvalidArgumentException('maxLineBytes must be greater than zero.');
        }

        $this->boundPort = $port;
    }

    public function start(): void
    {
        if ($this->running) {
            throw new RuntimeException('mcp host is already running.');
        }

        $server = @stream_socket_server(
            $this->endpointFor($this->host, $this->boundPort),
            $errno,
            $errstr
        );

        if (!is_resource($server)) {
            throw new RuntimeException(sprintf(
                'mcp host failed to start on %s:%d: %s',
                $this->host,
                $this->boundPort,
                $errstr
            ));
        }

        stream_set_blocking($server, true);
        $this->server = $server;
        $this->running = true;
        $this->stopRequested = false;

        $this->hydrateBoundAddress();
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->boundPort;
    }

    public function endpoint(): string
    {
        return $this->endpointFor($this->host, $this->boundPort);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function serve(callable $dispatcher, ?int $maxCommands = null, int $acceptTimeoutMs = 200): McpHostServeResult
    {
        if (!$this->running || !is_resource($this->server)) {
            throw new RuntimeException('mcp host is not running.');
        }

        if ($maxCommands !== null && $maxCommands <= 0) {
            throw new InvalidArgumentException('maxCommands must be greater than zero when provided.');
        }

        if ($acceptTimeoutMs < 0) {
            throw new InvalidArgumentException('acceptTimeoutMs must be zero or greater.');
        }

        $startConnections = $this->connectionsAccepted;
        $startCommands = $this->commandsHandled;
        $startProtocolErrors = $this->protocolErrors;
        $startHandlerErrors = $this->handlerErrors;

        $stopReason = self::STOP_REASON_SHUTDOWN;

        while ($this->running && is_resource($this->server)) {
            if ($maxCommands !== null && (($this->commandsHandled - $startCommands) >= $maxCommands)) {
                $stopReason = self::STOP_REASON_MAX_COMMANDS;
                break;
            }

            $client = @stream_socket_accept(
                $this->server,
                ((float) $acceptTimeoutMs) / 1000.0
            );

            if (!is_resource($client)) {
                continue;
            }

            $this->connectionsAccepted++;

            try {
                $this->processClient($client, $dispatcher);
            } finally {
                if (is_resource($client)) {
                    fclose($client);
                }
            }

            if ($this->stopRequested) {
                $stopReason = self::STOP_REASON_STOP_COMMAND;
                $this->shutdown();
                break;
            }
        }

        return new McpHostServeResult(
            $this->connectionsAccepted - $startConnections,
            $this->commandsHandled - $startCommands,
            $this->protocolErrors - $startProtocolErrors,
            $this->handlerErrors - $startHandlerErrors,
            $stopReason
        );
    }

    public function shutdown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }

        $this->server = null;
        $this->running = false;
        $this->stopRequested = false;
    }

    /**
     * @return array<string,int|bool|string>
     */
    public function stats(): array
    {
        return [
            'running' => $this->running,
            'host' => $this->host,
            'port' => $this->boundPort,
            'connections_accepted' => $this->connectionsAccepted,
            'commands_handled' => $this->commandsHandled,
            'protocol_errors' => $this->protocolErrors,
            'handler_errors' => $this->handlerErrors,
        ];
    }

    private function processClient($client, callable $dispatcher): void
    {
        stream_set_blocking($client, true);
        stream_set_timeout($client, 5);
        $loopbackPeer = $this->isLoopbackPeer($client);

        while (($line = stream_get_line($client, $this->maxLineBytes, "\n")) !== false) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }

            $this->commandsHandled++;

            if ($line === 'STOP') {
                if (!$loopbackPeer) {
                    $this->protocolErrors++;
                    $this->writeFrame($client, McpHostResponse::error(
                        'mcp host STOP command is restricted to loopback clients.'
                    )->toFrame());
                    continue;
                }

                $this->writeFrame($client, 'OK');
                $this->stopRequested = true;
                break;
            }

            [$request, $parseError] = $this->parseFrame($line);
            if (!$request instanceof McpHostRequest) {
                $this->protocolErrors++;
                $this->writeFrame($client, McpHostResponse::error(
                    $parseError ?? 'mcp host received an invalid command frame.'
                )->toFrame());
                continue;
            }

            try {
                $response = $dispatcher($request);
            } catch (Throwable $error) {
                $this->handlerErrors++;
                $this->writeFrame($client, McpHostResponse::error(
                    'MCP host dispatcher failed: ' . $error->getMessage()
                )->toFrame());
                continue;
            }

            if (!$response instanceof McpHostResponse) {
                $this->handlerErrors++;
                $this->writeFrame($client, McpHostResponse::error(
                    'MCP host dispatcher must return King\\Flow\\McpHostResponse.'
                )->toFrame());
                continue;
            }

            $this->writeFrame($client, $response->toFrame());
        }
    }

    /**
     * @return array{0:?McpHostRequest,1:?string}
     */
    private function parseFrame(string $line): array
    {
        $parts = explode("\t", $line);
        $opcode = array_shift($parts);

        if ($opcode === 'REQ' && (count($parts) === 3 || count($parts) === 5)) {
            $service = $this->decodeField($parts[0]);
            $method = $this->decodeField($parts[1]);
            $payload = $this->decodeField($parts[2]);

            if ($service === null || $method === null || $payload === null) {
                return [null, 'mcp host received an invalid request frame.'];
            }

            return [
                new McpHostRequest(
                    'request',
                    $service,
                    $method,
                    $payload,
                    null,
                    $this->parseBudget($parts, 3),
                    $this->parseBudget($parts, 4)
                ),
                null,
            ];
        }

        if ($opcode === 'PUT' && (count($parts) === 4 || count($parts) === 6)) {
            $service = $this->decodeField($parts[0]);
            $method = $this->decodeField($parts[1]);
            $streamIdentifier = $this->decodeField($parts[2]);
            $payload = $this->decodeField($parts[3]);

            if (
                $service === null
                || $method === null
                || $streamIdentifier === null
                || $payload === null
            ) {
                return [null, 'mcp host received an invalid upload frame.'];
            }

            return [
                new McpHostRequest(
                    'upload',
                    $service,
                    $method,
                    $payload,
                    $streamIdentifier,
                    $this->parseBudget($parts, 4),
                    $this->parseBudget($parts, 5)
                ),
                null,
            ];
        }

        if ($opcode === 'GET' && (count($parts) === 3 || count($parts) === 5)) {
            $service = $this->decodeField($parts[0]);
            $method = $this->decodeField($parts[1]);
            $streamIdentifier = $this->decodeField($parts[2]);

            if ($service === null || $method === null || $streamIdentifier === null) {
                return [null, 'mcp host received an invalid download frame.'];
            }

            return [
                new McpHostRequest(
                    'download',
                    $service,
                    $method,
                    null,
                    $streamIdentifier,
                    $this->parseBudget($parts, 3),
                    $this->parseBudget($parts, 4)
                ),
                null,
            ];
        }

        return [null, 'mcp host received an unsupported command frame.'];
    }

    private function decodeField(string $encoded): ?string
    {
        $decoded = base64_decode($encoded, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @param array<int,string> $parts
     */
    private function parseBudget(array $parts, int $index): int
    {
        if (!isset($parts[$index])) {
            return 0;
        }

        return max(0, (int) $parts[$index]);
    }

    private function writeFrame($client, string $frame): void
    {
        fwrite($client, $frame . "\n");
        fflush($client);
    }

    private function isLoopbackPeer($client): bool
    {
        $peerName = stream_socket_get_name($client, true);
        if (!is_string($peerName) || $peerName === '') {
            return false;
        }

        $host = $this->hostFromSocketName($peerName);
        if ($host === '') {
            return false;
        }

        return $this->isLoopbackHost($host);
    }

    private function hostFromSocketName(string $socketName): string
    {
        if (preg_match('/^\[(.*)\]:(\d+)$/', $socketName, $matches) === 1) {
            return (string) $matches[1];
        }

        $separatorPosition = strrpos($socketName, ':');
        if ($separatorPosition === false) {
            return trim($socketName, '[]');
        }

        return trim(substr($socketName, 0, $separatorPosition), '[]');
    }

    private function isLoopbackHost(string $host): bool
    {
        $normalized = strtolower(trim($host));
        if ($normalized === '') {
            return false;
        }

        $zoneSeparator = strpos($normalized, '%');
        if ($zoneSeparator !== false) {
            $normalized = substr($normalized, 0, $zoneSeparator);
        }

        if ($normalized === 'localhost' || $normalized === '::1') {
            return true;
        }

        if (str_starts_with($normalized, '127.') || str_starts_with($normalized, '::ffff:127.')) {
            return true;
        }

        return false;
    }

    private function endpointFor(string $host, int $port): string
    {
        $target = $host;

        if ($target !== '' && str_contains($target, ':') && !preg_match('/^\[.*\]$/', $target)) {
            $target = '[' . $target . ']';
        }

        return 'tcp://' . $target . ':' . $port;
    }

    private function hydrateBoundAddress(): void
    {
        if (!is_resource($this->server)) {
            return;
        }

        $localName = stream_socket_get_name($this->server, false);
        if (!is_string($localName) || $localName === '') {
            return;
        }

        if (preg_match('/^\[(.*)\]:(\d+)$/', $localName, $matches) === 1) {
            $this->host = (string) $matches[1];
            $this->boundPort = (int) $matches[2];
            return;
        }

        $separatorPosition = strrpos($localName, ':');
        if ($separatorPosition === false) {
            return;
        }

        $host = trim(substr($localName, 0, $separatorPosition), '[]');
        $port = substr($localName, $separatorPosition + 1);

        if ($host !== '') {
            $this->host = $host;
        }

        if ($port !== '') {
            $this->boundPort = (int) $port;
        }
    }
}
