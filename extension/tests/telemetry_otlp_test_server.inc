<?php

$capturePath = $argv[1];
$planPath = $argv[2];
$port = (int) $argv[3];
$bindHost = $argv[4] ?? '127.0.0.1';
$plan = json_decode((string) file_get_contents($planPath), true);

if (!is_array($plan)) {
    $plan = [];
}

$server = stream_socket_server("tcp://{$bindHost}:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

fwrite(STDOUT, "READY\n");
$captures = [];
$requestIndex = 0;

while (($conn = @stream_socket_accept($server, 15)) !== false) {
    stream_set_timeout($conn, 15);

    $buffer = fread($conn, 8192);
    if ($buffer === false || $buffer === '') {
        fclose($conn);
        continue;
    }

    if (strncmp($buffer, "STOP\n", 5) == 0 || strncmp($buffer, "STOP\r\n", 6) == 0) {
        fclose($conn);
        break;
    }

    while (!str_contains($buffer, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buffer .= $chunk;
    }

    [$head, $body] = array_pad(explode("\r\n\r\n", $buffer, 2), 2, '');
    $lines = explode("\r\n", $head);
    $requestLine = array_shift($lines);
    $parts = explode(' ', $requestLine, 3);
    $method = $parts[0] ?? '';
    $path = $parts[1] ?? '';
    $headers = [];
    $contentLength = 0;

    foreach ($lines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    if (isset($headers['content-length'])) {
        $contentLength = (int) $headers['content-length'];
    }

    while (strlen($body) < $contentLength) {
        $chunk = fread($conn, $contentLength - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    $captures[] = [
        'method' => $method,
        'path' => $path,
        'body' => $body,
        'headers' => $headers,
    ];
    file_put_contents($capturePath, json_encode($captures, JSON_PRETTY_PRINT));

    $entry = $plan[$requestIndex] ?? [];
    $requestIndex++;

    if (isset($entry['delay_ms'])) {
        usleep((int) $entry['delay_ms'] * 1000);
    }

    $status = (int) ($entry['status'] ?? 200);
    $responseBody = (string) ($entry['body'] ?? '');
    if (isset($entry['body_bytes']) && (int) $entry['body_bytes'] > 0) {
        $responseBody = str_repeat('R', (int) $entry['body_bytes']);
    }

    $statusText = match ($status) {
        200 => 'OK',
        202 => 'Accepted',
        400 => 'Bad Request',
        413 => 'Payload Too Large',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
        default => 'Status',
    };

    $response = "HTTP/1.1 {$status} {$statusText}\r\n"
        . 'Content-Length: ' . strlen($responseBody) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $responseBody;
    fwrite($conn, $response);
    fclose($conn);
}

file_put_contents($capturePath, json_encode($captures, JSON_PRETTY_PRINT));
fclose($server);
