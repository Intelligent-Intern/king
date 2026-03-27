<?php

function king_http2_start_failure_test_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve HTTP/2 failure test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $command = escapeshellarg(PHP_BINARY)
        . ' -n '
        . escapeshellarg(__DIR__ . '/http2_failure_test_server.inc')
        . ' '
        . (int) $port;
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch local HTTP/2 failure test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        throw new RuntimeException('local HTTP/2 failure test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, (int) $port];
}

function king_http2_stop_failure_test_server(array $server): void
{
    [$process, $pipes] = $server;

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fwrite($pipes[0], "STOP\n");
        fclose($pipes[0]);
    }

    if (isset($pipes[1]) && is_resource($pipes[1])) {
        fclose($pipes[1]);
    }

    if (isset($pipes[2]) && is_resource($pipes[2])) {
        fclose($pipes[2]);
    }

    proc_close($process);
}
