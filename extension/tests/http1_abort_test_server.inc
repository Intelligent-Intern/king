<?php

/* Harness server for HTTP/1 early-abort and truncated-body contracts. */

$port = (int) ($argv[1] ?? 0);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

fwrite(STDOUT, "READY\n");

while (true) {
    $conn = @stream_socket_accept($server, 10);
    if ($conn === false) {
        break;
    }

    stream_set_timeout($conn, 5);
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $request .= $chunk;
    }

    [$head] = array_pad(explode("\r\n\r\n", $request, 2), 1, '');
    $lines = $head === '' ? [] : explode("\r\n", $head);
    $requestLine = array_shift($lines) ?? '';
    $parts = explode(' ', $requestLine, 3);
    $requestTarget = $parts[1] ?? '/';
    $path = parse_url($requestTarget, PHP_URL_PATH) ?: $requestTarget;

    if ($path === '/header-abort') {
        fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain");
        fclose($conn);
        continue;
    }

    if ($path === '/length-abort') {
        $response = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/plain\r\n"
            . "Content-Length: 11\r\n"
            . "Connection: close\r\n\r\n"
            . "alpha";
        fwrite($conn, $response);
        fclose($conn);
        continue;
    }

    if ($path === '/chunked-abort') {
        $response = "HTTP/1.1 200 OK\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Connection: close\r\n\r\n"
            . "5\r\nalpha\r\n3\r\nbe";
        fwrite($conn, $response);
        fclose($conn);
        continue;
    }

    $body = "unexpected-path";
    $response = "HTTP/1.1 404 Not Found\r\n"
        . "Content-Length: " . strlen($body) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $body;
    fwrite($conn, $response);
    fclose($conn);
}

fclose($server);
