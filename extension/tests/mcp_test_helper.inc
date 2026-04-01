<?php

function king_mcp_test_format_tcp_endpoint(string $host, int $port): string
{
    if (strpos($host, ':') !== false && !preg_match('/^\[.*\]$/', $host)) {
        return 'tcp://[' . $host . ']:' . $port;
    }

    return 'tcp://' . $host . ':' . $port;
}

function king_mcp_test_start_server_script(
    string $scriptPath,
    ?int $port = null,
    array $extraArgs = [],
    string $bindHost = '127.0.0.1'
): array
{
    if ($port === null) {
        $probe = stream_socket_server(
            king_mcp_test_format_tcp_endpoint($bindHost, 0),
            $errno,
            $errstr
        );
        if ($probe === false) {
            throw new RuntimeException("failed to reserve MCP test port: $errstr");
        }

        $serverName = stream_socket_get_name($probe, false);
        fclose($probe);
        if (preg_match('/^\[.*\]:(\d+)$/', $serverName, $matches) === 1) {
            $port = (int) $matches[1];
        } elseif (preg_match('/^.*:(\d+)$/', $serverName, $matches) === 1) {
            $port = (int) $matches[1];
        } else {
            throw new RuntimeException('failed to parse MCP test port from reserved socket name');
        }
    }

    $capture = tempnam(sys_get_temp_dir(), 'king-mcp-capture-');
    $command = [
        PHP_BINARY,
        '-n',
        $scriptPath,
        $capture,
        (string) ((int) $port),
        $bindHost,
    ];
    foreach ($extraArgs as $arg) {
        $command[] = (string) $arg;
    }

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch local MCP test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('local MCP test server failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => (int) $port,
        'host' => $bindHost,
    ];
}

function king_mcp_test_start_server(?int $port = null, string $bindHost = '127.0.0.1'): array
{
    return king_mcp_test_start_server_script(__DIR__ . '/mcp_test_server.inc', $port, [], $bindHost);
}

function king_mcp_test_start_parallel_server(?int $port = null, string $bindHost = '127.0.0.1'): array
{
    return king_mcp_test_start_server_script(__DIR__ . '/mcp_parallel_test_server.inc', $port, [], $bindHost);
}

function king_mcp_test_start_persisted_server(
    string $statePath,
    ?int $port = null,
    string $bindHost = '127.0.0.1'
): array
{
    return king_mcp_test_start_server_script(
        __DIR__ . '/mcp_test_server.inc',
        $port,
        [$statePath],
        $bindHost
    );
}

function king_mcp_test_finalize_server(array $server): array
{
    if (isset($server['pipes'][1]) && is_resource($server['pipes'][1])) {
        stream_set_blocking($server['pipes'][1], false);
    }
    if (isset($server['pipes'][2]) && is_resource($server['pipes'][2])) {
        stream_set_blocking($server['pipes'][2], false);
    }

    $stdout = isset($server['pipes'][1]) ? stream_get_contents($server['pipes'][1]) : '';
    $stderr = isset($server['pipes'][2]) ? stream_get_contents($server['pipes'][2]) : '';

    foreach ($server['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    $exitCode = proc_close($server['process']);
    $capture = [];
    if (is_file($server['capture'])) {
        $capture = json_decode((string) file_get_contents($server['capture']), true);
        if (!is_array($capture)) {
            $capture = [];
        }
        @unlink($server['capture']);
    }

    if ($capture === [] && ($stdout !== '' || $stderr !== '' || $exitCode !== 0)) {
        $capture = [
            '__stdout' => $stdout,
            '__stderr' => $stderr,
            '__exit_code' => $exitCode,
        ];
    }

    return $capture;
}

function king_mcp_test_stop_server(array $server): array
{
    $stop = @stream_socket_client(
        king_mcp_test_format_tcp_endpoint(
            (string) ($server['host'] ?? '127.0.0.1'),
            (int) $server['port']
        ),
        $errno,
        $errstr,
        0.5
    );
    if (is_resource($stop)) {
        fwrite($stop, "STOP\n");
        fflush($stop);
        fclose($stop);
    }

    return king_mcp_test_finalize_server($server);
}

function king_mcp_test_crash_server(array $server, int $signal = 9): array
{
    if (is_resource($server['process'])) {
        proc_terminate($server['process'], $signal);
    }

    $deadline = microtime(true) + 2.0;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($server['process']);
        if (!is_array($status) || !$status['running']) {
            break;
        }
        usleep(10000);
    }

    return king_mcp_test_finalize_server($server);
}

function king_mcp_schedule_cancel_signal(
    King\CancelToken $token,
    int $delayUs = 100000,
    int $signal = SIGUSR1
): int {
    pcntl_async_signals(true);
    pcntl_signal($signal, static function () use ($token): void {
        $token->cancel();
    });

    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('failed to fork MCP cancel helper');
    }

    if ($pid === 0) {
        usleep($delayUs);
        posix_kill(posix_getppid(), $signal);
        exit(0);
    }

    return $pid;
}

function king_mcp_wait_cancel_signal(int $pid, int $signal = SIGUSR1): void
{
    pcntl_waitpid($pid, $status);
    pcntl_signal($signal, SIG_DFL);
}

function king_mcp_test_detect_non_loopback_ipv4(): string
{
    $output = trim((string) shell_exec('ip -4 -o addr show up scope global 2>/dev/null'));

    if ($output === '') {
        $output = trim((string) shell_exec('ip -4 -o addr show up 2>/dev/null'));
    }

    if ($output === '') {
        throw new RuntimeException('failed to detect a non-loopback IPv4 address for the MCP multi-host harness');
    }

    foreach (preg_split('/\R+/', $output) as $line) {
        if (preg_match('/\sinet\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\//', $line, $matches) !== 1) {
            continue;
        }

        if (str_starts_with($matches[1], '127.')) {
            continue;
        }

        return $matches[1];
    }

    throw new RuntimeException('failed to find a usable non-loopback IPv4 address for the MCP multi-host harness');
}

function king_mcp_test_run_namespaced_client_script(
    string $scriptPath,
    int $port,
    array $extraArgs = []
): array
{
    $workDir = sys_get_temp_dir() . '/king-mcp-netns-' . bin2hex(random_bytes(8));
    $outboundAddr = king_mcp_test_detect_non_loopback_ipv4();
    $gatewayHost = $outboundAddr;
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $ldLibraryPath = getenv('LD_LIBRARY_PATH');
    $namespaceProcess = null;
    $slirpProcess = null;
    $namespacePipes = [];
    $slirpPipes = [];
    $capture = [];

    if (!mkdir($workDir, 0700, true) && !is_dir($workDir)) {
        throw new RuntimeException('failed to create MCP network-namespace harness workspace');
    }

    $namespaceScript = <<<'BASH'
set -e
workdir="$1"
gateway_host="$2"
port="$3"
script_path="$4"
ld_library_path="$5"
shift 5

echo $$ > "$workdir/pid"
ip link set lo up

while [ ! -f "$workdir/go" ]; do
    sleep 0.05
done

if [ -n "$ld_library_path" ]; then
    export LD_LIBRARY_PATH="$ld_library_path"
fi

set +e
"PHP_BINARY_PLACEHOLDER" -n \
    -d "extension=EXTENSION_PLACEHOLDER" \
    -d "king.security_allow_config_override=1" \
    -d "king.mcp_allowed_peer_hosts=$gateway_host" \
    "$script_path" \
    "$workdir/capture.json" \
    "$gateway_host" \
    "$port" \
    "$@" > "$workdir/stdout" 2> "$workDir/stderr"
status=$?
set -e

printf '%s' "$status" > "$workdir/exit.status"
exit "$status"
BASH;

    $namespaceScript = str_replace(
        ['PHP_BINARY_PLACEHOLDER', 'EXTENSION_PLACEHOLDER', '$workDir/stderr'],
        [PHP_BINARY, $extensionPath, '$workdir/stderr'],
        $namespaceScript
    );

    $namespaceCommand = array_merge(
        [
            'unshare',
            '-Urn',
            'bash',
            '-lc',
            $namespaceScript,
            '_',
            $workDir,
            $gatewayHost,
            (string) $port,
            $scriptPath,
            is_string($ldLibraryPath) ? $ldLibraryPath : '',
        ],
        array_map(static fn ($arg): string => (string) $arg, $extraArgs)
    );

    try {
        $namespaceProcess = proc_open(
            $namespaceCommand,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $namespacePipes
        );
        if (!is_resource($namespaceProcess)) {
            throw new RuntimeException('failed to launch the MCP network-namespace client process');
        }

        $pidPath = $workDir . '/pid';
        $deadline = microtime(true) + 5.0;
        while (!is_file($pidPath) && microtime(true) < $deadline) {
            usleep(50000);
        }

        if (!is_file($pidPath)) {
            throw new RuntimeException('MCP network-namespace client did not publish its pid');
        }

        $namespacePid = trim((string) file_get_contents($pidPath));
        if ($namespacePid === '' || preg_match('/^\d+$/', $namespacePid) !== 1) {
            throw new RuntimeException('MCP network-namespace client published an invalid pid');
        }

        $slirpProcess = proc_open(
            [
                'slirp4netns',
                '--configure',
                '--disable-host-loopback',
                '--outbound-addr=' . $outboundAddr,
                $namespacePid,
                'tap0',
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $slirpPipes
        );
        if (!is_resource($slirpProcess)) {
            throw new RuntimeException('failed to launch slirp4netns for the MCP network-namespace client');
        }

        usleep(1000000);
        touch($workDir . '/go');

        $exitPath = $workDir . '/exit.status';
        $deadline = microtime(true) + 20.0;
        while (!is_file($exitPath) && microtime(true) < $deadline) {
            usleep(50000);
        }

        if (!is_file($exitPath)) {
            throw new RuntimeException('MCP network-namespace client did not finish within the timeout budget');
        }

        $capture = is_file($workDir . '/capture.json')
            ? json_decode((string) file_get_contents($workDir . '/capture.json'), true)
            : [];
        if (!is_array($capture)) {
            $capture = [];
        }

        $capture['__exit_code'] = (int) trim((string) file_get_contents($exitPath));
        $capture['__stdout'] = is_file($workDir . '/stdout')
            ? (string) file_get_contents($workDir . '/stdout')
            : '';
        $capture['__stderr'] = is_file($workDir . '/stderr')
            ? (string) file_get_contents($workDir . '/stderr')
            : '';
        $capture['gateway_host'] = $gatewayHost;
        $capture['outbound_addr'] = $outboundAddr;
    } finally {
        if (isset($slirpPipes[1]) && is_resource($slirpPipes[1])) {
            fclose($slirpPipes[1]);
        }
        if (isset($slirpPipes[2]) && is_resource($slirpPipes[2])) {
            fclose($slirpPipes[2]);
        }
        if (is_resource($slirpProcess)) {
            proc_terminate($slirpProcess);
            proc_close($slirpProcess);
        }

        if (isset($namespacePipes[1]) && is_resource($namespacePipes[1])) {
            fclose($namespacePipes[1]);
        }
        if (isset($namespacePipes[2]) && is_resource($namespacePipes[2])) {
            fclose($namespacePipes[2]);
        }
        if (is_resource($namespaceProcess)) {
            proc_terminate($namespaceProcess);
            proc_close($namespaceProcess);
        }

        @unlink($workDir . '/capture.json');
        @unlink($workDir . '/stdout');
        @unlink($workDir . '/stderr');
        @unlink($workDir . '/exit.status');
        @unlink($workDir . '/go');
        @unlink($workDir . '/pid');
        @rmdir($workDir);
    }

    return $capture;
}
