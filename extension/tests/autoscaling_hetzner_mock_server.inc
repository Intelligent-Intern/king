<?php

$logFile = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$deleteStatus = (int) ($argv[3] ?? 204);
$createStatus = (int) ($argv[4] ?? 201);
$nextId = (int) ($argv[5] ?? 9000);
$bindHost = $argv[6] ?? '127.0.0.1';

$server = @stream_socket_server("tcp://{$bindHost}:{$port}", $errno, $errstr);
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
    $request = fread($conn, 8192);
    if ($request === false) {
        fclose($conn);
        continue;
    }

    if (trim($request) === 'STOP') {
        fclose($conn);
        break;
    }

    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $request .= $chunk;
    }

    [$head, $body] = explode("\r\n\r\n", $request, 2) + ['', ''];
    $lines = explode("\r\n", $head);
    $requestLine = array_shift($lines);
    $contentLength = 0;

    if (!is_string($requestLine) || $requestLine === '') {
        fclose($conn);
        continue;
    }

    [$method, $path] = explode(' ', $requestLine, 3);
    foreach ($lines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        if (strtolower(trim($name)) === 'content-length') {
            $contentLength = (int) trim($value);
        }
    }

    while (strlen($body) < $contentLength) {
        $chunk = fread($conn, $contentLength - strlen($body));
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $body .= $chunk;
    }

    file_put_contents($logFile, json_encode([
        'method' => $method,
        'path' => $path,
        'body' => $body,
    ]) . "\n", FILE_APPEND);

    if ($method === 'POST' && $path === '/v1/servers') {
        $id = $nextId++;
        $payload = json_encode([
            'server' => [
                'id' => $id,
                'name' => "king-autoscale-$id",
                'status' => 'running',
            ],
        ]);
        $statusLine = $createStatus === 201 ? '201 Created' : $createStatus . ' Error';
        $response = "HTTP/1.1 {$statusLine}\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($payload) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $payload;
        fwrite($conn, $response);
    } elseif ($method === 'DELETE' && preg_match('#^/v1/servers/\d+$#', $path) === 1) {
        if ($deleteStatus === 204) {
            fwrite($conn, "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n");
        } else {
            $payload = json_encode(['error' => 'delete failed']);
            $statusLine = $deleteStatus . ' Error';
            $response = "HTTP/1.1 {$statusLine}\r\n"
                . "Content-Type: application/json\r\n"
                . "Content-Length: " . strlen($payload) . "\r\n"
                . "Connection: close\r\n\r\n"
                . $payload;
            fwrite($conn, $response);
        }
    } else {
        $payload = json_encode(['error' => 'unexpected request']);
        $response = "HTTP/1.1 404 Not Found\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($payload) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $payload;
        fwrite($conn, $response);
    }

    fclose($conn);
}

fclose($server);
