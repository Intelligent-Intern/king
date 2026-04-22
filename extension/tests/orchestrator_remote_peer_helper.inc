<?php

/* Shared helper utilities for orchestrator remote-peer harness processes. */

function king_orchestrator_test_format_tcp_endpoint(string $host, int $port): string
{
    if (strpos($host, ':') !== false && !preg_match('/^\[.*\]$/', $host)) {
        return 'tcp://[' . $host . ']:' . $port;
    }

    return 'tcp://' . $host . ':' . $port;
}

function king_orchestrator_remote_peer_wait_until_ready(string $host, int $port, int $timeoutMs = 3000): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000.0);
    $lastError = 'connection probe failed';
    $lastErrno = 0;

    while (microtime(true) < $deadline) {
        $probe = @stream_socket_client(
            king_orchestrator_test_format_tcp_endpoint($host, $port),
            $lastErrno,
            $lastError,
            0.2
        );
        if (is_resource($probe)) {
            fclose($probe);
            return;
        }

        usleep(50000);
    }

    throw new RuntimeException(
        sprintf(
            'orchestrator remote peer listener did not become reachable at %s:%d (last error: %s [%d])',
            $host,
            $port,
            $lastError,
            $lastErrno
        )
    );
}

function king_orchestrator_remote_peer_start(
    ?int $port = null,
    string $bindHost = '127.0.0.1',
    ?string $serverScript = null,
    array $extraArgs = []
): array {
    if ($port === null) {
        $lastError = 'unknown startup error';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return king_orchestrator_remote_peer_start(
                    random_int(40000, 49999),
                    $bindHost,
                    $serverScript,
                    $extraArgs
                );
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException('failed to launch orchestrator remote peer server after retries: ' . $lastError);
    }

    $capture = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-peer-capture-');
    $serverScript ??= __DIR__ . '/orchestrator_remote_peer_server.inc';
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $command = [
        PHP_BINARY,
        '-n',
        '-d', 'extension=' . $extensionPath,
        $serverScript,
        $capture,
        (string) $port,
        $bindHost,
    ];
    foreach ($extraArgs as $extraArg) {
        $command[] = (string) $extraArg;
    }

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch orchestrator remote peer server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('orchestrator remote peer server failed: ' . trim($stderr));
    }

    king_orchestrator_remote_peer_wait_until_ready($bindHost, (int) $port);

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => (int) $port,
        'host' => $bindHost,
        'server_script' => $serverScript,
    ];
}

function king_orchestrator_remote_peer_finalize(array $server): array
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

function king_orchestrator_remote_peer_stop(array $server): array
{
    $stop = @stream_socket_client(
        king_orchestrator_test_format_tcp_endpoint(
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

    return king_orchestrator_remote_peer_finalize($server);
}

function king_orchestrator_remote_peer_crash(array $server, int $signal = 9): array
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

    return king_orchestrator_remote_peer_finalize($server);
}
