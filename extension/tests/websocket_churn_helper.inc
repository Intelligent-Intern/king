<?php

function king_websocket_churn_start_server(int $maxConnections = 4): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve WebSocket churn test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $capture = tempnam(sys_get_temp_dir(), 'king-websocket-churn-');
    $command = escapeshellarg(PHP_BINARY)
        . ' -n '
        . escapeshellarg(__DIR__ . '/websocket_churn_server.inc')
        . ' '
        . escapeshellarg($capture)
        . ' '
        . (int) $port
        . ' '
        . max(1, $maxConnections);

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch local WebSocket churn test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('local WebSocket churn test server failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => (int) $port,
    ];
}

function king_websocket_churn_stop_server(array $server): array
{
    $stdout = isset($server['pipes'][1]) ? stream_get_contents($server['pipes'][1]) : '';
    $stderr = isset($server['pipes'][2]) ? stream_get_contents($server['pipes'][2]) : '';

    foreach ($server['pipes'] as $pipe) {
        fclose($pipe);
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

function king_websocket_churn_connect_retry(string $url, int $timeoutMs = 3000)
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    do {
        $websocket = king_client_websocket_connect($url);
        if (is_resource($websocket)) {
            return $websocket;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('failed to connect to WebSocket churn server: ' . king_get_last_error());
}

function king_websocket_churn_receive_text($websocket, int $timeoutMs = 1000)
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    do {
        $payload = king_client_websocket_receive($websocket, 100);
        if ($payload === false || $payload !== '') {
            return $payload;
        }

        usleep(20000);
    } while (microtime(true) < $deadline);

    return king_client_websocket_receive($websocket, 0);
}
