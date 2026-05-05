<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_users.php';
require_once __DIR__ . '/../domain/users/user_settings.php';
require_once __DIR__ . '/../domain/users/onboarding_progress.php';

function videochat_onboarding_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[onboarding-progress-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_onboarding_contract_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    videochat_onboarding_contract_assert(is_array($decoded), 'response body must be JSON');
    return $decoded;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-onboarding-progress-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $userId = (int) $pdo->query(
        "SELECT users.id FROM users INNER JOIN roles ON roles.id = users.role_id WHERE roles.slug = 'user' LIMIT 1"
    )->fetchColumn();
    videochat_onboarding_contract_assert($userId > 0, 'expected seeded standard user');

    $sessionId = 'sess_onboarding_progress_contract';
    $pdo->prepare(
        'INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent) VALUES(:id, :user_id, :issued_at, :expires_at, NULL, NULL, NULL)'
    )->execute([
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c', time() - 10),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);

    $initialSettings = videochat_fetch_user_settings($pdo, $userId);
    videochat_onboarding_contract_assert(is_array($initialSettings), 'initial settings lookup should succeed');
    videochat_onboarding_contract_assert(($initialSettings['onboarding_completed_tours'] ?? []) === [], 'initial completed tours should be empty');
    videochat_onboarding_contract_assert(($initialSettings['onboarding_badges'] ?? []) === [], 'initial onboarding badges should be empty');

    $firstCompletion = videochat_complete_onboarding_tour($pdo, $userId, 'governance.users.tour', '2026-05-05T10:00:00+00:00');
    videochat_onboarding_contract_assert((bool) ($firstCompletion['ok'] ?? false), 'first completion should succeed');
    videochat_onboarding_contract_assert((string) ($firstCompletion['reason'] ?? '') === 'completed', 'first completion reason mismatch');
    videochat_onboarding_contract_assert(
        in_array('governance.users.tour', $firstCompletion['onboarding']['completed_tours'] ?? [], true),
        'completed tour key missing from payload'
    );

    $duplicateCompletion = videochat_complete_onboarding_tour($pdo, $userId, 'governance.users.tour', '2026-05-05T11:00:00+00:00');
    videochat_onboarding_contract_assert((bool) ($duplicateCompletion['ok'] ?? false), 'duplicate completion should be idempotent');
    videochat_onboarding_contract_assert((string) ($duplicateCompletion['reason'] ?? '') === 'already_completed', 'duplicate completion reason mismatch');
    videochat_onboarding_contract_assert(
        (string) (($duplicateCompletion['onboarding']['badges'][0] ?? [])['completed_at'] ?? '') === '2026-05-05T10:00:00+00:00',
        'duplicate completion should preserve original completion time'
    );

    $invalidCompletion = videochat_complete_onboarding_tour($pdo, $userId, '../bad-key');
    videochat_onboarding_contract_assert((bool) ($invalidCompletion['ok'] ?? true) === false, 'invalid tour key should fail');
    videochat_onboarding_contract_assert(
        (string) (($invalidCompletion['errors'] ?? [])['tour_key'] ?? '') === 'invalid_tour_key',
        'invalid tour key error mismatch'
    );

    $authContext = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'POST',
            'uri' => '/api/user/onboarding/tours/complete',
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'rest'
    );
    videochat_onboarding_contract_assert((bool) ($authContext['ok'] ?? false), 'auth context should authenticate');
    videochat_onboarding_contract_assert(
        in_array('governance.users.tour', ($authContext['user'] ?? [])['onboarding_completed_tours'] ?? [], true),
        'auth payload should expose completed tours'
    );

    $jsonResponse = static fn (int $status, array $payload): array => [
        'status' => $status,
        'headers' => ['content-type' => 'application/json'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
    $errorResponse = static fn (int $status, string $code, string $message, array $details = []): array => $jsonResponse($status, [
        'status' => 'error',
        'error' => [
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ],
    ]);
    $decodeJsonBody = static function (array $request): array {
        $decoded = json_decode((string) ($request['body'] ?? ''), true);
        return [is_array($decoded) ? $decoded : null, is_array($decoded) ? null : 'invalid_json'];
    };
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);

    $endpointResponse = videochat_handle_user_routes(
        '/api/user/onboarding/tours/complete',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/user/onboarding/tours/complete',
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
            'body' => json_encode(['tour_key' => 'administration.app_configuration.tour'], JSON_UNESCAPED_SLASHES),
        ],
        $authContext,
        [],
        sys_get_temp_dir(),
        1024,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_onboarding_contract_assert(is_array($endpointResponse), 'endpoint response should be handled');
    videochat_onboarding_contract_assert((int) ($endpointResponse['status'] ?? 0) === 200, 'endpoint completion should return 200');
    $endpointPayload = videochat_onboarding_contract_decode($endpointResponse);
    videochat_onboarding_contract_assert(
        in_array('administration.app_configuration.tour', ($endpointPayload['result']['onboarding'] ?? [])['completed_tours'] ?? [], true),
        'endpoint payload should include completed route tour'
    );

    $methodResponse = videochat_handle_user_routes(
        '/api/user/onboarding/tours/complete',
        'GET',
        ['method' => 'GET', 'uri' => '/api/user/onboarding/tours/complete', 'headers' => []],
        $authContext,
        [],
        sys_get_temp_dir(),
        1024,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_onboarding_contract_assert((int) ($methodResponse['status'] ?? 0) === 405, 'wrong method should be rejected');

    @unlink($databasePath);
    fwrite(STDOUT, "[onboarding-progress-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[onboarding-progress-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
