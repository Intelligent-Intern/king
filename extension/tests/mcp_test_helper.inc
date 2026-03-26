<?php

function king_mcp_test_start_server_script(string $scriptPath, ?int $port = null): array
{
    if ($port === null) {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            throw new RuntimeException("failed to reserve MCP test port: $errstr");
        }

        $serverName = stream_socket_get_name($probe, false);
        fclose($probe);
        [, $port] = explode(':', $serverName, 2);
    }

    $capture = tempnam(sys_get_temp_dir(), 'king-mcp-capture-');
    $command = [
        PHP_BINARY,
        '-n',
        $scriptPath,
        $capture,
        (string) ((int) $port),
    ];

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
    ];
}

function king_mcp_test_start_server(?int $port = null): array
{
    return king_mcp_test_start_server_script(__DIR__ . '/mcp_test_server.inc', $port);
}

function king_mcp_test_start_parallel_server(?int $port = null): array
{
    return king_mcp_test_start_server_script(__DIR__ . '/mcp_parallel_test_server.inc', $port);
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
        'tcp://127.0.0.1:' . $server['port'],
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
