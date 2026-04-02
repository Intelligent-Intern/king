<?php

/* Test helper for starting the local Smart-DNS live-probe harness server. */

function king_semantic_dns_live_probe_pick_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve Semantic-DNS live probe port: $errstr");
    }

    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $address, 2);

    return (int) $port;
}

function king_semantic_dns_live_probe_write_state(string $path, array $state): void
{
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
}

function king_semantic_dns_live_probe_start_server(string $statePath, ?int $port = null): array
{
    $port ??= king_semantic_dns_live_probe_pick_port();
    $capture = tempnam(sys_get_temp_dir(), 'king-semantic-dns-live-probe-capture-');

    $command = [
        PHP_BINARY,
        '-n',
        __DIR__ . '/semantic_dns_live_probe_server.inc',
        $capture,
        $statePath,
        (string) $port,
    ];

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch Semantic-DNS live probe server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('Semantic-DNS live probe server failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'state' => $statePath,
        'port' => $port,
    ];
}

function king_semantic_dns_live_probe_stop_server(array $server): array
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

    @unlink($server['state']);

    if ($capture === [] && ($stdout !== '' || $stderr !== '' || $exitCode !== 0)) {
        $capture = [
            '__stdout' => $stdout,
            '__stderr' => $stderr,
            '__exit_code' => $exitCode,
        ];
    }

    return $capture;
}
