<?php

function king_http3_start_failure_peer(
    string $mode,
    string $certFile,
    string $keyFile,
    string $bindHost = 'localhost',
    ?string $urlHost = null
): array {
    $envBindHost = getenv('KING_HTTP3_TEST_BIND_HOST');
    if ($bindHost === 'localhost' && is_string($envBindHost) && $envBindHost !== '') {
        $bindHost = $envBindHost;
    }

    $binary = king_http3_failure_peer_binary();
    $envUrlHost = getenv('KING_HTTP3_TEST_URL_HOST');
    if ($urlHost === null && is_string($envUrlHost) && $envUrlHost !== '') {
        $urlHost = $envUrlHost;
    }
    $urlHost ??= $bindHost;
    $command = [$binary, $mode, $certFile, $keyFile, $bindHost];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch local HTTP/3 failure peer');
    }

    $ready = fgets($pipes[1]);
    if (!is_string($ready) || preg_match('/^READY (\d+)$/', trim($ready), $matches) !== 1) {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        throw new RuntimeException('local HTTP/3 failure peer failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'port' => (int) $matches[1],
        'host' => $urlHost,
        'bind_host' => $bindHost,
        'mode' => $mode,
    ];
}

function king_http3_start_multi_peer(
    string $certFile,
    string $keyFile,
    string $bindHost = 'localhost',
    ?string $urlHost = null,
    int $expectedRequests = 3
): array {
    $envBindHost = getenv('KING_HTTP3_TEST_BIND_HOST');
    if ($bindHost === 'localhost' && is_string($envBindHost) && $envBindHost !== '') {
        $bindHost = $envBindHost;
    }

    $binary = king_http3_multi_peer_binary();
    $envUrlHost = getenv('KING_HTTP3_TEST_URL_HOST');
    if ($urlHost === null && is_string($envUrlHost) && $envUrlHost !== '') {
        $urlHost = $envUrlHost;
    }
    $urlHost ??= $bindHost;
    $command = [$binary, $certFile, $keyFile, $bindHost, (string) $expectedRequests];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch local HTTP/3 multi peer');
    }

    $ready = fgets($pipes[1]);
    if (!is_string($ready) || preg_match('/^READY (\d+)$/', trim($ready), $matches) !== 1) {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        throw new RuntimeException('local HTTP/3 multi peer failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'port' => (int) $matches[1],
        'host' => $urlHost,
        'bind_host' => $bindHost,
        'expected_requests' => $expectedRequests,
    ];
}

function king_http3_finalize_failure_peer(array $peer): array
{
    $deadline = microtime(true) + 2.0;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($peer['process']);
        if (!is_array($status) || !$status['running']) {
            break;
        }
        usleep(10000);
    }

    $status = proc_get_status($peer['process']);
    if (is_array($status) && $status['running']) {
        @proc_terminate($peer['process']);
    }

    if (isset($peer['pipes'][1]) && is_resource($peer['pipes'][1])) {
        stream_set_blocking($peer['pipes'][1], false);
    }
    if (isset($peer['pipes'][2]) && is_resource($peer['pipes'][2])) {
        stream_set_blocking($peer['pipes'][2], false);
    }

    $stdout = isset($peer['pipes'][1]) ? stream_get_contents($peer['pipes'][1]) : '';
    $stderr = isset($peer['pipes'][2]) ? stream_get_contents($peer['pipes'][2]) : '';

    foreach ($peer['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    $exitCode = proc_close($peer['process']);

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit_code' => $exitCode,
    ];
}

function king_http3_stop_failure_peer(array $peer): array
{
    return king_http3_finalize_failure_peer($peer);
}
