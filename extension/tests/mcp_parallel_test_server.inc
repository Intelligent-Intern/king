<?php

if ($argc < 3) {
    fwrite(STDERR, "usage: mcp_parallel_test_server.php <capture-path> <port>\n");
    exit(1);
}

const KING_MCP_PARALLEL_MAX_LINE_BYTES = 8 * 1024 * 1024;

$capturePath = $argv[1];
$port = (int) $argv[2];
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

file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);

stream_set_blocking($server, true);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);

function king_mcp_parallel_decode_field(string $encoded): ?string
{
    $decoded = base64_decode($encoded, true);
    return $decoded === false ? null : $decoded;
}

function king_mcp_parallel_write_line($stream, string $line): void
{
    fwrite($stream, $line . "\n");
    fflush($stream);
}

function king_mcp_parallel_write_error($stream, string $message): void
{
    king_mcp_parallel_write_line($stream, 'ERR' . "\t" . base64_encode($message));
}

function king_mcp_parallel_delay_from_method(string $method, string $prefix, int $defaultMs): int
{
    if ($method === $prefix) {
        return $defaultMs;
    }

    if (strncmp($method, $prefix . '-', strlen($prefix) + 1) != 0) {
        return 0;
    }

    return max(0, (int) substr($method, strlen($prefix) + 1));
}

function king_mcp_parallel_handle_client($client): void
{
    $transfers = [];

    stream_set_blocking($client, true);
    stream_set_timeout($client, 5);

    while (($line = stream_get_line($client, KING_MCP_PARALLEL_MAX_LINE_BYTES, "\n")) !== false) {
        $line = rtrim($line, "\r");
        if ($line === '') {
            continue;
        }

        $parts = explode("\t", $line);
        $opcode = array_shift($parts);

        if ($opcode === 'REQ' && (count($parts) === 3 || count($parts) === 5)) {
            [$serviceB64, $methodB64, $payloadB64] = $parts;
            $service = king_mcp_parallel_decode_field($serviceB64);
            $method = king_mcp_parallel_decode_field($methodB64);
            $payload = king_mcp_parallel_decode_field($payloadB64);

            if ($service === null || $method === null || $payload === null) {
                king_mcp_parallel_write_error($client, 'Remote MCP peer received an invalid request frame.');
                continue;
            }

            if ($service === 'svc' && $method === 'ping') {
                king_mcp_parallel_write_line(
                    $client,
                    'OK' . "\t" . base64_encode(json_encode(['res' => $payload], JSON_UNESCAPED_SLASHES))
                );
                continue;
            }

            king_mcp_parallel_write_line(
                $client,
                'OK' . "\t" . base64_encode(json_encode([
                    'status' => 'ok',
                    'remote' => true,
                    'service' => $service,
                    'method' => $method,
                    'payload' => $payload,
                ], JSON_UNESCAPED_SLASHES))
            );
            continue;
        }

        if ($opcode === 'PUT' && (count($parts) === 4 || count($parts) === 6)) {
            [$serviceB64, $methodB64, $idB64, $payloadB64] = $parts;
            $service = king_mcp_parallel_decode_field($serviceB64);
            $method = king_mcp_parallel_decode_field($methodB64);
            $identifier = king_mcp_parallel_decode_field($idB64);
            $payload = king_mcp_parallel_decode_field($payloadB64);
            $delayMs = 0;

            if ($service === null || $method === null || $identifier === null || $payload === null) {
                king_mcp_parallel_write_error($client, 'Remote MCP peer received an invalid upload frame.');
                continue;
            }

            $delayMs = king_mcp_parallel_delay_from_method($method, 'slow-upload', 400);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $transfers[$service . "\n" . $method . "\n" . $identifier] = $payload;
            king_mcp_parallel_write_line($client, 'OK');
            continue;
        }

        if ($opcode === 'GET' && (count($parts) === 3 || count($parts) === 5)) {
            [$serviceB64, $methodB64, $idB64] = $parts;
            $service = king_mcp_parallel_decode_field($serviceB64);
            $method = king_mcp_parallel_decode_field($methodB64);
            $identifier = king_mcp_parallel_decode_field($idB64);
            $delayMs = 0;
            $key = null;
            $payload = null;

            if ($service === null || $method === null || $identifier === null) {
                king_mcp_parallel_write_error($client, 'Remote MCP peer received an invalid download frame.');
                continue;
            }

            $key = $service . "\n" . $method . "\n" . $identifier;
            $payload = $transfers[$key] ?? null;
            if (!is_string($payload)) {
                king_mcp_parallel_write_line($client, 'MISS');
                continue;
            }

            $delayMs = king_mcp_parallel_delay_from_method($method, 'slow-download', 400);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            king_mcp_parallel_write_line($client, 'OK' . "\t" . base64_encode($payload));
            continue;
        }

        king_mcp_parallel_write_error($client, 'Remote MCP peer received an unsupported command frame.');
    }

    fclose($client);
}

while (true) {
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
    }

    $client = @stream_socket_accept($server, 1);
    if (!is_resource($client)) {
        continue;
    }

    $pid = pcntl_fork();
    if ($pid < 0) {
        fclose($client);
        continue;
    }

    if ($pid === 0) {
        fclose($server);
        king_mcp_parallel_handle_client($client);
        exit(0);
    }

    fclose($client);
}
