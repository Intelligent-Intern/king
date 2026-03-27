<?php

if ($argc < 4) {
    fwrite(STDERR, "usage: mcp_test_server.php <capture-path> <port> <bind-host> [transfer-state-path]\n");
    exit(1);
}

const KING_MCP_TEST_MAX_LINE_BYTES = 8 * 1024 * 1024;
const KING_MCP_TEST_DELAY_SLICE_US = 10000;

$capturePath = $argv[1];
$port = (int) $argv[2];
$bindHost = (string) $argv[3];
$transferStatePath = $argv[4] ?? null;
$capture = [
    'connections' => [],
    'events' => [],
];
$transfers = [];

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

stream_set_blocking($server, true);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);

function king_mcp_test_persist_capture(array $capture, string $capturePath): void
{
    file_put_contents(
        $capturePath,
        json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
    );
}

function king_mcp_test_load_transfers(?string $transferStatePath): array
{
    $encoded = [];
    $transfers = [];

    if ($transferStatePath === null || $transferStatePath === '' || !is_file($transferStatePath)) {
        return [];
    }

    $encoded = json_decode((string) file_get_contents($transferStatePath), true);
    if (!is_array($encoded)) {
        return [];
    }

    foreach ($encoded as $encodedKey => $encodedPayload) {
        $key = base64_decode((string) $encodedKey, true);
        $payload = base64_decode((string) $encodedPayload, true);
        if ($key === false || $payload === false) {
            continue;
        }
        $transfers[$key] = $payload;
    }

    return $transfers;
}

function king_mcp_test_persist_transfers(array $transfers, ?string $transferStatePath): void
{
    $encoded = [];

    if ($transferStatePath === null || $transferStatePath === '') {
        return;
    }

    foreach ($transfers as $key => $payload) {
        $encoded[base64_encode((string) $key)] = base64_encode((string) $payload);
    }

    file_put_contents(
        $transferStatePath,
        json_encode($encoded, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
    );
}

function king_mcp_test_capture_event(array &$capture, string $capturePath, array $event): void
{
    $capture['events'][] = $event;
    king_mcp_test_persist_capture($capture, $capturePath);
}

function king_mcp_test_transfer_key(string $service, string $method, string $id): string
{
    $encode = static function (string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    };

    return 'mcp.v1.' . $encode($service) . '.' . $encode($method) . '.' . $encode($id);
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

function king_mcp_test_parse_budget(array $parts, int $index): int
{
    if (!isset($parts[$index])) {
        return 0;
    }

    return max(0, (int) $parts[$index]);
}

function king_mcp_test_effective_budget(int $timeoutBudgetMs, int $deadlineBudgetMs): array
{
    if ($timeoutBudgetMs > 0 && $deadlineBudgetMs > 0) {
        return $timeoutBudgetMs <= $deadlineBudgetMs
            ? [$timeoutBudgetMs, 'timeout']
            : [$deadlineBudgetMs, 'deadline'];
    }

    if ($timeoutBudgetMs > 0) {
        return [$timeoutBudgetMs, 'timeout'];
    }

    if ($deadlineBudgetMs > 0) {
        return [$deadlineBudgetMs, 'deadline'];
    }

    return [0, null];
}

function king_mcp_test_connection_cancelled($stream): bool
{
    $read = [$stream];
    $write = null;
    $except = null;
    $selected = @stream_select($read, $write, $except, 0, 0);

    if ($selected !== 1) {
        return false;
    }

    return @stream_socket_recvfrom($stream, 1, STREAM_PEEK) === '';
}

function king_mcp_test_maybe_delay(
    $stream,
    int $delayMs,
    int $timeoutBudgetMs,
    int $deadlineBudgetMs,
    array &$event
): ?string {
    [$effectiveBudgetMs, $budgetKind] = king_mcp_test_effective_budget(
        $timeoutBudgetMs,
        $deadlineBudgetMs
    );
    $elapsedMs = 0;

    while ($elapsedMs < $delayMs) {
        $sliceMs = min(
            max(1, (int) (($delayMs - $elapsedMs))),
            (int) (KING_MCP_TEST_DELAY_SLICE_US / 1000)
        );

        if (king_mcp_test_connection_cancelled($stream)) {
            $event['peer_observed_cancel'] = true;
            $event['peer_cancel_reason'] = 'disconnect';
            return 'cancelled';
        }

        if ($effectiveBudgetMs > 0 && $elapsedMs >= $effectiveBudgetMs) {
            $event['peer_budget_exhausted'] = $budgetKind;
            return $budgetKind;
        }

        usleep($sliceMs * 1000);
        $elapsedMs += $sliceMs;
    }

    return null;
}

function king_mcp_test_handle_request(
    $stream,
    string $service,
    string $method,
    string $payload,
    int $timeoutBudgetMs,
    int $deadlineBudgetMs,
    array &$event
): ?string {
    if ($service === 'svc' && $method === 'sleep') {
        $delayResult = king_mcp_test_maybe_delay(
            $stream,
            max(0, (int) $payload),
            $timeoutBudgetMs,
            $deadlineBudgetMs,
            $event
        );
        if ($delayResult === 'cancelled') {
            return null;
        }
        if ($delayResult === 'timeout') {
            king_mcp_test_write_error($stream, 'Remote MCP peer exceeded the propagated timeout budget.');
            return null;
        }
        if ($delayResult === 'deadline') {
            king_mcp_test_write_error($stream, 'Remote MCP peer exceeded the propagated deadline budget.');
            return null;
        }
    }

    if ($service === 'svc' && $method === 'drop-request') {
        $event['remote_error'] = 'forced_request_disconnect';
        $event['peer_observed_cancel'] = true;
        $event['peer_cancel_reason'] = 'forced_disconnect';
        return null;
    }

    if ($service === 'svc' && $method === 'fail') {
        king_mcp_test_write_error($stream, 'Remote MCP peer forced request failure.');
        $event['remote_error'] = 'forced_request_failure';
        return null;
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
$transfers = king_mcp_test_load_transfers($transferStatePath);

king_mcp_test_persist_capture($capture, $capturePath);

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
            king_mcp_test_capture_event($capture, $capturePath, [
                'connection_id' => $connectionId,
                'operation' => 'stop',
            ]);
            king_mcp_test_write_line($client, 'OK');
            $shouldStop = true;
            break;
        }

        $parts = explode("\t", $line);
        $opcode = array_shift($parts);
        $capture['connections'][$connectionIndex]['command_count']++;

        if ($opcode === 'REQ' && (count($parts) === 3 || count($parts) === 5)) {
            [$serviceB64, $methodB64, $payloadB64] = $parts;
            $service = king_mcp_test_decode_field($serviceB64);
            $method = king_mcp_test_decode_field($methodB64);
            $payload = king_mcp_test_decode_field($payloadB64);
            $timeoutBudgetMs = king_mcp_test_parse_budget($parts, 3);
            $deadlineBudgetMs = king_mcp_test_parse_budget($parts, 4);

            if ($service === null || $method === null || $payload === null) {
                king_mcp_test_write_error($client, 'Remote MCP peer received an invalid request frame.');
                continue;
            }

            $event = [
                'connection_id' => $connectionId,
                'operation' => 'request',
                'service' => $service,
                'method' => $method,
                'payload_length' => strlen($payload),
                'timeout_budget_ms' => $timeoutBudgetMs,
                'deadline_budget_ms' => $deadlineBudgetMs,
            ];
            $response = king_mcp_test_handle_request(
                $client,
                $service,
                $method,
                $payload,
                $timeoutBudgetMs,
                $deadlineBudgetMs,
                $event
            );
            $event['response_length'] = is_string($response) ? strlen($response) : 0;
            king_mcp_test_capture_event($capture, $capturePath, $event);
            if (!is_string($response)) {
                if (
                    ($event['peer_cancel_reason'] ?? null) === 'disconnect'
                    || ($event['peer_cancel_reason'] ?? null) === 'forced_disconnect'
                ) {
                    break;
                }
                continue;
            }
            king_mcp_test_write_line($client, 'OK' . "\t" . base64_encode($response));
            continue;
        }

        if ($opcode === 'PUT' && (count($parts) === 4 || count($parts) === 6)) {
            [$serviceB64, $methodB64, $idB64, $payloadB64] = $parts;
            $service = king_mcp_test_decode_field($serviceB64);
            $method = king_mcp_test_decode_field($methodB64);
            $identifier = king_mcp_test_decode_field($idB64);
            $payload = king_mcp_test_decode_field($payloadB64);
            $timeoutBudgetMs = king_mcp_test_parse_budget($parts, 4);
            $deadlineBudgetMs = king_mcp_test_parse_budget($parts, 5);

            if ($service === null || $method === null || $identifier === null || $payload === null) {
                king_mcp_test_write_error($client, 'Remote MCP peer received an invalid upload frame.');
                continue;
            }

            $event = [
                'connection_id' => $connectionId,
                'operation' => 'upload',
                'service' => $service,
                'method' => $method,
                'identifier' => $identifier,
                'payload_length' => strlen($payload),
                'timeout_budget_ms' => $timeoutBudgetMs,
                'deadline_budget_ms' => $deadlineBudgetMs,
            ];

            if ($method === 'fail-upload') {
                $event['remote_error'] = 'forced_upload_failure';
                king_mcp_test_capture_event($capture, $capturePath, $event);
                king_mcp_test_write_error($client, 'Remote MCP peer forced upload failure.');
                continue;
            }

            if ($method === 'drop-upload') {
                $event['remote_error'] = 'forced_upload_disconnect';
                $event['peer_observed_cancel'] = true;
                $event['peer_cancel_reason'] = 'forced_disconnect';
                king_mcp_test_capture_event($capture, $capturePath, $event);
                break;
            }

            if ($method === 'slow-upload') {
                $delayResult = king_mcp_test_maybe_delay(
                    $client,
                    200,
                    $timeoutBudgetMs,
                    $deadlineBudgetMs,
                    $event
                );
                if ($delayResult === 'cancelled') {
                    king_mcp_test_capture_event($capture, $capturePath, $event);
                    break;
                }
                if ($delayResult === 'timeout') {
                    king_mcp_test_capture_event($capture, $capturePath, $event);
                    king_mcp_test_write_error($client, 'Remote MCP peer exceeded the propagated timeout budget.');
                    continue;
                }
                if ($delayResult === 'deadline') {
                    king_mcp_test_capture_event($capture, $capturePath, $event);
                    king_mcp_test_write_error($client, 'Remote MCP peer exceeded the propagated deadline budget.');
                    continue;
                }
            }

            $transfers[king_mcp_test_transfer_key($service, $method, $identifier)] = $payload;
            king_mcp_test_persist_transfers($transfers, $transferStatePath);
            king_mcp_test_capture_event($capture, $capturePath, $event);
            king_mcp_test_write_line($client, 'OK');
            continue;
        }

        if ($opcode === 'GET' && (count($parts) === 3 || count($parts) === 5)) {
            [$serviceB64, $methodB64, $idB64] = $parts;
            $service = king_mcp_test_decode_field($serviceB64);
            $method = king_mcp_test_decode_field($methodB64);
            $identifier = king_mcp_test_decode_field($idB64);
            $timeoutBudgetMs = king_mcp_test_parse_budget($parts, 3);
            $deadlineBudgetMs = king_mcp_test_parse_budget($parts, 4);

            if ($service === null || $method === null || $identifier === null) {
                king_mcp_test_write_error($client, 'Remote MCP peer received an invalid download frame.');
                continue;
            }

            $key = king_mcp_test_transfer_key($service, $method, $identifier);
            $payload = $transfers[$key] ?? null;
            $event = [
                'connection_id' => $connectionId,
                'operation' => 'download',
                'service' => $service,
                'method' => $method,
                'identifier' => $identifier,
                'payload_length' => is_string($payload) ? strlen($payload) : 0,
                'hit' => is_string($payload),
                'timeout_budget_ms' => $timeoutBudgetMs,
                'deadline_budget_ms' => $deadlineBudgetMs,
            ];

            if ($method === 'fail-download') {
                $event['remote_error'] = 'forced_download_failure';
                king_mcp_test_capture_event($capture, $capturePath, $event);
                king_mcp_test_write_error($client, 'Remote MCP peer forced download failure.');
                continue;
            }

            if ($method === 'drop-download') {
                $event['remote_error'] = 'forced_download_disconnect';
                $event['peer_observed_cancel'] = true;
                $event['peer_cancel_reason'] = 'forced_disconnect';
                king_mcp_test_capture_event($capture, $capturePath, $event);
                break;
            }

            if (!is_string($payload)) {
                king_mcp_test_capture_event($capture, $capturePath, $event);
                king_mcp_test_write_line($client, 'MISS');
                continue;
            }

            if ($method === 'slow-download') {
                $delayResult = king_mcp_test_maybe_delay(
                    $client,
                    200,
                    $timeoutBudgetMs,
                    $deadlineBudgetMs,
                    $event
                );
                if ($delayResult === 'cancelled') {
                    king_mcp_test_capture_event($capture, $capturePath, $event);
                    break;
                }
                if ($delayResult === 'timeout') {
                    king_mcp_test_capture_event($capture, $capturePath, $event);
                    king_mcp_test_write_error($client, 'Remote MCP peer exceeded the propagated timeout budget.');
                    continue;
                }
                if ($delayResult === 'deadline') {
                    king_mcp_test_capture_event($capture, $capturePath, $event);
                    king_mcp_test_write_error($client, 'Remote MCP peer exceeded the propagated deadline budget.');
                    continue;
                }
            }

            king_mcp_test_capture_event($capture, $capturePath, $event);
            king_mcp_test_write_line($client, 'OK' . "\t" . base64_encode($payload));
            continue;
        }

        king_mcp_test_write_error($client, 'Remote MCP peer received an unsupported command frame.');
    }

    fclose($client);
}

fclose($server);
king_mcp_test_persist_capture($capture, $capturePath);
