<?php

/* MCP harness server for repo-local SQL/pgvector bridge request/response contract checks. */

if ($argc < 4) {
    fwrite(STDERR, "usage: sql_vector_bridge_mcp_server.inc <capture-path> <port> <bind-host>\n");
    exit(1);
}

const KING_SQL_VECTOR_BRIDGE_MAX_LINE_BYTES = 8 * 1024 * 1024;

$capturePath = (string) $argv[1];
$port = (int) $argv[2];
$bindHost = (string) $argv[3];

$capture = [
    'events' => [],
];

$server = stream_socket_server(
    str_contains($bindHost, ':') && !preg_match('/^\[.*\]$/', $bindHost)
        ? 'tcp://[' . $bindHost . ']:' . $port
        : 'tcp://' . $bindHost . ':' . $port,
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

function king_sql_vector_bridge_decode_field(string $encoded): ?string
{
    $decoded = base64_decode($encoded, true);

    return $decoded === false ? null : $decoded;
}

function king_sql_vector_bridge_write_line($stream, string $line): void
{
    fwrite($stream, $line . "\n");
    fflush($stream);
}

function king_sql_vector_bridge_write_error($stream, string $message): void
{
    king_sql_vector_bridge_write_line($stream, "ERR\t" . base64_encode($message));
}

function king_sql_vector_bridge_parse_budget(array $parts, int $index): int
{
    if (!isset($parts[$index])) {
        return 0;
    }

    return max(0, (int) $parts[$index]);
}

$shouldStop = false;

while (!$shouldStop) {
    $client = @stream_socket_accept($server, 5);
    if (!is_resource($client)) {
        continue;
    }

    stream_set_blocking($client, true);
    stream_set_timeout($client, 5);

    while (($line = stream_get_line($client, KING_SQL_VECTOR_BRIDGE_MAX_LINE_BYTES, "\n")) !== false) {
        $line = rtrim($line, "\r");
        if ($line === '') {
            continue;
        }

        if ($line === 'STOP') {
            $capture['events'][] = ['operation' => 'stop'];
            king_sql_vector_bridge_write_line($client, 'OK');
            $shouldStop = true;
            break;
        }

        $parts = explode("\t", $line);
        $opcode = array_shift($parts);

        if ($opcode !== 'REQ' || (count($parts) !== 3 && count($parts) !== 5)) {
            $capture['events'][] = [
                'operation' => 'protocol_error',
                'opcode' => $opcode,
                'part_count' => count($parts),
            ];
            king_sql_vector_bridge_write_error($client, 'unsupported MCP command frame for sql-vector bridge harness.');
            continue;
        }

        [$serviceB64, $methodB64, $payloadB64] = $parts;
        $service = king_sql_vector_bridge_decode_field($serviceB64);
        $method = king_sql_vector_bridge_decode_field($methodB64);
        $payloadRaw = king_sql_vector_bridge_decode_field($payloadB64);

        if (!is_string($service) || !is_string($method) || !is_string($payloadRaw)) {
            $capture['events'][] = ['operation' => 'decode_error'];
            king_sql_vector_bridge_write_error($client, 'invalid base64 payload in MCP request frame.');
            continue;
        }

        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            $capture['events'][] = [
                'operation' => 'json_error',
                'service' => $service,
                'method' => $method,
            ];
            king_sql_vector_bridge_write_error($client, 'sql-vector request payload must be valid JSON.');
            continue;
        }

        $event = [
            'operation' => 'request',
            'service' => $service,
            'method' => $method,
            'timeout_budget_ms' => king_sql_vector_bridge_parse_budget($parts, 3),
            'deadline_budget_ms' => king_sql_vector_bridge_parse_budget($parts, 4),
            'payload' => $payload,
        ];
        $capture['events'][] = $event;

        if ($service !== 'sql_vector' || $method !== 'search') {
            king_sql_vector_bridge_write_error($client, 'unsupported sql-vector bridge route.');
            continue;
        }

        $requestId = is_string($payload['request_id'] ?? null) ? $payload['request_id'] : 'missing-request-id';
        $index = is_string($payload['index'] ?? null) ? $payload['index'] : 'missing-index';

        $response = [
            'schema' => 'king.sql_vector.result.v1',
            'request_id' => $requestId,
            'index' => $index,
            'matches' => [
                [
                    'id' => 'doc-1',
                    'score' => 0.93,
                    'metadata' => [
                        'source' => 'faq',
                        'tenant' => (string) (($payload['filters']['tenant'] ?? 'unknown')),
                    ],
                ],
                [
                    'id' => 'doc-2',
                    'score' => 0.87,
                    'metadata' => [
                        'source' => 'kb',
                    ],
                ],
            ],
            'stats' => [
                'backend' => 'pgvector',
                'engine' => 'postgresql',
                'latency_ms' => 12,
            ],
        ];

        king_sql_vector_bridge_write_line(
            $client,
            'OK' . "\t" . base64_encode((string) json_encode($response, JSON_UNESCAPED_SLASHES))
        );
    }

    fclose($client);
}

fclose($server);
file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
