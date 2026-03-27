<?php

function king_http3_parse_socket_name(string $socketName): array
{
    if (preg_match('/^\[(.+)\]:(\d+)$/', $socketName, $matches) === 1) {
        return [$matches[1], (int) $matches[2]];
    }

    $parts = explode(':', $socketName, 2);
    if (count($parts) !== 2) {
        throw new RuntimeException('failed to parse HTTP/3 socket name');
    }

    return [$parts[0], (int) $parts[1]];
}

function king_http3_create_fixture(array $files, string $prefix = 'king-http3-fixture-'): array
{
    $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(5));
    $root = $dir . '/root';

    if (!mkdir($dir, 0700) && !is_dir($dir)) {
        throw new RuntimeException('failed to create HTTP/3 fixture directory');
    }
    if (!mkdir($root, 0700) && !is_dir($root)) {
        throw new RuntimeException('failed to create HTTP/3 fixture root');
    }

    foreach ($files as $name => $contents) {
        file_put_contents($root . '/' . $name, $contents);
    }

    $cert = $dir . '/cert.pem';
    $key = $dir . '/key.pem';
    $command = sprintf(
        'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -sha256 -days 1 -subj %s -addext %s 2>/dev/null',
        escapeshellarg($key),
        escapeshellarg($cert),
        escapeshellarg('/CN=localhost'),
        escapeshellarg('subjectAltName = IP:127.0.0.1,DNS:localhost')
    );

    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('failed to create HTTP/3 fixture certificate');
    }

    return [
        'dir' => $dir,
        'root' => $root,
        'cert' => $cert,
        'key' => $key,
        'files' => array_keys($files),
    ];
}

function king_http3_destroy_fixture(array $fixture): void
{
    foreach ($fixture['files'] ?? [] as $name) {
        @unlink($fixture['root'] . '/' . $name);
    }

    @rmdir($fixture['root']);
    @unlink($fixture['cert']);
    @unlink($fixture['key']);
    @rmdir($fixture['dir']);
}

function king_http3_start_test_server(string $certFile, string $keyFile, string $rootDir): array
{
    $probe = stream_socket_server('udp://localhost:0', $errno, $errstr, STREAM_SERVER_BIND);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve UDP test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = king_http3_parse_socket_name($serverName);

    $binary = getenv('KING_QUICHE_SERVER');
    $command = 'RUST_LOG=info '
        . escapeshellarg($binary)
        . ' --listen ' . escapeshellarg($serverName)
        . ' --cert ' . escapeshellarg($certFile)
        . ' --key ' . escapeshellarg($keyFile)
        . ' --root ' . escapeshellarg($rootDir)
        . ' --http-version ' . escapeshellarg('HTTP/3')
        . ' --no-retry --disable-gso --disable-pacing';

    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch local HTTP/3 test server');
    }

    fclose($pipes[0]);
    unset($pipes[0]);

    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $output = '';
    $startupGraceAt = microtime(true) + 1.0;
    $deadline = microtime(true) + 45.0;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;
        $selected = @stream_select($read, $write, $except, 0, 200000);
        if ($selected === false) {
            break;
        }

        if ($selected > 0) {
            foreach ($read as $pipe) {
                $chunk = stream_get_contents($pipe);
                if (is_string($chunk) && $chunk !== '') {
                    $output .= $chunk;
                }
            }
        }

        if (str_contains($output, 'listening on')) {
            return [
                'process' => $process,
                'pipes' => $pipes,
                'port' => (int) $port,
                'host' => 'localhost',
            ];
        }

        if (!$status['running']) {
            break;
        }

        if (microtime(true) >= $startupGraceAt) {
            return [
                'process' => $process,
                'pipes' => $pipes,
                'port' => (int) $port,
                'host' => 'localhost',
            ];
        }
    }

    foreach ($pipes as $pipe) {
        $chunk = stream_get_contents($pipe);
        if (is_string($chunk) && $chunk !== '') {
            $output .= $chunk;
        }
        fclose($pipe);
    }

    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('local HTTP/3 test server failed: ' . trim($output));
}

function king_http3_ticket_server_binary(): string
{
    $root = dirname(dirname(__DIR__));
    $manifest = __DIR__ . '/http3_ticket_server/Cargo.toml';
    $source = __DIR__ . '/http3_ticket_server/src/main.rs';
    $lock = __DIR__ . '/http3_ticket_server/Cargo.lock';
    $binary = __DIR__ . '/http3_ticket_server/target/release/king-http3-ticket-server';

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
        'cargo build --manifest-path %s --release --locked 2>&1',
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
    string $bindHost = '127.0.0.1',
    ?int $port = null,
    ?string $urlHost = null
): array {
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
    $urlHost ??= $bindHost === '127.0.0.1' ? 'localhost' : $bindHost;
    $command = [$binary, $certFile, $keyFile, $rootDir, $bindHost, (string) $port];
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

function king_http3_request_with_retry(callable $callback)
{
    $lastError = null;
    $deadline = microtime(true) + 60.0;

    while (microtime(true) < $deadline) {
        try {
            $result = $callback();
            if ($result !== false) {
                return $result;
            }

            $message = king_get_last_error();
            if (!is_string($message) || $message === '') {
                $message = 'HTTP/3 request returned false before the local server became ready.';
            }
            $lastError = new RuntimeException($message);
        } catch (Throwable $e) {
            $lastError = $e;
        }

        usleep(250000);
    }

    throw $lastError ?? new RuntimeException('HTTP/3 request retry exhausted without an exception.');
}

function king_http3_exception_with_retry(
    callable $callback,
    string $expectedClass,
    ?string $expectedMessage = null
): Throwable {
    $lastError = null;
    $deadline = microtime(true) + 60.0;

    while (microtime(true) < $deadline) {
        try {
            $callback();
            $lastError = new RuntimeException(
                'HTTP/3 failure-path request unexpectedly succeeded before the local peer reached the expected failure state.'
            );
        } catch (Throwable $e) {
            if (
                $e instanceof $expectedClass
                && ($expectedMessage === null || $e->getMessage() === $expectedMessage)
            ) {
                return $e;
            }

            $lastError = $e;
        }

        usleep(250000);
    }

    throw $lastError ?? new RuntimeException('HTTP/3 exception retry exhausted without the expected failure.');
}

function king_http3_start_silent_udp_server(): array
{
    $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    if ($server === false) {
        throw new RuntimeException("failed to reserve silent UDP test port: $errstr");
    }

    $serverName = stream_socket_get_name($server, false);
    [, $port] = explode(':', $serverName, 2);

    return [$server, (int) $port];
}

function king_http3_stop_silent_udp_server(array $server): void
{
    fclose($server[0]);
}

function king_http3_failure_peer_binary(): string
{
    $root = dirname(dirname(__DIR__));
    $manifest = $root . '/quiche/apps/Cargo.toml';
    $source = $root . '/quiche/apps/src/bin/king-http3-failure-peer.rs';
    $binary = $root . '/quiche/target/release/king-http3-failure-peer';

    if (trim((string) shell_exec('command -v cargo')) === '') {
        throw new RuntimeException('cargo is required to build the HTTP/3 failure peer helper');
    }

    if (is_file($binary) && filemtime($binary) >= filemtime($source)) {
        return $binary;
    }

    $command = sprintf(
        'cargo build --manifest-path %s --release --locked --bin king-http3-failure-peer 2>&1',
        escapeshellarg($manifest)
    );

    exec($command, $output, $exitCode);
    if ($exitCode !== 0 || !is_file($binary)) {
        throw new RuntimeException(
            "failed to build HTTP/3 failure peer helper:\n" . implode("\n", $output)
        );
    }

    return $binary;
}

function king_http3_start_failure_peer(
    string $mode,
    string $certFile,
    string $keyFile,
    string $bindHost = '127.0.0.1',
    ?string $urlHost = null
): array {
    $binary = king_http3_failure_peer_binary();
    $urlHost ??= $bindHost === '127.0.0.1' ? 'localhost' : $bindHost;
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
