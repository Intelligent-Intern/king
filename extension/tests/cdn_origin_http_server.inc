<?php

/* Bounded local HTTP origin harness for CDN readthrough timeout, retry, and size-guard proofs. */

if ($argc < 5) {
    fwrite(STDERR, "usage: cdn_origin_http_server.inc <capture-path> <plan-path> <port> <bind-host>\n");
    exit(1);
}

$capturePath = $argv[1];
$planPath = $argv[2];
$port = (int) $argv[3];
$bindHost = $argv[4];
$plan = json_decode((string) file_get_contents($planPath), true);
if (!is_array($plan)) {
    fwrite(STDERR, "invalid plan\n");
    exit(2);
}

$server = stream_socket_server("tcp://{$bindHost}:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(3);
}

stream_set_blocking($server, true);
$capture = [
    'events' => [],
];

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$requestIndex = 0;
while (true) {
    $conn = @stream_socket_accept($server, 5);
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
    $method = $parts[0] ?? '';
    $path = $parts[1] ?? '';

    if ($method === 'STOP') {
        fclose($conn);
        break;
    }

    $entry = $plan[$requestIndex] ?? [];
    $requestIndex++;
    $action = isset($entry['hang_ms']) ? 'hang' : 'response';
    $capture['events'][] = [
        'request_index' => $requestIndex,
        'method' => $method,
        'path' => $path,
        'expected_path' => $entry['path'] ?? null,
        'action' => $action,
        'status' => (int) ($entry['status'] ?? 200),
        'hang_ms' => (int) ($entry['hang_ms'] ?? 0),
        'body_size' => isset($entry['body_size']) ? (int) $entry['body_size'] : strlen((string) ($entry['body'] ?? '')),
    ];

    if (isset($entry['hang_ms'])) {
        usleep(max(0, (int) $entry['hang_ms']) * 1000);
        fclose($conn);
        continue;
    }

    $status = (int) ($entry['status'] ?? 200);
    $body = (string) ($entry['body'] ?? '');
    $bodySize = isset($entry['body_size']) ? (int) $entry['body_size'] : strlen($body);
    $bodyByte = (string) ($entry['body_byte'] ?? 'x');
    $reason = match ($status) {
        200 => 'OK',
        404 => 'Not Found',
        503 => 'Service Unavailable',
        default => 'Origin Response',
    };
    if ($bodyByte === '') {
        $bodyByte = 'x';
    }
    $bodyByte = $bodyByte[0];

    $response = "HTTP/1.1 {$status} {$reason}\r\n"
        . "Content-Type: text/plain\r\n"
        . "Content-Length: " . $bodySize . "\r\n"
        . "Connection: close\r\n\r\n";
    fwrite($conn, $response);
    if (isset($entry['body_size'])) {
        $remaining = $bodySize;
        while ($remaining > 0) {
            $chunkLength = min($remaining, 8192);
            $chunk = str_repeat($bodyByte, $chunkLength);
            fwrite($conn, $chunk);
            $remaining -= $chunkLength;
        }
    } else {
        fwrite($conn, $body);
    }
    fflush($conn);
    fclose($conn);

    if ($requestIndex >= count($plan)) {
        break;
    }
}

fclose($server);
file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
