<?php

/* Namespaced TCP client for the shared multi-host namespace harness. */

require_once __DIR__ . '/multi_host_test_helper.inc';

if ($argc < 4) {
    fwrite(STDERR, "usage: multi_host_echo_client.inc <capture-path> <host> <port>\n");
    exit(1);
}

$capturePath = $argv[1];
$host = $argv[2];
$port = (int) $argv[3];
$capture = [];

try {
    $socket = stream_socket_client(
        king_multi_host_test_format_tcp_endpoint($host, $port),
        $errno,
        $errstr,
        5.0
    );
    if ($socket === false) {
        throw new RuntimeException('failed to connect multi-host echo client: ' . $errstr);
    }

    $capture['target_host'] = $host;
    $capture['target_port'] = $port;
    $capture['local_name'] = stream_socket_get_name($socket, false);
    $capture['peer_name'] = stream_socket_get_name($socket, true);

    fwrite($socket, "namespace-hello\n");
    fflush($socket);
    $capture['reply'] = rtrim((string) fgets($socket), "\r\n");
    fclose($socket);
} catch (Throwable $e) {
    $capture['exception_class'] = get_class($e);
    $capture['exception_message'] = $e->getMessage();
}

file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);

exit(isset($capture['exception_class']) ? 1 : 0);
