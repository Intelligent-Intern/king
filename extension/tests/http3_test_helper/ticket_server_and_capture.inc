<?php

function king_http3_ticket_server_binary(): string
{
    $root = dirname(dirname(KING_HTTP3_TESTS_DIR));
    $manifest = KING_HTTP3_TESTS_DIR . '/http3_ticket_server/Cargo.toml';
    $source = KING_HTTP3_TESTS_DIR . '/http3_ticket_server/src/main.rs';
    $lock = KING_HTTP3_TESTS_DIR . '/http3_ticket_server/Cargo.lock';
    $targetDir = $root . '/quiche/target';
    $binary = $targetDir . '/release/king-http3-ticket-server';

    if (trim((string) shell_exec('command -v cargo')) === '') {
        throw new RuntimeException('cargo is required to build the HTTP/3 ticket server helper');
    }

    if (
        is_file($binary)
        && filemtime($binary) >= filemtime($source)
        && filemtime($binary) >= filemtime($manifest)
        && (!is_file($lock) || filemtime($binary) >= filemtime($lock))
    ) {
        return $binary;
    }

    $command = sprintf(
        'env CARGO_TARGET_DIR=%s cargo build --manifest-path %s --release --locked 2>&1',
        escapeshellarg($targetDir),
        escapeshellarg($manifest)
    );

    exec($command, $output, $exitCode);
    if ($exitCode !== 0 || !is_file($binary)) {
        throw new RuntimeException(
            "failed to build HTTP/3 ticket server helper:\n" . implode("\n", $output)
        );
    }

    return $binary;
}

function king_http3_start_ticket_test_server(
    string $certFile,
    string $keyFile,
    string $rootDir,
    string $bindHost = 'localhost',
    ?int $port = null,
    ?string $urlHost = null,
    bool $enableEarlyData = false,
    int $dropEstablishedDatagramCount = 0
): array {
    $envBindHost = getenv('KING_HTTP3_TEST_BIND_HOST');
    if ($bindHost === 'localhost' && is_string($envBindHost) && $envBindHost !== '') {
        $bindHost = $envBindHost;
    }

    if ($port === null) {
        $probe = stream_socket_server('udp://' . $bindHost . ':0', $errno, $errstr, STREAM_SERVER_BIND);
        if ($probe === false) {
            throw new RuntimeException("failed to reserve UDP test port: $errstr");
        }

        $serverName = stream_socket_get_name($probe, false);
        fclose($probe);
        [, $port] = king_http3_parse_socket_name($serverName);
    }

    $binary = king_http3_ticket_server_binary();
    $envUrlHost = getenv('KING_HTTP3_TEST_URL_HOST');
    if ($urlHost === null && is_string($envUrlHost) && $envUrlHost !== '') {
        $urlHost = $envUrlHost;
    }
    $urlHost ??= $bindHost;
    $command = [
        $binary,
        $certFile,
        $keyFile,
        $rootDir,
        $bindHost,
        (string) $port,
        $enableEarlyData ? '1' : '0',
        (string) max(0, $dropEstablishedDatagramCount),
    ];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch local HTTP/3 ticket test server');
    }

    $ready = fgets($pipes[1]);
    if (!is_string($ready) || preg_match('/^READY (\d+)$/', trim($ready), $matches) !== 1) {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        throw new RuntimeException('local HTTP/3 ticket test server failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'port' => (int) $matches[1],
        'host' => $urlHost,
        'bind_host' => $bindHost,
    ];
}

function king_http3_test_server_url(array $server, string $path): string
{
    $host = $server['host'] ?? '127.0.0.1';
    return 'https://' . $host . ':' . $server['port'] . $path;
}

function king_http3_stop_test_server(array $server): void
{
    $process = $server['process'];
    $pipes = $server['pipes'];
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
}

function king_http3_stop_ticket_test_server(array $server): array
{
    return king_http3_finalize_failure_peer($server);
}

function king_http3_ticket_server_lifecycle(array $capture): array
{
    $stdout = (string) ($capture['stdout'] ?? '');

    if (preg_match('/^LIFECYCLE (\{.+\})$/m', $stdout, $matches) !== 1) {
        throw new RuntimeException('HTTP/3 ticket test server did not emit a lifecycle capture.');
    }

    $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('HTTP/3 ticket test server lifecycle capture was not an object.');
    }

    return $decoded;
}

function king_http3_failure_peer_close_capture(array $capture): array
{
    $stdout = (string) ($capture['stdout'] ?? '');

    if (preg_match('/^CLOSE (\{.+\})$/m', $stdout, $matches) !== 1) {
        throw new RuntimeException('HTTP/3 failure peer did not emit a close capture.');
    }

    $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('HTTP/3 failure peer close capture was not an object.');
    }

    return $decoded;
}
