<?php

/* Harness server for HTTP/1 HEAD, 103, 204 and 304 bodiless-response contracts. */

$port = (int) ($argv[1] ?? 0);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

fwrite(STDOUT, "READY\n");

$connectionCount = 0;
while (true) {
    $conn = @stream_socket_accept($server, 10);
    if ($conn === false) {
        break;
    }

    $connectionCount++;
    $connectionId = (string) $connectionCount;
    stream_set_timeout($conn, 5);

    while (true) {
        $request = '';
        while (!str_contains($request, "\r\n\r\n")) {
            $chunk = fread($conn, 8192);
            if ($chunk === '' || $chunk === false) {
                break 2;
            }
            $request .= $chunk;
        }

        [$head] = array_pad(explode("\r\n\r\n", $request, 2), 1, '');
        $lines = $head === '' ? [] : explode("\r\n", $head);
        $requestLine = array_shift($lines) ?? '';
        $parts = explode(' ', $requestLine, 3);
        $method = $parts[0] ?? '';
        $requestTarget = $parts[1] ?? '/';
        $path = parse_url($requestTarget, PHP_URL_PATH) ?: $requestTarget;

        if ($path === '/head-reuse') {
            $response = "HTTP/1.1 200 OK\r\n"
                . "X-Connection-Id: {$connectionId}\r\n"
                . "Content-Length: 15\r\n"
                . "Connection: keep-alive\r\n\r\n";
            fwrite($conn, $response);
            continue;
        }

        if ($path === '/after-head') {
            $body = json_encode([
                'method' => $method,
                'connection_id' => $connectionId,
                'path' => $path,
            ], JSON_UNESCAPED_SLASHES);
            $response = "HTTP/1.1 200 OK\r\n"
                . "Content-Type: application/json\r\n"
                . "X-Connection-Id: {$connectionId}\r\n"
                . "Content-Length: " . strlen($body) . "\r\n"
                . "Connection: close\r\n\r\n"
                . $body;
            fwrite($conn, $response);
            fclose($conn);
            continue 2;
        }

        if ($path === '/not-modified') {
            $response = "HTTP/1.1 304 Not Modified\r\n"
                . "ETag: \"bodiless-etag\"\r\n"
                . "X-Connection-Id: {$connectionId}\r\n"
                . "Connection: close\r\n\r\n";
            fwrite($conn, $response);
            fclose($conn);
            continue 2;
        }

        if ($path === '/early-hints-no-content') {
            $response = "HTTP/1.1 103 Early Hints\r\n"
                . "Link: </app.css>; rel=preload; as=style\r\n\r\n"
                . "HTTP/1.1 204 No Content\r\n"
                . "X-Connection-Id: {$connectionId}\r\n"
                . "Connection: close\r\n\r\n";
            fwrite($conn, $response);
            fclose($conn);
            continue 2;
        }

        $body = "unexpected-path";
        $response = "HTTP/1.1 404 Not Found\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
        fwrite($conn, $response);
        fclose($conn);
        continue 2;
    }
}

fclose($server);
