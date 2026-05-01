<?php

/* Shared helper utilities for HTTP/3 test peers and harness processes. */

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
    require_once dirname(__DIR__) . '/http3_server_wire_helper.inc';

    $server = king_http3_server_wire_start_server($certFile, $keyFile, null, 'static-root', [$rootDir]);
    $server['host'] = 'localhost';
    $server['root'] = $rootDir;
    $server['wire_server'] = true;

    return $server;
}
