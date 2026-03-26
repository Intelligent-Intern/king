<?php

function king_orchestrator_test_format_tcp_endpoint(string $host, int $port): string
{
    if (strpos($host, ':') !== false && !preg_match('/^\[.*\]$/', $host)) {
        return 'tcp://[' . $host . ']:' . $port;
    }

    return 'tcp://' . $host . ':' . $port;
}

function king_orchestrator_remote_peer_start(
    ?int $port = null,
    string $bindHost = '127.0.0.1'
): array {
    if ($port === null) {
        $probe = stream_socket_server(
            king_orchestrator_test_format_tcp_endpoint($bindHost, 0),
            $errno,
            $errstr
        );
        if ($probe === false) {
            throw new RuntimeException("failed to reserve orchestrator remote-peer port: $errstr");
        }

        $serverName = stream_socket_get_name($probe, false);
        fclose($probe);
        if (preg_match('/^\[.*\]:(\d+)$/', $serverName, $matches) === 1) {
            $port = (int) $matches[1];
        } elseif (preg_match('/^.*:(\d+)$/', $serverName, $matches) === 1) {
            $port = (int) $matches[1];
        } else {
            throw new RuntimeException('failed to parse orchestrator remote-peer port');
        }
    }

    $capture = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-peer-capture-');
    $command = [
        PHP_BINARY,
        '-n',
        __DIR__ . '/orchestrator_remote_peer_server.inc',
        $capture,
        (string) $port,
        $bindHost,
    ];

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

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => (int) $port,
        'host' => $bindHost,
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
