<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../http/module_users.php';

function videochat_client_diagnostics_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[client-diagnostics-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_client_diagnostics_contract_json_response(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => [
            'content-type' => 'application/json',
        ],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ];
}

function videochat_client_diagnostics_contract_error_response(
    int $status,
    string $code,
    string $message,
    array $details = []
): array {
    return videochat_client_diagnostics_contract_json_response($status, [
        'status' => 'error',
        'error' => [
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ],
        'time' => gmdate('c'),
    ]);
}

function videochat_client_diagnostics_contract_decode_json_body(array $request): array
{
    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return [null, 'empty_body'];
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return [$decoded, null];
    } catch (Throwable $error) {
        return [null, $error->getMessage()];
    }
}

function videochat_client_diagnostics_contract_decode_response(array $response): array
{
    $body = $response['body'] ?? '{}';
    $decoded = json_decode((string) $body, true);
    return is_array($decoded) ? $decoded : [];
}

$databasePath = tempnam(sys_get_temp_dir(), 'videochat-client-diagnostics-');
videochat_client_diagnostics_contract_assert(is_string($databasePath) && $databasePath !== '', 'temporary database path should be created');

try {
    videochat_bootstrap_sqlite($databasePath);

    $openDatabase = static function () use ($databasePath): PDO {
        return videochat_open_sqlite_pdo($databasePath);
    };

    $response = videochat_handle_user_routes(
        '/api/user/client-diagnostics',
        'POST',
        [
            'body' => json_encode([
                'entries' => [
                    [
                        'category' => 'media',
                        'level' => 'error',
                        'event_type' => 'sfu_remote_video_stalled',
                        'code' => 'stall_detected',
                        'message' => 'Remote publisher advertised tracks but no decoded frames arrived.',
                        'call_id' => 'call_contract_123',
                        'room_id' => 'room_contract_123',
                        'repeat_count' => 3,
                        'payload' => [
                            'publisher_id' => 'pub_contract_1',
                            'track_count' => 1,
                            'frame_count' => 0,
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES),
        ],
        [
            'user' => [
                'id' => 2,
                'role' => 'user',
            ],
            'session' => [
                'id' => 'sess_contract_1',
            ],
        ],
        [],
        __DIR__ . '/../var/avatar-files',
        5 * 1024 * 1024,
        'videochat_client_diagnostics_contract_json_response',
        'videochat_client_diagnostics_contract_error_response',
        'videochat_client_diagnostics_contract_decode_json_body',
        $openDatabase
    );

    videochat_client_diagnostics_contract_assert((int) ($response['status'] ?? 0) === 202, 'client diagnostics endpoint should return 202');

    $payload = videochat_client_diagnostics_contract_decode_response($response);
    videochat_client_diagnostics_contract_assert(($payload['status'] ?? '') === 'ok', 'client diagnostics endpoint should return ok status');
    videochat_client_diagnostics_contract_assert((int) ($payload['result']['accepted_count'] ?? 0) === 1, 'client diagnostics endpoint should accept one entry');
    videochat_client_diagnostics_contract_assert((int) ($payload['result']['stored_count'] ?? 0) === 1, 'client diagnostics endpoint should persist one entry');
    videochat_client_diagnostics_contract_assert((string) ($payload['result']['store_mode'] ?? '') === 'database', 'client diagnostics endpoint should persist to database during the contract');

    $pdo = $openDatabase();
    $rows = $pdo->query('SELECT * FROM client_diagnostics ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    videochat_client_diagnostics_contract_assert(count($rows) === 1, 'one diagnostics row should be stored');

    $row = is_array($rows[0] ?? null) ? $rows[0] : [];
    videochat_client_diagnostics_contract_assert((int) ($row['user_id'] ?? 0) === 2, 'diagnostics row should keep authenticated user id');
    videochat_client_diagnostics_contract_assert((string) ($row['event_type'] ?? '') === 'sfu_remote_video_stalled', 'diagnostics row should keep event type');
    videochat_client_diagnostics_contract_assert((string) ($row['call_id'] ?? '') === 'call_contract_123', 'diagnostics row should keep call id');
    videochat_client_diagnostics_contract_assert((int) ($row['repeat_count'] ?? 0) === 3, 'diagnostics row should keep repeat count');

    $invalidResponse = videochat_handle_user_routes(
        '/api/user/client-diagnostics',
        'POST',
        [
            'body' => json_encode([
                'entries' => [
                    [
                        'level' => 'error',
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES),
        ],
        [
            'user' => [
                'id' => 2,
                'role' => 'user',
            ],
            'session' => [
                'id' => 'sess_contract_1',
            ],
        ],
        [],
        __DIR__ . '/../var/avatar-files',
        5 * 1024 * 1024,
        'videochat_client_diagnostics_contract_json_response',
        'videochat_client_diagnostics_contract_error_response',
        'videochat_client_diagnostics_contract_decode_json_body',
        $openDatabase
    );

    videochat_client_diagnostics_contract_assert((int) ($invalidResponse['status'] ?? 0) === 422, 'invalid diagnostics payload should return 422');

    fwrite(STDOUT, "[client-diagnostics-contract] PASS\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "[client-diagnostics-contract] FAIL: {$exception->getMessage()}\n");
    exit(1);
} finally {
    if (is_string($databasePath) && $databasePath !== '' && file_exists($databasePath)) {
        @unlink($databasePath);
    }
}
