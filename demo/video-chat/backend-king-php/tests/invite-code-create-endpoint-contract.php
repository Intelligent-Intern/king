<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/invite_codes.php';
require_once __DIR__ . '/../http/module_invites.php';

function videochat_invite_create_endpoint_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[invite-code-create-endpoint-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_invite_create_endpoint_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

function videochat_invite_create_endpoint_uuid_v4_like(string $value): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        strtolower($value)
    ) === 1;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-invite-create-endpoint-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('admin@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_invite_create_endpoint_assert($adminUserId > 0, 'expected seeded admin user');

    $standardUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('user@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_invite_create_endpoint_assert($standardUserId > 0, 'expected seeded standard user');

    $createCall = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Invite Endpoint Contract Call',
        'starts_at' => '2026-08-01T09:00:00Z',
        'ends_at' => '2026-08-01T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
        'external_participants' => [],
    ]);
    videochat_invite_create_endpoint_assert((bool) ($createCall['ok'] ?? false), 'call create should succeed for invite endpoint contract');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_invite_create_endpoint_assert($callId !== '', 'call id must be present');

    $adminSessionId = 'sess_invite_create_endpoint_admin';
    $userSessionId = 'sess_invite_create_endpoint_user';
    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', :user_agent)
SQL
    );
    $insertSession->execute([
        ':id' => $adminSessionId,
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'invite-create-endpoint-contract-admin',
    ]);
    $insertSession->execute([
        ':id' => $userSessionId,
        ':user_id' => $standardUserId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'invite-create-endpoint-contract-user',
    ]);

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return $jsonResponse($status, [
            'status' => 'error',
            'error' => $error,
            'time' => gmdate('c'),
        ]);
    };

    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return [null, 'empty_body'];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [null, 'invalid_json'];
        }

        return [$decoded, null];
    };

    $openDatabase = static function () use ($databasePath): PDO {
        return videochat_open_sqlite_pdo($databasePath);
    };

    $adminAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'POST',
            'uri' => '/api/invite-codes',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        ],
        'rest'
    );
    videochat_invite_create_endpoint_assert((bool) ($adminAuth['ok'] ?? false), 'expected valid admin auth context');

    $userAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'POST',
            'uri' => '/api/invite-codes',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
        ],
        'rest'
    );
    videochat_invite_create_endpoint_assert((bool) ($userAuth['ok'] ?? false), 'expected valid user auth context');

    $adminRequestTemplate = [
        'uri' => '/api/invite-codes',
        'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        'remote_address' => '127.0.0.1',
    ];

    $invalidJson = videochat_handle_invite_routes(
        '/api/invite-codes',
        'POST',
        [...$adminRequestTemplate, 'method' => 'POST', 'body' => 'not-json'],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($invalidJson), 'invalid-json invite-create response must be an array');
    videochat_invite_create_endpoint_assert((int) ($invalidJson['status'] ?? 0) === 400, 'invalid-json invite-create status should be 400');
    $invalidJsonPayload = videochat_invite_create_endpoint_decode($invalidJson);
    videochat_invite_create_endpoint_assert(
        (string) (($invalidJsonPayload['error'] ?? [])['code'] ?? '') === 'invite_codes_invalid_request_body',
        'invalid-json invite-create error code mismatch'
    );

    $invalidPayload = videochat_handle_invite_routes(
        '/api/invite-codes',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'scope' => 'tenant',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($invalidPayload), 'invalid-payload invite-create response must be an array');
    videochat_invite_create_endpoint_assert((int) ($invalidPayload['status'] ?? 0) === 422, 'invalid-payload invite-create status should be 422');
    $invalidPayloadBody = videochat_invite_create_endpoint_decode($invalidPayload);
    videochat_invite_create_endpoint_assert(
        (string) (($invalidPayloadBody['error'] ?? [])['code'] ?? '') === 'invite_codes_validation_failed',
        'invalid-payload invite-create error code mismatch'
    );
    videochat_invite_create_endpoint_assert(
        (string) (((($invalidPayloadBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['scope'] ?? '') === 'must_be_room_or_call',
        'invalid-payload invite-create scope field mismatch'
    );

    $callInviteResponse = videochat_handle_invite_routes(
        '/api/invite-codes',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'scope' => 'call',
                'call_id' => $callId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($callInviteResponse), 'call invite-create response must be an array');
    videochat_invite_create_endpoint_assert((int) ($callInviteResponse['status'] ?? 0) === 201, 'call invite-create status should be 201');
    $callInvitePayload = videochat_invite_create_endpoint_decode($callInviteResponse);
    videochat_invite_create_endpoint_assert((string) ($callInvitePayload['status'] ?? '') === 'ok', 'call invite-create payload status mismatch');
    videochat_invite_create_endpoint_assert(
        (string) ((($callInvitePayload['result'] ?? [])['state'] ?? '')) === 'created',
        'call invite-create result state mismatch'
    );
    $callInviteCode = (array) ((($callInvitePayload['result'] ?? [])['invite_code'] ?? []));
    videochat_invite_create_endpoint_assert(
        videochat_invite_create_endpoint_uuid_v4_like((string) ($callInviteCode['id'] ?? '')),
        'call invite id should be uuid-v4'
    );
    videochat_invite_create_endpoint_assert(
        !array_key_exists('code', $callInviteCode),
        'call invite create preview must not expose raw code'
    );
    videochat_invite_create_endpoint_assert(
        ($callInviteCode['secret_available'] ?? true) === false,
        'call invite create preview must mark secret unavailable'
    );
    videochat_invite_create_endpoint_assert((string) ($callInviteCode['scope'] ?? '') === 'call', 'call invite scope mismatch');
    videochat_invite_create_endpoint_assert((string) ($callInviteCode['call_id'] ?? '') === $callId, 'call invite call_id mismatch');
    videochat_invite_create_endpoint_assert(($callInviteCode['room_id'] ?? null) === null, 'call invite room_id must be null');
    videochat_invite_create_endpoint_assert((int) ($callInviteCode['issued_by_user_id'] ?? 0) === $adminUserId, 'call invite issued_by mismatch');
    videochat_invite_create_endpoint_assert((int) ($callInviteCode['max_redemptions'] ?? 0) === 1, 'call invite max_redemptions mismatch');
    videochat_invite_create_endpoint_assert((int) ($callInviteCode['redemption_count'] ?? -1) === 0, 'call invite redemption_count mismatch');
    videochat_invite_create_endpoint_assert(
        (int) ($callInviteCode['expires_in_seconds'] ?? 0) === videochat_invite_scope_ttl_seconds('call'),
        'call invite expires_in_seconds mismatch'
    );
    $expectedCallExpiresAt = gmdate('c', strtotime((string) ($callInviteCode['created_at'] ?? '')) + videochat_invite_scope_ttl_seconds('call'));
    videochat_invite_create_endpoint_assert(
        (string) ($callInviteCode['expires_at'] ?? '') === $expectedCallExpiresAt,
        'call invite expires_at mismatch'
    );
    $callInviteCopyBoundary = (array) (($callInvitePayload['result'] ?? [])['copy'] ?? []);
    videochat_invite_create_endpoint_assert(
        (string) ($callInviteCopyBoundary['endpoint_template'] ?? '') === '/api/invite-codes/{id}/copy',
        'call invite create response must expose copy endpoint template without code'
    );
    $callInviteCopyPath = (string) ($callInviteCopyBoundary['endpoint'] ?? '');
    videochat_invite_create_endpoint_assert(
        $callInviteCopyPath === '/api/invite-codes/' . (string) ($callInviteCode['id'] ?? '') . '/copy',
        'call invite copy endpoint path mismatch'
    );
    $callInviteCopyResponse = videochat_handle_invite_routes(
        $callInviteCopyPath,
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'uri' => $callInviteCopyPath,
            'body' => '',
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($callInviteCopyResponse), 'call invite copy response must be an array');
    videochat_invite_create_endpoint_assert((int) ($callInviteCopyResponse['status'] ?? 0) === 200, 'call invite copy status should be 200');
    $callInviteCopyPayload = videochat_invite_create_endpoint_decode($callInviteCopyResponse);
    videochat_invite_create_endpoint_assert(
        (string) ((($callInviteCopyPayload['result'] ?? [])['state'] ?? '')) === 'copy_ready',
        'call invite copy result state mismatch'
    );
    $callInviteCopyPreview = (array) ((($callInviteCopyPayload['result'] ?? [])['invite_code'] ?? []));
    videochat_invite_create_endpoint_assert(
        !array_key_exists('code', $callInviteCopyPreview),
        'call invite copy preview must not expose raw code'
    );
    $callInviteCopy = (array) ((($callInviteCopyPayload['result'] ?? [])['copy'] ?? []));
    $callInviteSecretCode = (string) ($callInviteCopy['code'] ?? '');
    videochat_invite_create_endpoint_assert(
        videochat_invite_create_endpoint_uuid_v4_like($callInviteSecretCode),
        'call invite copied code should be uuid-v4'
    );
    videochat_invite_create_endpoint_assert(
        (string) ($callInviteCopy['copy_text'] ?? '') === $callInviteSecretCode,
        'call invite copy_text should equal copied code'
    );

    $secondCallInviteResponse = videochat_handle_invite_routes(
        '/api/invite-codes',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'scope' => 'call',
                'call_id' => $callId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($secondCallInviteResponse), 'second call invite-create response must be an array');
    videochat_invite_create_endpoint_assert((int) ($secondCallInviteResponse['status'] ?? 0) === 201, 'second call invite-create status should be 201');
    $secondCallInvitePayload = videochat_invite_create_endpoint_decode($secondCallInviteResponse);
    $secondCallInvitePreview = (array) ((($secondCallInvitePayload['result'] ?? [])['invite_code'] ?? []));
    videochat_invite_create_endpoint_assert(
        !array_key_exists('code', $secondCallInvitePreview),
        'second call invite create preview must not expose raw code'
    );
    $secondCallInviteCopyPath = (string) (((($secondCallInvitePayload['result'] ?? [])['copy'] ?? [])['endpoint'] ?? ''));
    $secondCallInviteCopyResponse = videochat_handle_invite_routes(
        $secondCallInviteCopyPath,
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'uri' => $secondCallInviteCopyPath,
            'body' => '',
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($secondCallInviteCopyResponse), 'second call invite copy response must be an array');
    videochat_invite_create_endpoint_assert((int) ($secondCallInviteCopyResponse['status'] ?? 0) === 200, 'second call invite copy status should be 200');
    $secondCallInviteCopyPayload = videochat_invite_create_endpoint_decode($secondCallInviteCopyResponse);
    $secondCallInviteCode = (string) (((($secondCallInviteCopyPayload['result'] ?? [])['copy'] ?? [])['code'] ?? ''));
    videochat_invite_create_endpoint_assert(
        strtolower($secondCallInviteCode) !== strtolower($callInviteSecretCode),
        'second call invite code must be unique'
    );

    $forbiddenCallInvite = videochat_handle_invite_routes(
        '/api/invite-codes',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/invite-codes',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
            'remote_address' => '127.0.0.1',
            'body' => json_encode([
                'scope' => 'call',
                'call_id' => $callId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $userAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($forbiddenCallInvite), 'forbidden call invite response must be an array');
    videochat_invite_create_endpoint_assert((int) ($forbiddenCallInvite['status'] ?? 0) === 403, 'forbidden call invite status should be 403');
    $forbiddenCallInviteBody = videochat_invite_create_endpoint_decode($forbiddenCallInvite);
    videochat_invite_create_endpoint_assert(
        (string) (($forbiddenCallInviteBody['error'] ?? [])['code'] ?? '') === 'invite_codes_forbidden',
        'forbidden call invite error code mismatch'
    );

    $roomInviteResponse = videochat_handle_invite_routes(
        '/api/invite-codes',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/invite-codes',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
            'remote_address' => '127.0.0.1',
            'body' => json_encode([
                'scope' => 'room',
                'room_id' => 'lobby',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $userAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($roomInviteResponse), 'room invite-create response must be an array');
    videochat_invite_create_endpoint_assert((int) ($roomInviteResponse['status'] ?? 0) === 201, 'room invite-create status should be 201');
    $roomInvitePayload = videochat_invite_create_endpoint_decode($roomInviteResponse);
    $roomInviteCode = (array) ((($roomInvitePayload['result'] ?? [])['invite_code'] ?? []));
    videochat_invite_create_endpoint_assert(!array_key_exists('code', $roomInviteCode), 'room invite create preview must not expose raw code');
    videochat_invite_create_endpoint_assert((string) ($roomInviteCode['scope'] ?? '') === 'room', 'room invite scope mismatch');
    videochat_invite_create_endpoint_assert((string) ($roomInviteCode['room_id'] ?? '') === 'lobby', 'room invite room_id mismatch');
    videochat_invite_create_endpoint_assert(($roomInviteCode['call_id'] ?? null) === null, 'room invite call_id must be null');
    videochat_invite_create_endpoint_assert(
        (int) ($roomInviteCode['expires_in_seconds'] ?? 0) === videochat_invite_scope_ttl_seconds('room'),
        'room invite expires_in_seconds mismatch'
    );

    $invalidExpiryOverrideResponse = videochat_handle_invite_routes(
        '/api/invite-codes',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'scope' => 'room',
                'room_id' => 'lobby',
                'expires_in_seconds' => 10,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($invalidExpiryOverrideResponse), 'invalid expiry override response must be an array');
    videochat_invite_create_endpoint_assert((int) ($invalidExpiryOverrideResponse['status'] ?? 0) === 422, 'invalid expiry override status should be 422');
    $invalidExpiryOverrideBody = videochat_invite_create_endpoint_decode($invalidExpiryOverrideResponse);
    videochat_invite_create_endpoint_assert(
        (string) (((($invalidExpiryOverrideBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['expires_in_seconds'] ?? '') === 'server_managed_expiry_policy',
        'invalid expiry override field mismatch'
    );

    $missingRoomResponse = videochat_handle_invite_routes(
        '/api/invite-codes',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'scope' => 'room',
                'room_id' => 'unknown-room',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($missingRoomResponse), 'missing room response must be an array');
    videochat_invite_create_endpoint_assert((int) ($missingRoomResponse['status'] ?? 0) === 404, 'missing room status should be 404');
    $missingRoomBody = videochat_invite_create_endpoint_decode($missingRoomResponse);
    videochat_invite_create_endpoint_assert(
        (string) (($missingRoomBody['error'] ?? [])['code'] ?? '') === 'invite_codes_not_found',
        'missing room error code mismatch'
    );

    $endedCall = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Ended invite call',
        'starts_at' => '2026-09-01T09:00:00Z',
        'ends_at' => '2026-09-01T10:00:00Z',
    ]);
    videochat_invite_create_endpoint_assert((bool) ($endedCall['ok'] ?? false), 'ended-call setup should succeed');
    $endedCallId = (string) (($endedCall['call'] ?? [])['id'] ?? '');
    $setEnded = $pdo->prepare('UPDATE calls SET status = :status WHERE id = :id');
    $setEnded->execute([
        ':status' => 'ended',
        ':id' => $endedCallId,
    ]);

    $endedCallInvite = videochat_handle_invite_routes(
        '/api/invite-codes',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'scope' => 'call',
                'call_id' => $endedCallId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($endedCallInvite), 'ended-call invite response must be an array');
    videochat_invite_create_endpoint_assert((int) ($endedCallInvite['status'] ?? 0) === 422, 'ended-call invite status should be 422');
    $endedCallInviteBody = videochat_invite_create_endpoint_decode($endedCallInvite);
    videochat_invite_create_endpoint_assert(
        (string) (((($endedCallInviteBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['call_id'] ?? '') === 'call_not_invitable_from_status',
        'ended-call invite field mismatch'
    );

    $callInviteDbRowQuery = $pdo->prepare(
        'SELECT scope, room_id, call_id, max_redemptions, redemption_count, expires_at FROM invite_codes WHERE code = :code LIMIT 1'
    );
    $callInviteDbRowQuery->execute([':code' => $callInviteSecretCode]);
    $callInviteDbRow = $callInviteDbRowQuery->fetch();
    videochat_invite_create_endpoint_assert(is_array($callInviteDbRow), 'call invite db row must exist');
    videochat_invite_create_endpoint_assert((string) ($callInviteDbRow['scope'] ?? '') === 'call', 'call invite db scope mismatch');
    videochat_invite_create_endpoint_assert(($callInviteDbRow['room_id'] ?? null) === null, 'call invite db room_id must be null');
    videochat_invite_create_endpoint_assert((string) ($callInviteDbRow['call_id'] ?? '') === $callId, 'call invite db call_id mismatch');
    videochat_invite_create_endpoint_assert((int) ($callInviteDbRow['max_redemptions'] ?? 0) === 1, 'call invite db max_redemptions mismatch');
    videochat_invite_create_endpoint_assert((int) ($callInviteDbRow['redemption_count'] ?? -1) === 0, 'call invite db redemption_count mismatch');

    $totalInvites = (int) $pdo->query('SELECT COUNT(*) FROM invite_codes')->fetchColumn();
    videochat_invite_create_endpoint_assert($totalInvites === 3, 'expected three persisted invites (2 call + 1 room)');

    $methodNotAllowed = videochat_handle_invite_routes(
        '/api/invite-codes',
        'GET',
        [...$adminRequestTemplate, 'method' => 'GET', 'body' => ''],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($methodNotAllowed), 'method-not-allowed invite-create response must be an array');
    videochat_invite_create_endpoint_assert((int) ($methodNotAllowed['status'] ?? 0) === 405, 'method-not-allowed invite-create status should be 405');

    $invalidContext = videochat_handle_invite_routes(
        '/api/invite-codes',
        'POST',
        [...$adminRequestTemplate, 'method' => 'POST', 'body' => json_encode(['scope' => 'room', 'room_id' => 'lobby'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        ['user' => ['id' => 0, 'role' => 'admin']],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_create_endpoint_assert(is_array($invalidContext), 'invalid-context invite-create response must be an array');
    videochat_invite_create_endpoint_assert((int) ($invalidContext['status'] ?? 0) === 401, 'invalid-context invite-create status should be 401');

    @unlink($databasePath);
    fwrite(STDOUT, "[invite-code-create-endpoint-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[invite-code-create-endpoint-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
