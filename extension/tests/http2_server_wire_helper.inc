<?php

function king_http2_server_wire_start_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve on-wire HTTP/2 test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $capture = tempnam(sys_get_temp_dir(), 'king-http2-server-wire-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $command = sprintf(
        '%s -n -d %s %s %s %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg(__DIR__ . '/http2_server_wire_server.inc'),
        escapeshellarg($capture),
        (int) $port
    );

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch on-wire HTTP/2 listener');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('on-wire HTTP/2 listener failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => (int) $port,
    ];
}

function king_http2_server_wire_stop_server(array $server): array
{
    $stdout = isset($server['pipes'][1]) ? stream_get_contents($server['pipes'][1]) : '';
    $stderr = isset($server['pipes'][2]) ? stream_get_contents($server['pipes'][2]) : '';

    foreach ($server['pipes'] as $pipe) {
        fclose($pipe);
    }
    $exitCode = proc_close($server['process']);

    $capture = [];
    if (is_file($server['capture'])) {
        $capture = json_decode((string) file_get_contents($server['capture']), true);
        if (!is_array($capture)) {
            $capture = [];
        }
        @unlink($server['capture']);
    }

    if ($capture === [] && ($stdout !== '' || $stderr !== '' || $exitCode !== 0)) {
        $capture = [
            '__stdout' => $stdout,
            '__stderr' => $stderr,
            '__exit_code' => $exitCode,
        ];
    }

    return $capture;
}

function king_http2_wire_static_header(int $index): array
{
    return match ($index) {
        1 => [':authority', ''],
        2 => [':method', 'GET'],
        3 => [':method', 'POST'],
        4 => [':path', '/'],
        5 => [':path', '/index.html'],
        6 => [':scheme', 'http'],
        7 => [':scheme', 'https'],
        8 => [':status', '200'],
        9 => [':status', '204'],
        10 => [':status', '206'],
        11 => [':status', '304'],
        12 => [':status', '400'],
        13 => [':status', '404'],
        14 => [':status', '500'],
        31 => ['content-type', ''],
        default => throw new RuntimeException("unsupported HPACK static header index $index"),
    };
}

function king_http2_wire_encode_integer(int $value, int $prefixBits, int $firstByteMask): string
{
    $prefixMax = (1 << $prefixBits) - 1;
    if ($value < $prefixMax) {
        return chr($firstByteMask | $value);
    }

    $encoded = chr($firstByteMask | $prefixMax);
    $value -= $prefixMax;
    while ($value >= 128) {
        $encoded .= chr(($value % 128) + 128);
        $value = intdiv($value, 128);
    }

    return $encoded . chr($value);
}

function king_http2_wire_encode_string(string $value): string
{
    return king_http2_wire_encode_integer(strlen($value), 7, 0x00) . $value;
}

function king_http2_wire_encode_literal_static_name(int $nameIndex, string $value): string
{
    return king_http2_wire_encode_integer($nameIndex, 4, 0x00)
        . king_http2_wire_encode_string($value);
}

function king_http2_wire_encode_literal_name(string $name, string $value): string
{
    return "\x00"
        . king_http2_wire_encode_string($name)
        . king_http2_wire_encode_string($value);
}

function king_http2_wire_decode_integer(string $buffer, int $offset, int $prefixBits): array
{
    if (!isset($buffer[$offset])) {
        throw new RuntimeException('truncated HPACK integer');
    }

    $mask = (1 << $prefixBits) - 1;
    $value = ord($buffer[$offset]) & $mask;
    $consumed = 1;
    if ($value < $mask) {
        return [$value, $consumed];
    }

    $multiplier = 0;
    while (isset($buffer[$offset + $consumed])) {
        $byte = ord($buffer[$offset + $consumed]);
        $consumed++;
        $value += ($byte & 0x7f) << $multiplier;
        if (($byte & 0x80) === 0) {
            return [$value, $consumed];
        }
        $multiplier += 7;
    }

    throw new RuntimeException('truncated HPACK integer continuation');
}

function king_http2_wire_decode_string(string $buffer, int $offset): array
{
    if (!isset($buffer[$offset])) {
        throw new RuntimeException('truncated HPACK string');
    }

    if ((ord($buffer[$offset]) & 0x80) !== 0) {
        throw new RuntimeException('Huffman-coded HPACK strings are not used by this wire helper');
    }

    [$length, $consumed] = king_http2_wire_decode_integer($buffer, $offset, 7);
    $start = $offset + $consumed;
    $value = substr($buffer, $start, $length);
    if (strlen($value) !== $length) {
        throw new RuntimeException('truncated HPACK string payload');
    }

    return [$value, $consumed + $length];
}

function king_http2_wire_decode_header_block(string $block): array
{
    $headers = [];
    $offset = 0;

    while ($offset < strlen($block)) {
        $first = ord($block[$offset]);

        if (($first & 0x80) !== 0) {
            [$index, $consumed] = king_http2_wire_decode_integer($block, $offset, 7);
            [$name, $value] = king_http2_wire_static_header($index);
            $offset += $consumed;
        } elseif (($first & 0xe0) === 0x20) {
            throw new RuntimeException('unexpected HPACK dynamic table size update');
        } else {
            $prefixBits = ($first & 0xc0) === 0x40 ? 6 : 4;
            [$nameIndex, $consumed] = king_http2_wire_decode_integer($block, $offset, $prefixBits);
            $offset += $consumed;

            if ($nameIndex === 0) {
                [$name, $consumed] = king_http2_wire_decode_string($block, $offset);
                $offset += $consumed;
            } else {
                [$name] = king_http2_wire_static_header($nameIndex);
            }

            [$value, $consumed] = king_http2_wire_decode_string($block, $offset);
            $offset += $consumed;
        }

        $headers[strtolower($name)] = $value;
    }

    return $headers;
}

function king_http2_wire_frame(int $type, int $flags, int $streamId, string $payload = ''): string
{
    $length = strlen($payload);

    return chr(($length >> 16) & 0xff)
        . chr(($length >> 8) & 0xff)
        . chr($length & 0xff)
        . chr($type & 0xff)
        . chr($flags & 0xff)
        . pack('N', $streamId & 0x7fffffff)
        . $payload;
}

function king_http2_wire_read_exact($stream, int $bytes, string $label): string
{
    $data = '';
    while (strlen($data) < $bytes) {
        $chunk = fread($stream, $bytes - strlen($data));
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($stream);
            if (($meta['timed_out'] ?? false) === true) {
                throw new RuntimeException("timed out while reading $label");
            }
            if (feof($stream)) {
                throw new RuntimeException("unexpected EOF while reading $label");
            }
            usleep(10000);
            continue;
        }

        $data .= $chunk;
    }

    return $data;
}

function king_http2_wire_read_frame($stream): array
{
    $header = king_http2_wire_read_exact($stream, 9, 'HTTP/2 frame header');
    $length = (ord($header[0]) << 16) | (ord($header[1]) << 8) | ord($header[2]);
    $type = ord($header[3]);
    $flags = ord($header[4]);
    $streamId = unpack('N', substr($header, 5, 4))[1] & 0x7fffffff;
    $payload = $length > 0
        ? king_http2_wire_read_exact($stream, $length, 'HTTP/2 frame payload')
        : '';

    return [$type, $flags, $streamId, $payload];
}

function king_http2_server_wire_request_retry(int $port, array $request, int $timeoutMs = 3000): array
{
    $deadline = microtime(true) + ($timeoutMs / 1000);
    $lastError = 'connect failed';

    do {
        $client = @stream_socket_client(
            'tcp://127.0.0.1:' . $port,
            $errno,
            $errstr,
            0.25
        );
        if (is_resource($client)) {
            stream_set_timeout($client, 2);

            $method = strtoupper((string) ($request['method'] ?? 'GET'));
            $path = (string) ($request['path'] ?? '/');
            $body = (string) ($request['body'] ?? '');
            $headers = $request['headers'] ?? [];
            $authority = '127.0.0.1:' . $port;

            $headerBlock = $method === 'POST' ? "\x83" : "\x82";
            $headerBlock .= "\x86";
            $headerBlock .= king_http2_wire_encode_literal_static_name(4, $path);
            $headerBlock .= king_http2_wire_encode_literal_static_name(1, $authority);
            foreach ($headers as $name => $value) {
                $headerBlock .= king_http2_wire_encode_literal_name(
                    strtolower((string) $name),
                    (string) $value
                );
            }

            $wire = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
            $wire .= king_http2_wire_frame(0x4, 0x0, 0, '');
            $wire .= king_http2_wire_frame(
                0x1,
                $body === '' ? 0x5 : 0x4,
                1,
                $headerBlock
            );
            if ($body !== '') {
                $wire .= king_http2_wire_frame(0x0, 0x1, 1, $body);
            }

            fwrite($client, $wire);

            $responseHeaders = [];
            $responseBody = '';
            $responseStatus = null;
            $sawEndStream = false;
            $sawGoaway = false;

            while (!$sawGoaway || !$sawEndStream) {
                [$type, $flags, $streamId, $payload] = king_http2_wire_read_frame($client);

                if ($type === 0x4) {
                    if (($flags & 0x1) === 0) {
                        fwrite($client, king_http2_wire_frame(0x4, 0x1, 0, ''));
                    }
                    continue;
                }

                if ($type === 0x1) {
                    $decoded = king_http2_wire_decode_header_block($payload);
                    foreach ($decoded as $name => $value) {
                        $responseHeaders[$name] = $value;
                    }
                    if (isset($decoded[':status'])) {
                        $responseStatus = (int) $decoded[':status'];
                    }
                    if (($flags & 0x1) !== 0) {
                        $sawEndStream = true;
                    }
                    continue;
                }

                if ($type === 0x0) {
                    $responseBody .= $payload;
                    if (($flags & 0x1) !== 0) {
                        $sawEndStream = true;
                    }
                    continue;
                }

                if ($type === 0x7) {
                    $sawGoaway = true;
                    if ($sawEndStream) {
                        break;
                    }
                }
            }

            stream_set_timeout($client, 0, 250000);
            $tail = '';
            $peerClosed = false;
            $closeDeadline = microtime(true) + 0.5;
            do {
                $chunk = fread($client, 1);
                if ($chunk === '' && feof($client)) {
                    $peerClosed = true;
                    break;
                }
                if ($chunk !== false) {
                    $tail .= $chunk;
                }
            } while (microtime(true) < $closeDeadline);

            fclose($client);

            return [
                'status' => $responseStatus,
                'headers' => $responseHeaders,
                'body' => $responseBody,
                'saw_goaway' => $sawGoaway,
                'peer_closed' => $peerClosed,
                'tail' => $tail,
            ];
        }

        $lastError = $errstr;
        usleep(50000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("failed to connect to on-wire HTTP/2 listener: $lastError");
}
