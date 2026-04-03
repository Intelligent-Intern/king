<?php

/* Shared helper for the bounded local CDN HTTP-origin harness. */

function king_cdn_origin_http_test_format_endpoint(string $host, int $port): string
{
    if (strpos($host, ':') !== false && !preg_match('/^\[.*\]$/', $host)) {
        return 'tcp://[' . $host . ']:' . $port;
    }

    return 'tcp://' . $host . ':' . $port;
}

function king_cdn_origin_http_test_reserve_unused_port(string $bindHost = '127.0.0.1'): int
{
    $probe = stream_socket_server(
        king_cdn_origin_http_test_format_endpoint($bindHost, 0),
        $errno,
        $errstr
    );
    if ($probe === false) {
        throw new RuntimeException("failed to reserve CDN origin test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);

    if (preg_match('/^\[.*\]:(\d+)$/', $serverName, $matches) === 1) {
        return (int) $matches[1];
    }

    if (preg_match('/^.*:(\d+)$/', $serverName, $matches) === 1) {
        return (int) $matches[1];
    }

    throw new RuntimeException('failed to parse CDN origin test port');
}

function king_cdn_origin_http_test_start_server(
    array $plan,
    string $bindHost = '127.0.0.1'
): array {
    $port = king_cdn_origin_http_test_reserve_unused_port($bindHost);
    $capture = tempnam(sys_get_temp_dir(), 'king-cdn-origin-capture-');
    $planPath = tempnam(sys_get_temp_dir(), 'king-cdn-origin-plan-');
    file_put_contents($planPath, json_encode($plan, JSON_UNESCAPED_SLASHES));

    $command = [
        PHP_BINARY,
        '-n',
        __DIR__ . '/cdn_origin_http_server.inc',
        $capture,
        $planPath,
        (string) $port,
        $bindHost,
    ];

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        @unlink($planPath);
        throw new RuntimeException('failed to launch CDN origin test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        @unlink($planPath);
        throw new RuntimeException('CDN origin test server failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'plan_path' => $planPath,
        'port' => $port,
        'host' => $bindHost,
        'endpoint' => 'http://' . $bindHost . ':' . $port,
    ];
}

function king_cdn_origin_http_test_stop_server(array $server): array
{
    $stop = @stream_socket_client(
        king_cdn_origin_http_test_format_endpoint(
            (string) ($server['host'] ?? '127.0.0.1'),
            (int) ($server['port'] ?? 0)
        ),
        $errno,
        $errstr,
        0.5
    );
    if (is_resource($stop)) {
        fwrite($stop, "STOP / HTTP/1.1\r\n\r\n");
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

    if (isset($server['plan_path']) && is_file($server['plan_path'])) {
        @unlink($server['plan_path']);
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
