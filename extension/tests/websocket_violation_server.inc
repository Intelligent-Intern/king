<?php

if ($argc < 4) {
    fwrite(STDERR, "usage: websocket_violation_server.php <capture-path> <port> <mode>\n");
    exit(1);
}

$capturePath = $argv[1];
$port = (int) $argv[2];
$mode = $argv[3];

$supportedModes = [
    'bad_opcode',
    'bad_rsv_text',
    'bad_close_payload',
];

if (!in_array($mode, $supportedModes, true)) {
    fwrite(STDERR, "unsupported violation mode: {$mode}\n");
    exit(1);
}

function king_ws_violation_read_exact($stream, int $length): ?string
{
    $data = '';

    while (strlen($data) < $length) {
        $chunk = fread($stream, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($stream);
            if (($meta['timed_out'] ?? false) === true) {
                return null;
            }

            return null;
        }

        $data .= $chunk;
    }

    return $data;
}

function king_ws_violation_write_raw($stream, string $frame): void
{
    $written = 0;
    $length = strlen($frame);

    while ($written < $length) {
        $chunk = fwrite($stream, substr($frame, $written));
        if ($chunk === false || $chunk === 0) {
            throw new RuntimeException('failed to write violation frame');
        }

        $written += $chunk;
    }

    fflush($stream);
}

function king_ws_violation_read_client_frame($stream): ?array
{
    $header = king_ws_violation_read_exact($stream, 2);
    if ($header === null) {
        return null;
    }

    $first = ord($header[0]);
    $second = ord($header[1]);
    $opcode = $first & 0x0f;
    $masked = ($second & 0x80) !== 0;
    $payloadLen = $second & 0x7f;

    if ($payloadLen === 126) {
        $extended = king_ws_violation_read_exact($stream, 2);
        if ($extended === null) {
            return null;
        }
        $payloadLen = unpack('nlen', $extended)['len'];
    } elseif ($payloadLen === 127) {
        $extended = king_ws_violation_read_exact($stream, 8);
        if ($extended === null) {
            return null;
        }
        $unpacked = unpack('Nhi/Nlo', $extended);
        $payloadLen = ((int) $unpacked['hi'] << 32) | (int) $unpacked['lo'];
    }

    $mask = $masked ? king_ws_violation_read_exact($stream, 4) : '';
    if ($masked && $mask === null) {
        return null;
    }

    $payload = $payloadLen > 0 ? king_ws_violation_read_exact($stream, $payloadLen) : '';
    if ($payload === null) {
        return null;
    }

    if ($masked) {
        $decoded = '';
        for ($i = 0; $i < $payloadLen; $i++) {
            $decoded .= $payload[$i] ^ $mask[$i % 4];
        }
        $payload = $decoded;
    }

    $frame = [
        'opcode' => $opcode,
        'payload' => $payload,
    ];

    if ($opcode === 0x8) {
        $frame['close_code'] = $payloadLen >= 2
            ? unpack('ncode', substr($payload, 0, 2))['code']
            : null;
        $frame['close_reason'] = $payloadLen > 2
            ? substr($payload, 2)
            : '';
    }

    return $frame;
}

function king_ws_violation_build_frame(string $mode): string
{
    switch ($mode) {
        case 'bad_opcode':
            return chr(0x8b) . chr(0x00);

        case 'bad_rsv_text':
            $payload = 'boom';
            return chr(0xc1) . chr(strlen($payload)) . $payload;

        case 'bad_close_payload':
            return chr(0x88) . chr(0x01) . 'x';
    }

    throw new RuntimeException('unreachable violation mode');
}

$capture = [
    'mode' => $mode,
    'request_line' => null,
    'headers' => [],
    'client_frames' => [],
];

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

$client = @stream_socket_accept($server, 10);
if (!is_resource($client)) {
    fclose($server);
    file_put_contents($capturePath, json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
    exit(0);
}

stream_set_blocking($client, true);
stream_set_timeout($client, 2);

$requestLine = fgets($client);
if ($requestLine !== false) {
    $capture['request_line'] = rtrim($requestLine, "\r\n");
}

while (($line = fgets($client)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === '') {
        break;
    }

    $parts = explode(':', $line, 2);
    if (count($parts) !== 2) {
        continue;
    }

    $name = trim($parts[0]);
    $value = trim($parts[1]);
    $capture['headers'][$name] ??= [];
    $capture['headers'][$name][] = $value;
}

$secKey = $capture['headers']['Sec-WebSocket-Key'][0] ?? null;
if (!is_string($secKey) || $secKey === '') {
    fwrite(STDERR, "missing Sec-WebSocket-Key\n");
    fclose($client);
    fclose($server);
    file_put_contents($capturePath, json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
    exit(1);
}

$accept = base64_encode(
    sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)
);

$response = "HTTP/1.1 101 Switching Protocols\r\n"
    . "Upgrade: websocket\r\n"
    . "Connection: Upgrade\r\n"
    . "Sec-WebSocket-Accept: {$accept}\r\n"
    . "\r\n";
fwrite($client, $response);
fflush($client);

king_ws_violation_write_raw($client, king_ws_violation_build_frame($mode));

$clientFrame = king_ws_violation_read_client_frame($client);
if ($clientFrame !== null) {
    $capture['client_frames'][] = $clientFrame;
}

fclose($client);
fclose($server);

file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
