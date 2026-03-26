<?php

if ($argc < 3) {
    fwrite(STDERR, "usage: websocket_echo_server.php <capture-path> <port> [max-connections]\n");
    exit(1);
}

$capturePath = $argv[1];
$port = (int) $argv[2];
$maxConnections = isset($argv[3]) ? max(1, (int) $argv[3]) : 1;
$captures = [];

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

function king_ws_server_read_exact($stream, int $length): ?string
{
    $data = '';

    while (strlen($data) < $length) {
        $chunk = fread($stream, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            return null;
        }

        $data .= $chunk;
    }

    return $data;
}

function king_ws_server_frame_write($stream, int $opcode, string $payload = ''): bool
{
    $payloadLen = strlen($payload);
    $frame = chr(0x80 | ($opcode & 0x0f));

    if ($payloadLen <= 125) {
        $frame .= chr($payloadLen);
    } elseif ($payloadLen <= 0xffff) {
        $frame .= chr(126) . pack('n', $payloadLen);
    } else {
        $frame .= chr(127) . pack('NN', ($payloadLen >> 32) & 0xffffffff, $payloadLen & 0xffffffff);
    }

    $frame .= $payload;
    return @fwrite($stream, $frame) !== false;
}

for ($connectionIndex = 0; $connectionIndex < $maxConnections; $connectionIndex++) {
    $client = @stream_socket_accept($server, 10);
    if (!is_resource($client)) {
        break;
    }

    stream_set_blocking($client, true);

    $requestLine = fgets($client);
    if ($requestLine === false) {
        fclose($client);
        continue;
    }

    $headers = [];
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
        $headers[$name] ??= [];
        $headers[$name][] = $value;
    }

    $secKey = $headers['Sec-WebSocket-Key'][0] ?? null;
    if (!is_string($secKey) || $secKey === '') {
        fwrite(STDERR, "missing Sec-WebSocket-Key\n");
        fclose($client);
        continue;
    }

    $accept = base64_encode(
        sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)
    );

    $response = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: {$accept}\r\n"
        . "\r\n";
    @fwrite($client, $response);
    fflush($client);

    $capture = [
        'request_line' => rtrim($requestLine, "\r\n"),
        'headers' => $headers,
        'frames' => [],
    ];

    while (!feof($client)) {
        $header = king_ws_server_read_exact($client, 2);
        if ($header === null) {
            break;
        }

        $first = ord($header[0]);
        $second = ord($header[1]);
        $opcode = $first & 0x0f;
        $masked = ($second & 0x80) !== 0;
        $payloadLen = $second & 0x7f;

        if ($payloadLen === 126) {
            $extended = king_ws_server_read_exact($client, 2);
            if ($extended === null) {
                break;
            }
            $payloadLen = unpack('nlen', $extended)['len'];
        } elseif ($payloadLen === 127) {
            $extended = king_ws_server_read_exact($client, 8);
            if ($extended === null) {
                break;
            }
            $unpacked = unpack('Nhi/Nlo', $extended);
            $payloadLen = ((int) $unpacked['hi'] << 32) | (int) $unpacked['lo'];
        }

        $mask = $masked ? king_ws_server_read_exact($client, 4) : '';
        if ($masked && $mask === null) {
            break;
        }

        $payload = $payloadLen > 0 ? king_ws_server_read_exact($client, $payloadLen) : '';
        if ($payload === null) {
            break;
        }

        if ($masked) {
            $decoded = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $decoded;
        }

        $frameCapture = [
            'opcode' => $opcode,
            'payload' => $payload,
        ];
        if ($opcode === 0x8) {
            $frameCapture['close_code'] = $payloadLen >= 2
                ? unpack('ncode', substr($payload, 0, 2))['code']
                : null;
            $frameCapture['close_reason'] = $payloadLen > 2
                ? substr($payload, 2)
                : '';
        }
        $capture['frames'][] = $frameCapture;

        if ($opcode === 0x1 || $opcode === 0x2) {
            king_ws_server_frame_write($client, $opcode, $payload);
            continue;
        }

        if ($opcode === 0x9) {
            king_ws_server_frame_write($client, 0xA, $payload);
            continue;
        }

        if ($opcode === 0x8) {
            king_ws_server_frame_write($client, 0x8, $payload);
            break;
        }
    }

    fclose($client);
    $captures[] = $capture;
}

fclose($server);
file_put_contents(
    $capturePath,
    json_encode($captures, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
