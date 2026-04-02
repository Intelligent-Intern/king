<?php

/* Local HTTP probe harness for Smart-DNS live health and sanitization contracts. */

if ($argc < 4) {
    fwrite(STDERR, "usage: semantic_dns_live_probe_server.php <capture-path> <state-path> <port>\n");
    exit(1);
}

$capturePath = $argv[1];
$statePath = $argv[2];
$port = (int) $argv[3];

$capture = [
    'request_count' => 0,
    'requests' => [],
];

function king_semantic_dns_live_probe_load_state(string $statePath): array
{
    $decoded = [];

    if (is_file($statePath)) {
        $decoded = json_decode((string) file_get_contents($statePath), true);
    }

    if (!is_array($decoded)) {
        $decoded = [];
    }

    return [
        'http_status' => (int) ($decoded['http_status'] ?? 200),
        'status' => (string) ($decoded['status'] ?? 'healthy'),
        'current_load_percent' => (int) ($decoded['current_load_percent'] ?? 0),
        'active_connections' => (int) ($decoded['active_connections'] ?? 0),
        'total_requests' => (int) ($decoded['total_requests'] ?? 0),
    ];
}

$server = stream_socket_server(
    'tcp://127.0.0.1:' . $port,
    $errno,
    $errstr
);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}

stream_set_blocking($server, true);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);

while (true) {
    $conn = @stream_socket_accept($server, 10);
    if (!is_resource($conn)) {
        break;
    }

    stream_set_blocking($conn, true);
    stream_set_timeout($conn, 2);

    $requestLine = fgets($conn);
    if ($requestLine === false) {
        fclose($conn);
        continue;
    }

    $requestLine = rtrim($requestLine, "\r\n");
    if ($requestLine === 'STOP') {
        fclose($conn);
        break;
    }

    while (($line = fgets($conn)) !== false) {
        if ($line === "\r\n" || $line === "\n") {
            break;
        }
    }

    $state = king_semantic_dns_live_probe_load_state($statePath);
    $body = "ok";
    $response = "HTTP/1.1 {$state['http_status']} OK\r\n"
        . "Content-Length: " . strlen($body) . "\r\n"
        . "Content-Type: text/plain\r\n"
        . "Connection: close\r\n"
        . "X-King-Service-Status: {$state['status']}\r\n"
        . "X-King-Load-Percent: {$state['current_load_percent']}\r\n"
        . "X-King-Active-Connections: {$state['active_connections']}\r\n"
        . "X-King-Total-Requests: {$state['total_requests']}\r\n\r\n"
        . $body;

    fwrite($conn, $response);
    fflush($conn);
    fclose($conn);

    $capture['request_count']++;
    $capture['requests'][] = [
        'request_line' => $requestLine,
        'response' => $state,
    ];
}

fclose($server);
file_put_contents($capturePath, json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
