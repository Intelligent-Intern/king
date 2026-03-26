<?php

function king_server_websocket_wire_start_server(string $mode, int $iterations = 1): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve on-wire HTTP/1 test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $capture = tempnam(sys_get_temp_dir(), 'king-server-websocket-wire-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $command = sprintf(
        '%s -n -d %s -d %s %s %s %d %s %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg(__DIR__ . '/server_websocket_wire_server.inc'),
        escapeshellarg($capture),
        (int) $port,
        escapeshellarg($mode),
        max(1, $iterations)
    );

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch on-wire HTTP/1 websocket server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('on-wire HTTP/1 websocket server failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => (int) $port,
    ];
}

function king_server_websocket_wire_stop_server(array $server): array
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

function king_server_websocket_wire_connect_retry(string $url, int $timeoutMs = 3000)
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    do {
        $websocket = king_client_websocket_connect($url);
        if (is_resource($websocket)) {
            return $websocket;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('failed to connect to on-wire HTTP/1 websocket listener: ' . king_get_last_error());
}

function king_server_http1_wire_request_retry(int $port, string $request, int $timeoutMs = 3000): string
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    do {
        $client = @stream_socket_client(
            'tcp://127.0.0.1:' . $port,
            $errno,
            $errstr,
            0.25
        );
        if (is_resource($client)) {
            stream_set_timeout($client, 2);
            fwrite($client, $request);
            $response = stream_get_contents($client);
            fclose($client);
            return (string) $response;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("failed to connect to on-wire HTTP/1 listener: $errstr");
}
