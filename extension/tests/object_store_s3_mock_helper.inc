<?php

function king_object_store_s3_mock_format_endpoint(string $host, int $port): string
{
    if (strpos($host, ':') !== false && !preg_match('/^\[.*\]$/', $host)) {
        return 'tcp://[' . $host . ']:' . $port;
    }

    return 'tcp://' . $host . ':' . $port;
}

function king_object_store_s3_mock_start_server(
    ?string $stateDirectory = null,
    string $bindHost = '127.0.0.1',
    array $options = []
): array {
    $probe = stream_socket_server(
        king_object_store_s3_mock_format_endpoint($bindHost, 0),
        $errno,
        $errstr
    );
    if ($probe === false) {
        throw new RuntimeException("failed to reserve object-store S3 mock port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    if (preg_match('/^\[.*\]:(\d+)$/', $serverName, $matches) === 1) {
        $port = (int) $matches[1];
    } elseif (preg_match('/^.*:(\d+)$/', $serverName, $matches) === 1) {
        $port = (int) $matches[1];
    } else {
        throw new RuntimeException('failed to parse object-store S3 mock port');
    }

    if ($stateDirectory === null) {
        $stateDirectory = sys_get_temp_dir() . '/king-object-store-s3-mock-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }
    if (!is_dir($stateDirectory) && !mkdir($stateDirectory, 0700, true) && !is_dir($stateDirectory)) {
        throw new RuntimeException('failed to create object-store S3 mock state directory');
    }

    $capture = tempnam(sys_get_temp_dir(), 'king-object-store-s3-mock-capture-');
    $command = [
        PHP_BINARY,
        '-n',
        __DIR__ . '/object_store_s3_mock_server.inc',
        $capture,
        $stateDirectory,
        (string) $port,
        $bindHost,
        json_encode($options, JSON_UNESCAPED_SLASHES),
    ];

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch object-store S3 mock server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('object-store S3 mock server failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => $port,
        'host' => $bindHost,
        'state_directory' => $stateDirectory,
        'endpoint' => 'http://' . $bindHost . ':' . $port,
    ];
}

function king_object_store_s3_mock_stop_server(array $server): array
{
    $stop = @stream_socket_client(
        king_object_store_s3_mock_format_endpoint(
            (string) ($server['host'] ?? '127.0.0.1'),
            (int) ($server['port'] ?? 0)
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

    $stdout = isset($server['pipes'][1]) ? stream_get_contents($server['pipes'][1]) : '';
    $stderr = isset($server['pipes'][2]) ? stream_get_contents($server['pipes'][2]) : '';

    foreach ($server['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    $exitCode = proc_close($server['process']);
    $capture = [];
    if (isset($server['capture']) && is_file($server['capture'])) {
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

function king_object_store_s3_mock_cleanup_state_directory(string $stateDirectory): void
{
    if ($stateDirectory === '' || !is_dir($stateDirectory)) {
        return;
    }

    $entries = scandir($stateDirectory);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $stateDirectory . '/' . $entry;
        if (is_dir($path)) {
            king_object_store_s3_mock_cleanup_state_directory($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($stateDirectory);
}
