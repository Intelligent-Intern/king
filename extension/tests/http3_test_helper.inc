<?php

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
    $probe = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve UDP test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [$host, $port] = explode(':', $serverName, 2);

    $binary = getenv('KING_QUICHE_SERVER');
    $command = 'RUST_LOG=info '
        . escapeshellarg($binary)
        . ' --listen ' . escapeshellarg($host . ':' . $port)
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
    $startupGraceAt = microtime(true) + 8.0;
    $deadline = microtime(true) + 30.0;
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
            return [$process, $pipes, (int) $port];
        }

        if (!$status['running']) {
            break;
        }

        if (microtime(true) >= $startupGraceAt) {
            return [$process, $pipes, (int) $port];
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

function king_http3_stop_test_server(array $server): void
{
    [$process, $pipes] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
}

function king_http3_request_with_retry(callable $callback)
{
    $lastError = null;
    $deadline = microtime(true) + 30.0;

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
