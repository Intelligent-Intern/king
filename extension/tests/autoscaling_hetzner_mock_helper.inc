<?php

/* Test helper for spawning the local Hetzner mock API used by autoscaling contracts. */

function king_autoscaling_test_format_tcp_endpoint(string $host, int $port): string
{
    if (strpos($host, ':') !== false && !preg_match('/^\[.*\]$/', $host)) {
        return 'tcp://[' . $host . ']:' . $port;
    }

    return 'tcp://' . $host . ':' . $port;
}

function king_autoscaling_hetzner_mock_start(array $options = []): array
{
    $bindHost = $options['host'] ?? '127.0.0.1';
    $deleteStatus = $options['delete_status'] ?? 204;
    $createStatus = (int) ($options['create_status'] ?? 201);
    $listStatus = (int) ($options['list_status'] ?? 200);
    $startId = (int) ($options['start_id'] ?? 9000);
    $logFile = $options['log_file'] ?? tempnam(sys_get_temp_dir(), 'king-hetzner-log-');
    $stateFile = $options['state_file'] ?? tempnam(sys_get_temp_dir(), 'king-hetzner-state-');
    $initialServers = $options['initial_servers'] ?? [];
    $nextId = $startId;

    foreach ($initialServers as $server) {
        if (is_array($server) && isset($server['id']) && (int) $server['id'] >= $nextId) {
            $nextId = (int) $server['id'] + 1;
        }
    }

    file_put_contents($stateFile, json_encode([
        'next_id' => $nextId,
        'servers' => array_values($initialServers),
    ], JSON_THROW_ON_ERROR));

    $probe = stream_socket_server(
        king_autoscaling_test_format_tcp_endpoint($bindHost, 0),
        $errno,
        $errstr
    );
    if ($probe === false) {
        throw new RuntimeException("failed to reserve mock Hetzner port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    if (preg_match('/^\[.*\]:(\d+)$/', $serverName, $matches) === 1) {
        $port = (int) $matches[1];
    } elseif (preg_match('/^.*:(\d+)$/', $serverName, $matches) === 1) {
        $port = (int) $matches[1];
    } else {
        throw new RuntimeException('failed to parse mock Hetzner port');
    }

    $command = [
        PHP_BINARY,
        '-n',
        __DIR__ . '/autoscaling_hetzner_mock_server.inc',
        $logFile,
        (string) $port,
        (string) (
            is_array($deleteStatus)
                ? implode(
                    ',',
                    array_map(
                        static fn($entry): int => (int) $entry,
                        $deleteStatus
                    )
                )
                : (string) (int) $deleteStatus
        ),
        (string) $createStatus,
        (string) $startId,
        $bindHost,
        (string) $listStatus,
        $stateFile,
    ];

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch mock Hetzner API server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        throw new RuntimeException('mock Hetzner API failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'port' => $port,
        'host' => $bindHost,
        'log_file' => $logFile,
        'state_file' => $stateFile,
    ];
}

function king_autoscaling_hetzner_mock_stop(array $server): void
{
    $stop = @stream_socket_client(
        king_autoscaling_test_format_tcp_endpoint(
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

    foreach ($server['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($server['process']);
}

function king_autoscaling_hetzner_mock_requests(string $logFile): array
{
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return array_map(
        static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_values(array_filter($lines))
    );
}
