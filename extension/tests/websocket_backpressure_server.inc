<?php

/* Harness server for concurrent WebSocket backpressure and fairness contracts. */

if ($argc < 5) {
    fwrite(STDERR, "usage: websocket_backpressure_server.php <capture-path> <port> <burst-count> <payload-bytes> [max-connections]\n");
    exit(1);
}

$capturePath = $argv[1];
$port = (int) $argv[2];
$burstCount = max(1, (int) $argv[3]);
$payloadBytes = max(16, (int) $argv[4]);
$maxConnections = isset($argv[5]) ? max(1, (int) $argv[5]) : 12;
$capture = [
    'connections' => [],
    'events' => [],
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

stream_set_blocking($server, false);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);

function king_ws_backpressure_read_exact($stream, int $length): ?string
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

function king_ws_backpressure_frame_write($stream, int $opcode, string $payload = ''): bool
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

function king_ws_backpressure_accept_client($server, array &$capture, int &$nextClientId): ?array
{
    $client = @stream_socket_accept($server, 0);
    if (!is_resource($client)) {
        return null;
    }

    stream_set_blocking($client, true);
    stream_set_timeout($client, 2);

    $requestLine = fgets($client);
    if ($requestLine === false) {
        fclose($client);
        return null;
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
        fclose($client);
        return null;
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

    $clientId = $nextClientId++;
    $capture['connections'][] = [
        'id' => $clientId,
        'request_line' => rtrim($requestLine, "\r\n"),
        'path' => preg_match('/^[A-Z]+\\s+(\\S+)\\s+HTTP\\//', $requestLine, $matches) ? $matches[1] : '',
        'headers' => $headers,
        'frames' => [],
        'sent_frames' => [],
    ];

    return [
        'id' => $clientId,
        'stream' => $client,
        'capture_index' => count($capture['connections']) - 1,
        'path' => $capture['connections'][count($capture['connections']) - 1]['path'],
        'closed' => false,
        'flooded' => false,
    ];
}

function king_ws_backpressure_close_client(array &$clientState): void
{
    if (!empty($clientState['closed'])) {
        return;
    }

    fclose($clientState['stream']);
    $clientState['closed'] = true;
}

function king_ws_backpressure_burst_payload(int $index, int $payloadBytes): string
{
    $prefix = sprintf('slow-%02d-', $index);

    if (strlen($prefix) >= $payloadBytes) {
        return substr($prefix, 0, $payloadBytes);
    }

    return $prefix . str_repeat('x', $payloadBytes - strlen($prefix));
}

$clients = [];
$acceptedConnections = 0;
$nextClientId = 1;
$deadline = microtime(true) + 15.0;

while (microtime(true) < $deadline) {
    $read = [$server];
    foreach ($clients as $clientState) {
        if (!$clientState['closed']) {
            $read[] = $clientState['stream'];
        }
    }

    $write = null;
    $except = null;
    $selected = @stream_select($read, $write, $except, 0, 200000);
    if ($selected === false) {
        break;
    }

    foreach ($read as $stream) {
        if ($stream === $server) {
            while ($acceptedConnections < $maxConnections) {
                $clientState = king_ws_backpressure_accept_client($server, $capture, $nextClientId);
                if ($clientState === null) {
                    break;
                }

                $clients[(int) $clientState['id']] = $clientState;
                $acceptedConnections++;
            }

            continue;
        }

        foreach ($clients as &$clientState) {
            if ($clientState['closed'] || $clientState['stream'] !== $stream) {
                continue;
            }

            $header = king_ws_backpressure_read_exact($stream, 2);
            if ($header === null) {
                king_ws_backpressure_close_client($clientState);
                break;
            }

            $first = ord($header[0]);
            $second = ord($header[1]);
            $opcode = $first & 0x0f;
            $masked = ($second & 0x80) !== 0;
            $payloadLen = $second & 0x7f;

            if ($payloadLen === 126) {
                $extended = king_ws_backpressure_read_exact($stream, 2);
                if ($extended === null) {
                    king_ws_backpressure_close_client($clientState);
                    break;
                }
                $payloadLen = unpack('nlen', $extended)['len'];
            } elseif ($payloadLen === 127) {
                $extended = king_ws_backpressure_read_exact($stream, 8);
                if ($extended === null) {
                    king_ws_backpressure_close_client($clientState);
                    break;
                }
                $unpacked = unpack('Nhi/Nlo', $extended);
                $payloadLen = ((int) $unpacked['hi'] << 32) | (int) $unpacked['lo'];
            }

            $mask = $masked ? king_ws_backpressure_read_exact($stream, 4) : '';
            if ($masked && $mask === null) {
                king_ws_backpressure_close_client($clientState);
                break;
            }

            $payload = $payloadLen > 0 ? king_ws_backpressure_read_exact($stream, $payloadLen) : '';
            if ($payload === null) {
                king_ws_backpressure_close_client($clientState);
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

            $capture['connections'][$clientState['capture_index']]['frames'][] = $frameCapture;
            $capture['events'][] = [
                'client_id' => $clientState['id'],
                'opcode' => $opcode,
                'payload' => $payload,
            ];

            if ($opcode === 0x1) {
                if ($payload === 'FLOOD' && !$clientState['flooded']) {
                    for ($messageIndex = 0; $messageIndex < $burstCount; $messageIndex++) {
                        $messagePayload = king_ws_backpressure_burst_payload($messageIndex, $payloadBytes);
                        king_ws_backpressure_frame_write($stream, 0x1, $messagePayload);
                        $capture['connections'][$clientState['capture_index']]['sent_frames'][] = [
                            'opcode' => 0x1,
                            'payload' => $messagePayload,
                        ];
                    }
                    $clientState['flooded'] = true;
                } else {
                    king_ws_backpressure_frame_write($stream, 0x1, $payload);
                    $capture['connections'][$clientState['capture_index']]['sent_frames'][] = [
                        'opcode' => 0x1,
                        'payload' => $payload,
                    ];
                }
                break;
            }

            if ($opcode === 0x9) {
                king_ws_backpressure_frame_write($stream, 0xA, $payload);
                $capture['connections'][$clientState['capture_index']]['sent_frames'][] = [
                    'opcode' => 0xA,
                    'payload' => $payload,
                ];
                break;
            }

            if ($opcode === 0x8) {
                king_ws_backpressure_frame_write($stream, 0x8, $payload);
                $capture['connections'][$clientState['capture_index']]['sent_frames'][] = [
                    'opcode' => 0x8,
                    'payload' => $payload,
                ];
                king_ws_backpressure_close_client($clientState);
                break;
            }
        }
        unset($clientState);
    }

    $allClosed = !empty($clients);
    foreach ($clients as $clientState) {
        if (!$clientState['closed']) {
            $allClosed = false;
            break;
        }
    }
    if ($allClosed) {
        break;
    }
}

foreach ($clients as &$clientState) {
    if (!$clientState['closed']) {
        king_ws_backpressure_close_client($clientState);
    }
}
unset($clientState);

fclose($server);
file_put_contents($capturePath, json_encode($capture, JSON_INVALID_UTF8_SUBSTITUTE));
