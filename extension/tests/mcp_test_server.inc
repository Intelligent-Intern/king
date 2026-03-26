<?php

if ($argc < 3) {
    fwrite(STDERR, "usage: mcp_test_server.php <capture-path> <port>\n");
    exit(1);
}

const KING_MCP_TEST_MAX_LINE_BYTES = 8 * 1024 * 1024;

$capturePath = $argv[1];
$port = (int) $argv[2];
$capture = [
    'connections' => [],
    'events' => [],
];
$transfers = [];

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

function king_mcp_test_transfer_key(string $service, string $method, string $id): string
{
    return $service . "\n" . $method . "\n" . $id;
}

function king_mcp_test_decode_field(string $encoded): ?string
{
    $decoded = base64_decode($encoded, true);
    return $decoded === false ? null : $decoded;
}

function king_mcp_test_write_line($stream, string $line): void
{
    fwrite($stream, $line . "\n");
    fflush($stream);
}

function king_mcp_test_write_error($stream, string $message): void
{
    king_mcp_test_write_line($stream, 'ERR' . "\t" . base64_encode($message));
}

function king_mcp_test_handle_request(string $service, string $method, string $payload): string
{
    if ($service === 'svc' && $method === 'sleep') {
        usleep(max(0, (int) $payload) * 1000);
    }

    if ($service === 'svc' && $method === 'ping') {
        return json_encode(['res' => $payload], JSON_UNESCAPED_SLASHES);
    }

    return json_encode([
        'status' => 'ok',
        'remote' => true,
        'service' => $service,
        'method' => $method,
        'payload' => $payload,
    ], JSON_UNESCAPED_SLASHES);
}

$nextConnectionId = 1;
$shouldStop = false;

while (!$shouldStop) {
    $client = @stream_socket_accept($server, 5);
    if (!is_resource($client)) {
        continue;
    }

    stream_set_blocking($client, true);
    stream_set_timeout($client, 5);

    $connectionId = $nextConnectionId++;
    $connectionIndex = count($capture['connections']);
    $capture['connections'][] = [
        'id' => $connectionId,
        'command_count' => 0,
    ];

    while (($line = stream_get_line($client, KING_MCP_TEST_MAX_LINE_BYTES, "\n")) !== false) {
        $line = rtrim($line, "\r");
        if ($line === '') {
            continue;
        }

        if ($line === 'STOP') {
            $capture['events'][] = [
                'connection_id' => $connectionId,
                'operation' => 'stop',
            ];
            king_mcp_test_write_line($client, 'OK');
            $shouldStop = true;
            break;
        }

        $parts = explode("\t", $line);
        $opcode = array_shift($parts);
        $capture['connections'][$connectionIndex]['command_count']++;

        if ($opcode === 'REQ' && count($parts) === 3) {
            [$serviceB64, $methodB64, $payloadB64] = $parts;
            $service = king_mcp_test_decode_field($serviceB64);
            $method = king_mcp_test_decode_field($methodB64);
            $payload = king_mcp_test_decode_field($payloadB64);

            if ($service === null || $method === null || $payload === null) {
                king_mcp_test_write_error($client, 'Remote MCP peer received an invalid request frame.');
                continue;
            }

            $response = king_mcp_test_handle_request($service, $method, $payload);
            $capture['events'][] = [
                'connection_id' => $connectionId,
                'operation' => 'request',
                'service' => $service,
                'method' => $method,
                'payload_length' => strlen($payload),
                'response_length' => strlen($response),
            ];
            king_mcp_test_write_line($client, 'OK' . "\t" . base64_encode($response));
            continue;
        }

        if ($opcode === 'PUT' && count($parts) === 4) {
            [$serviceB64, $methodB64, $idB64, $payloadB64] = $parts;
            $service = king_mcp_test_decode_field($serviceB64);
            $method = king_mcp_test_decode_field($methodB64);
            $identifier = king_mcp_test_decode_field($idB64);
            $payload = king_mcp_test_decode_field($payloadB64);

            if ($service === null || $method === null || $identifier === null || $payload === null) {
                king_mcp_test_write_error($client, 'Remote MCP peer received an invalid upload frame.');
                continue;
            }

            $transfers[king_mcp_test_transfer_key($service, $method, $identifier)] = $payload;
            $capture['events'][] = [
                'connection_id' => $connectionId,
                'operation' => 'upload',
                'service' => $service,
                'method' => $method,
                'identifier' => $identifier,
                'payload_length' => strlen($payload),
            ];
            king_mcp_test_write_line($client, 'OK');
            continue;
        }

        if ($opcode === 'GET' && count($parts) === 3) {
            [$serviceB64, $methodB64, $idB64] = $parts;
            $service = king_mcp_test_decode_field($serviceB64);
            $method = king_mcp_test_decode_field($methodB64);
            $identifier = king_mcp_test_decode_field($idB64);

            if ($service === null || $method === null || $identifier === null) {
                king_mcp_test_write_error($client, 'Remote MCP peer received an invalid download frame.');
                continue;
            }

            $key = king_mcp_test_transfer_key($service, $method, $identifier);
            $payload = $transfers[$key] ?? null;
            $capture['events'][] = [
                'connection_id' => $connectionId,
                'operation' => 'download',
                'service' => $service,
                'method' => $method,
                'identifier' => $identifier,
                'payload_length' => is_string($payload) ? strlen($payload) : 0,
                'hit' => is_string($payload),
            ];

            if (!is_string($payload)) {
                king_mcp_test_write_line($client, 'MISS');
                continue;
            }

            king_mcp_test_write_line($client, 'OK' . "\t" . base64_encode($payload));
            continue;
        }

        king_mcp_test_write_error($client, 'Remote MCP peer received an unsupported command frame.');
    }

    fclose($client);
}

fclose($server);
file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
