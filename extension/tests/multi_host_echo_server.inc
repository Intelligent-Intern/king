<?php

/* Host-bound TCP echo server for the shared multi-host namespace harness. */

require_once __DIR__ . '/multi_host_test_helper.inc';

if ($argc < 4) {
    fwrite(STDERR, "usage: multi_host_echo_server.inc <capture-path> <port> <bind-host>\n");
    exit(1);
}

$capturePath = $argv[1];
$port = (int) $argv[2];
$bindHost = $argv[3];
$capture = [];

$server = stream_socket_server(
    king_multi_host_test_format_tcp_endpoint($bindHost, $port),
    $errno,
    $errstr
);
if ($server === false) {
    fwrite(STDERR, "failed to bind echo server: {$errstr}\n");
    exit(1);
}

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$client = @stream_socket_accept($server, 10);
if (is_resource($client)) {
    $capture['peer_name'] = stream_socket_get_name($client, true);
    $capture['local_name'] = stream_socket_get_name($client, false);
    $capture['received'] = rtrim((string) fgets($client), "\r\n");
    fwrite($client, 'host-ack:' . $capture['received'] . "\n");
    fflush($client);
    fclose($client);
}

fclose($server);
file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
