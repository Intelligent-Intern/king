<?php

/* Harness server for HTTP/1 interim-response and Early Hints budget contracts. */

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
    stream_set_write_buffer($conn, 0);

    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === '' || $chunk === false) {
            fclose($conn);
            continue 2;
        }
        $request .= $chunk;
    }

    [$head] = array_pad(explode("\r\n\r\n", $request, 2), 1, '');
    $lines = $head === '' ? [] : explode("\r\n", $head);
    $requestLine = array_shift($lines) ?? '';
    $parts = explode(' ', $requestLine, 3);
    $requestTarget = $parts[1] ?? '/';
    $path = parse_url($requestTarget, PHP_URL_PATH) ?: $requestTarget;

    if ($path === '/interim-size-cap') {
        $response = "HTTP/1.1 102 Processing\r\n"
            . "X-Fill: " . str_repeat('a', 60000) . "\r\n\r\n";

        for ($i = 0; $i < 160; $i++) {
            if (@fwrite($conn, $response) === false) {
                break;
            }
        }

        fclose($conn);
        continue;
    }

    if ($path === '/early-hints-cap') {
        $response = "HTTP/1.1 103 Early Hints\r\n"
            . "Link: </asset.css>; rel=preload; as=style\r\n\r\n";

        for ($i = 0; $i < 300; $i++) {
            if (@fwrite($conn, $response) === false) {
                break;
            }
        }

        @fwrite(
            $conn,
            "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n"
        );
        fclose($conn);
        continue;
    }

    $body = "unexpected-path";
    @fwrite(
        $conn,
        "HTTP/1.1 404 Not Found\r\n"
        . "Content-Length: " . strlen($body) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $body
    );
    fclose($conn);
}

fclose($server);
