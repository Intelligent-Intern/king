<?php

/* Test helper for starting the TLS-mismatch harness server for HTTP clients. */

function king_http_tls_mismatch_start_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $command = escapeshellarg(PHP_BINARY)
        . ' -n '
        . escapeshellarg(__DIR__ . '/http_tls_mismatch_test_server.inc')
        . ' '
        . (int) $port;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch TLS mismatch test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        throw new RuntimeException('TLS mismatch test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, (int) $port];
}

function king_http_tls_mismatch_stop_server(array $server): void
{
    [$process, $pipes] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
}
