<?php

/* Test helper for starting the on-wire HTTP/1/WebSocket one-shot listener harness. */

function king_server_websocket_wire_start_server(string $mode, int $iterations = 1, ?int $fixedPort = null, array $env = []): array
{
    if ($fixedPort !== null) {
        $port = $fixedPort;
    } else {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            throw new RuntimeException("failed to reserve on-wire HTTP/1 test port: $errstr");
        }

        $serverName = stream_socket_get_name($probe, false);
        fclose($probe);
        [, $port] = explode(':', $serverName, 2);
    }

    $capture = tempnam(sys_get_temp_dir(), 'king-server-websocket-wire-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $commandParts = [
        escapeshellarg(PHP_BINARY),
        '-n',
        '-d',
        escapeshellarg('extension=' . $extensionPath),
        '-d',
        escapeshellarg('king.security_allow_config_override=1'),
    ];

    if ($mode === 'cors-allowlist') {
        $commandParts[] = '-d';
        $commandParts[] = escapeshellarg(
            'king.security_cors_allowed_origins=https://app.king.test, https://admin.king.test'
        );
    }

    $commandParts = array_merge($commandParts, [
        escapeshellarg(__DIR__ . '/server_websocket_wire_server.inc'),
        escapeshellarg($capture),
        (string) (int) $port,
        escapeshellarg($mode),
        (string) max(1, $iterations),
    ]);

    $command = implode(' ', $commandParts);

    $processEnv = null;
    if ($env !== []) {
        $baseEnv = getenv();
        $processEnv = is_array($baseEnv) ? array_merge($baseEnv, $env) : $env;
    }

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, $processEnv);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch on-wire HTTP/1 websocket server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('on-wire HTTP/1 websocket server failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => (int) $port,
    ];
}

function king_server_websocket_wire_stop_server(array $server): array
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

function king_server_websocket_wire_connect_retry(string $url, int $timeoutMs = 3000)
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    do {
        $websocket = king_client_websocket_connect($url);
        if (is_resource($websocket)) {
            return $websocket;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('failed to connect to on-wire HTTP/1 websocket listener: ' . king_get_last_error());
}

function king_server_http1_wire_request_retry(int $port, string $request, int $timeoutMs = 3000): string
{
    $client = king_server_http1_wire_connect_retry($port, $timeoutMs);
    stream_set_timeout($client, 2);
    fwrite($client, $request);
    $response = stream_get_contents($client);
    fclose($client);
    return (string) $response;
}

function king_server_http1_wire_abort_request(int $port, string $request, int $settleUs = 50000): void
{
    $deadline = microtime(true) + 3.0;
    $socket = false;
    $message = 'connection timed out';

    if (!function_exists('socket_create')) {
        throw new RuntimeException('The sockets extension is required for HTTP/1 abort requests. Please enable it in php.ini.');
    }

    do {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new RuntimeException('failed to create HTTP/1 abort socket');
        }

        if (@socket_connect($socket, '127.0.0.1', $port) === true) {
            break;
        }

        $message = socket_strerror(socket_last_error($socket));
        socket_close($socket);
        $socket = false;
        usleep(50000);
    } while (microtime(true) < $deadline);

    if ($socket === false) {
        throw new RuntimeException('failed to connect to on-wire HTTP/1 listener: ' . $message);
    }

    $written = 0;
    $length = strlen($request);
    while ($written < $length) {
        $chunk = @socket_write($socket, substr($request, $written), $length - $written);
        if ($chunk === false || $chunk === 0) {
            $message = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new RuntimeException('failed while writing HTTP/1 abort request: ' . $message);
        }
        $written += $chunk;
    }

    usleep($settleUs);

    if (defined('SO_LINGER')) {
        @socket_set_option($socket, SOL_SOCKET, SO_LINGER, [
            'l_onoff' => 1,
            'l_linger' => 0,
        ]);
    }

    socket_close($socket);
}

function king_server_http1_wire_parse_response(string $response): array
{
    [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
    $lines = $head === '' ? [] : explode("\r\n", $head);
    $statusLine = array_shift($lines) ?? '';
    $statusParts = explode(' ', $statusLine, 3);
    $headers = [];
    $headerPairs = [];

    foreach ($lines as $line) {
        if ($line === '' || strpos($line, ':') === false) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);
        $trimmedName = trim($name);
        $trimmedValue = trim($value);
        $normalizedName = strtolower($trimmedName);

        $headerPairs[] = [
            'name' => $trimmedName,
            'value' => $trimmedValue,
        ];

        if (!array_key_exists($normalizedName, $headers)) {
            $headers[$normalizedName] = $trimmedValue;
            continue;
        }

        if (is_array($headers[$normalizedName])) {
            $headers[$normalizedName][] = $trimmedValue;
            continue;
        }

        $headers[$normalizedName] = [
            $headers[$normalizedName],
            $trimmedValue,
        ];
    }

    return [
        'status_line' => $statusLine,
        'status' => isset($statusParts[1]) ? (int) $statusParts[1] : 0,
        'headers' => $headers,
        'header_pairs' => $headerPairs,
        'body' => $body,
    ];
}

function king_server_websocket_wire_raw_client_connect(int $port, string $path)
{
    $deadline = microtime(true) + 3.0;
    $socket = false;
    $message = 'connection failed';

    if (!function_exists('socket_create')) {
        throw new RuntimeException('The sockets extension is required for raw websocket client fixtures. Please enable it in php.ini.');
    }

    do {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new RuntimeException('failed to create raw websocket client socket');
        }

        if (@socket_connect($socket, '127.0.0.1', $port) === true) {
            break;
        }

        $message = socket_strerror(socket_last_error($socket));
        socket_close($socket);
        $socket = false;
        usleep(50000);
    } while (microtime(true) < $deadline);

    if ($socket === false) {
        throw new RuntimeException('failed to connect raw websocket client: ' . $message);
    }

    $key = base64_encode(random_bytes(16));
    $request =
        "GET " . $path . " HTTP/1.1\r\n"
        . "Host: 127.0.0.1:" . $port . "\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Version: 13\r\n"
        . "Sec-WebSocket-Key: " . $key . "\r\n\r\n";

    $written = 0;
    $length = strlen($request);
    while ($written < $length) {
        $chunk = @socket_write($socket, substr($request, $written), $length - $written);
        if ($chunk === false || $chunk === 0) {
            $message = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new RuntimeException('failed while writing raw websocket handshake: ' . $message);
        }

        $written += $chunk;
    }

    $response = '';
    while (!str_contains($response, "\r\n\r\n")) {
        $chunk = @socket_read($socket, 8192, PHP_BINARY_READ);
        if ($chunk === false || $chunk === '') {
            $message = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new RuntimeException('failed while reading raw websocket handshake: ' . $message);
        }

        $response .= $chunk;
    }

    if (!str_starts_with($response, "HTTP/1.1 101 ")) {
        socket_close($socket);
        throw new RuntimeException('raw websocket handshake failed: ' . trim(strtok($response, "\r\n")));
    }

    return $socket;
}

function king_server_websocket_wire_raw_client_send_text($socket, string $payload): void
{
    $payloadLength = strlen($payload);
    $mask = random_bytes(4);
    $frame = chr(0x81);

    if ($payloadLength <= 125) {
        $frame .= chr(0x80 | $payloadLength);
    } elseif ($payloadLength <= 65535) {
        $frame .= chr(0x80 | 126) . pack('n', $payloadLength);
    } else {
        $frame .= chr(0x80 | 127) . pack('N2', 0, $payloadLength);
    }

    $maskedPayload = '';
    for ($i = 0; $i < $payloadLength; $i++) {
        $maskedPayload .= $payload[$i] ^ $mask[$i % 4];
    }

    $frame .= $mask . $maskedPayload;

    $written = 0;
    $length = strlen($frame);
    while ($written < $length) {
        $chunk = @socket_write($socket, substr($frame, $written), $length - $written);
        if ($chunk === false || $chunk === 0) {
            $message = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new RuntimeException('failed while writing raw websocket frame: ' . $message);
        }

        $written += $chunk;
    }
}

function king_server_websocket_wire_raw_client_abort($socket): void
{
    if (defined('SO_LINGER')) {
        @socket_set_option($socket, SOL_SOCKET, SO_LINGER, [
            'l_onoff' => 1,
            'l_linger' => 0,
        ]);
    }

    socket_close($socket);
}

function king_server_http1_wire_connect_retry(int $port, int $timeoutMs = 3000)
{
    $deadline = microtime(true) + ($timeoutMs / 1000);
    $errstr = 'connection failed';

    do {
        $client = @stream_socket_client(
            'tcp://127.0.0.1:' . $port,
            $errno,
            $errstr,
            0.25
        );
        if (is_resource($client)) {
            return $client;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("failed to connect to on-wire HTTP/1 listener: $errstr");
}
