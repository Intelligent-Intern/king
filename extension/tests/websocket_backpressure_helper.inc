<?php

/* Test helper for starting the concurrent WebSocket backpressure harness server. */

function king_websocket_backpressure_start_server(
    int $burstCount = 12,
    int $payloadBytes = 128,
    int $maxConnections = 12
): array {
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve WebSocket backpressure test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $capture = tempnam(sys_get_temp_dir(), 'king-websocket-backpressure-');
    $command = escapeshellarg(PHP_BINARY)
        . ' -n '
        . escapeshellarg(__DIR__ . '/websocket_backpressure_server.inc')
        . ' '
        . escapeshellarg($capture)
        . ' '
        . (int) $port
        . ' '
        . max(1, $burstCount)
        . ' '
        . max(16, $payloadBytes)
        . ' '
        . max(1, $maxConnections);

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch WebSocket backpressure test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('WebSocket backpressure test server failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => (int) $port,
    ];
}

function king_websocket_backpressure_stop_server(array $server): array
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

function king_websocket_backpressure_connect_retry(string $url, ?array $options = null, int $timeoutMs = 3000)
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    do {
        try {
            return new King\WebSocket\Connection($url, null, $options);
        } catch (Throwable $e) {
            usleep(50000);
        }
    } while (microtime(true) < $deadline);

    throw new RuntimeException('failed to connect to WebSocket backpressure server: ' . king_get_last_error());
}
