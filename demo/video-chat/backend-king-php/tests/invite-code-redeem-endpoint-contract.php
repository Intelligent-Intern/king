<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/invite_codes.php';
require_once __DIR__ . '/../http/module_invites.php';

function videochat_invite_redeem_endpoint_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[invite-code-redeem-endpoint-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_invite_redeem_endpoint_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

function videochat_invite_redeem_endpoint_uuid_v4_like(string $value): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        strtolower($value)
    ) === 1;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-invite-redeem-endpoint-' . bin2hex(random_bytes(6)) . '.sqlite';
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
    videochat_invite_redeem_endpoint_assert($adminUserId > 0, 'expected seeded admin user');

    $standardUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('user@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_invite_redeem_endpoint_assert($standardUserId > 0, 'expected seeded standard user');

    $createCall = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Invite Redeem Endpoint Contract Call',
        'starts_at' => '2026-08-01T09:00:00Z',
        'ends_at' => '2026-08-01T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
        'external_participants' => [],
    ]);
    videochat_invite_redeem_endpoint_assert((bool) ($createCall['ok'] ?? false), 'call create should succeed for redeem endpoint contract');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_invite_redeem_endpoint_assert($callId !== '', 'call id must be present');

    $adminSessionId = 'sess_invite_redeem_endpoint_admin';
    $userSessionId = 'sess_invite_redeem_endpoint_user';
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
        ':user_agent' => 'invite-redeem-endpoint-contract-admin',
    ]);
    $insertSession->execute([
        ':id' => $userSessionId,
        ':user_id' => $standardUserId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'invite-redeem-endpoint-contract-user',
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
            'uri' => '/api/invite-codes/redeem',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        ],
        'rest'
    );
    videochat_invite_redeem_endpoint_assert((bool) ($adminAuth['ok'] ?? false), 'expected valid admin auth context');

    $userAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'POST',
            'uri' => '/api/invite-codes/redeem',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
        ],
        'rest'
    );
    videochat_invite_redeem_endpoint_assert((bool) ($userAuth['ok'] ?? false), 'expected valid user auth context');

    $adminRequestTemplate = [
        'uri' => '/api/invite-codes/redeem',
        'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        'remote_address' => '127.0.0.1',
    ];
    $userRequestTemplate = [
        'uri' => '/api/invite-codes/redeem',
        'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
        'remote_address' => '127.0.0.1',
    ];

    $invalidJson = videochat_handle_invite_routes(
        '/api/invite-codes/redeem',
        'POST',
        [...$adminRequestTemplate, 'method' => 'POST', 'body' => 'not-json'],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_redeem_endpoint_assert(is_array($invalidJson), 'invalid-json invite-redeem response must be an array');
    videochat_invite_redeem_endpoint_assert((int) ($invalidJson['status'] ?? 0) === 400, 'invalid-json invite-redeem status should be 400');
    $invalidJsonPayload = videochat_invite_redeem_endpoint_decode($invalidJson);
    videochat_invite_redeem_endpoint_assert(
        (string) (($invalidJsonPayload['error'] ?? [])['code'] ?? '') === 'invite_codes_redeem_invalid_request_body',
        'invalid-json invite-redeem error code mismatch'
    );

    $invalidPayload = videochat_handle_invite_routes(
        '/api/invite-codes/redeem',
        'POST',
        [...$adminRequestTemplate, 'method' => 'POST', 'body' => json_encode([], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_redeem_endpoint_assert(is_array($invalidPayload), 'invalid-payload invite-redeem response must be an array');
    videochat_invite_redeem_endpoint_assert((int) ($invalidPayload['status'] ?? 0) === 422, 'invalid-payload invite-redeem status should be 422');
    $invalidPayloadBody = videochat_invite_redeem_endpoint_decode($invalidPayload);
    videochat_invite_redeem_endpoint_assert(
        (string) (($invalidPayloadBody['error'] ?? [])['code'] ?? '') === 'invite_codes_redeem_validation_failed',
        'invalid-payload invite-redeem error code mismatch'
    );
    videochat_invite_redeem_endpoint_assert(
        (string) (((($invalidPayloadBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['code'] ?? '') === 'required_code',
        'invalid-payload invite-redeem field mismatch'
    );

    $unknownCode = videochat_generate_uuid_v4();
    $unknownCodeResponse = videochat_handle_invite_routes(
        '/api/invite-codes/redeem',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'body' => json_encode(['code' => $unknownCode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_redeem_endpoint_assert(is_array($unknownCodeResponse), 'unknown-code invite-redeem response must be an array');
    videochat_invite_redeem_endpoint_assert((int) ($unknownCodeResponse['status'] ?? 0) === 404, 'unknown-code invite-redeem status should be 404');
    $unknownCodePayload = videochat_invite_redeem_endpoint_decode($unknownCodeResponse);
    videochat_invite_redeem_endpoint_assert(
        (string) (($unknownCodePayload['error'] ?? [])['code'] ?? '') === 'invite_codes_redeem_not_found',
        'unknown-code invite-redeem error code mismatch'
    );
    videochat_invite_redeem_endpoint_assert(
        (string) (((($unknownCodePayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['code'] ?? '') === 'invite_code_not_found',
        'unknown-code invite-redeem field mismatch'
    );

    $fixedNow = 1_781_006_400;
    $roomInvite = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'room',
        'room_id' => 'lobby',
    ], $fixedNow);
    videochat_invite_redeem_endpoint_assert((bool) ($roomInvite['ok'] ?? false), 'room invite create should succeed');
    $roomInviteCode = (string) (($roomInvite['invite_code'] ?? [])['code'] ?? '');
    videochat_invite_redeem_endpoint_assert(videochat_invite_redeem_endpoint_uuid_v4_like($roomInviteCode), 'room invite code must be uuid-v4');

    $roomRedeem = videochat_handle_invite_routes(
        '/api/invite-codes/redeem',
        'POST',
        [
            ...$userRequestTemplate,
            'method' => 'POST',
            'body' => json_encode(['code' => strtoupper($roomInviteCode)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $userAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_redeem_endpoint_assert(is_array($roomRedeem), 'room redeem response must be an array');
    videochat_invite_redeem_endpoint_assert((int) ($roomRedeem['status'] ?? 0) === 200, 'room redeem status should be 200');
    $roomRedeemPayload = videochat_invite_redeem_endpoint_decode($roomRedeem);
    videochat_invite_redeem_endpoint_assert((string) ($roomRedeemPayload['status'] ?? '') === 'ok', 'room redeem status envelope mismatch');
    videochat_invite_redeem_endpoint_assert(
        (string) ((($roomRedeemPayload['result'] ?? [])['state'] ?? '')) === 'redeemed',
        'room redeem result state mismatch'
    );
    $roomRedemption = (array) (($roomRedeemPayload['result'] ?? [])['redemption'] ?? []);
    $roomInvitePayload = (array) ($roomRedemption['invite_code'] ?? []);
    $roomJoinContext = (array) ($roomRedemption['join_context'] ?? []);
    $roomJoinRoom = (array) ($roomJoinContext['room'] ?? []);
    $roomJoinRequestUser = (array) ($roomJoinContext['request_user'] ?? []);
    videochat_invite_redeem_endpoint_assert((string) ($roomInvitePayload['scope'] ?? '') === 'room', 'room redeem invite scope mismatch');
    videochat_invite_redeem_endpoint_assert((string) ($roomInvitePayload['code'] ?? '') === strtolower($roomInviteCode), 'room redeem invite code mismatch');
    videochat_invite_redeem_endpoint_assert((int) ($roomInvitePayload['redemption_count'] ?? -1) === 1, 'room redeem redemption_count should be 1');
    videochat_invite_redeem_endpoint_assert((int) ($roomInvitePayload['remaining_redemptions'] ?? -1) === 0, 'room redeem remaining_redemptions should be 0');
    videochat_invite_redeem_endpoint_assert((string) ($roomJoinContext['scope'] ?? '') === 'room', 'room join context scope mismatch');
    videochat_invite_redeem_endpoint_assert((string) ($roomJoinRoom['id'] ?? '') === 'lobby', 'room join context room id mismatch');
    videochat_invite_redeem_endpoint_assert(($roomJoinContext['call'] ?? null) === null, 'room join context call must be null');
    videochat_invite_redeem_endpoint_assert((int) ($roomJoinRequestUser['user_id'] ?? 0) === $standardUserId, 'room join context request user mismatch');
    videochat_invite_redeem_endpoint_assert((string) ($roomJoinRequestUser['role'] ?? '') === 'user', 'room join context request role mismatch');

    $roomInviteRow = $pdo->prepare(
        'SELECT redemption_count, redeemed_by_user_id FROM invite_codes WHERE lower(code) = :code LIMIT 1'
    );
    $roomInviteRow->execute([':code' => strtolower($roomInviteCode)]);
    $roomInviteRowData = $roomInviteRow->fetch();
    videochat_invite_redeem_endpoint_assert(is_array($roomInviteRowData), 'redeemed room invite row must exist');
    videochat_invite_redeem_endpoint_assert((int) ($roomInviteRowData['redemption_count'] ?? -1) === 1, 'room invite row redemption_count mismatch');
    videochat_invite_redeem_endpoint_assert((int) ($roomInviteRowData['redeemed_by_user_id'] ?? 0) === $standardUserId, 'room invite row redeemed_by mismatch');

    $roomRedeemAgain = videochat_handle_invite_routes(
        '/api/invite-codes/redeem',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'body' => json_encode(['code' => $roomInviteCode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_redeem_endpoint_assert(is_array($roomRedeemAgain), 'redeem-again invite-redeem response must be an array');
    videochat_invite_redeem_endpoint_assert((int) ($roomRedeemAgain['status'] ?? 0) === 409, 'redeem-again invite-redeem status should be 409');
    $roomRedeemAgainPayload = videochat_invite_redeem_endpoint_decode($roomRedeemAgain);
    videochat_invite_redeem_endpoint_assert(
        (string) (($roomRedeemAgainPayload['error'] ?? [])['code'] ?? '') === 'invite_codes_redeem_exhausted',
        'redeem-again invite-redeem error code mismatch'
    );

    $callInvite = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'call',
        'call_id' => $callId,
    ], $fixedNow + 30);
    videochat_invite_redeem_endpoint_assert((bool) ($callInvite['ok'] ?? false), 'call invite create should succeed');
    $callInviteCode = (string) (($callInvite['invite_code'] ?? [])['code'] ?? '');
    videochat_invite_redeem_endpoint_assert(videochat_invite_redeem_endpoint_uuid_v4_like($callInviteCode), 'call invite code must be uuid-v4');

    $callRedeem = videochat_handle_invite_routes(
        '/api/invite-codes/redeem',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'body' => json_encode(['code' => $callInviteCode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_redeem_endpoint_assert(is_array($callRedeem), 'call redeem response must be an array');
    videochat_invite_redeem_endpoint_assert((int) ($callRedeem['status'] ?? 0) === 200, 'call redeem status should be 200');
    $callRedeemPayload = videochat_invite_redeem_endpoint_decode($callRedeem);
    $callRedemption = (array) (($callRedeemPayload['result'] ?? [])['redemption'] ?? []);
    $callJoinContext = (array) ($callRedemption['join_context'] ?? []);
    $callJoinCall = (array) ($callJoinContext['call'] ?? []);
    videochat_invite_redeem_endpoint_assert((string) ($callJoinContext['scope'] ?? '') === 'call', 'call join context scope mismatch');
    videochat_invite_redeem_endpoint_assert((string) ($callJoinCall['id'] ?? '') === $callId, 'call join context call id mismatch');
    videochat_invite_redeem_endpoint_assert((string) ($callJoinCall['status'] ?? '') === 'scheduled', 'call join context status mismatch');

    $expiredInvite = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'room',
        'room_id' => 'lobby',
    ], $fixedNow + 100);
    videochat_invite_redeem_endpoint_assert((bool) ($expiredInvite['ok'] ?? false), 'expired invite setup create should succeed');
    $expiredInviteCode = (string) (($expiredInvite['invite_code'] ?? [])['code'] ?? '');
    $markExpired = $pdo->prepare('UPDATE invite_codes SET expires_at = :expires_at WHERE lower(code) = :code');
    $markExpired->execute([
        ':expires_at' => gmdate('c', time() - 30),
        ':code' => strtolower($expiredInviteCode),
    ]);

    $expiredRedeem = videochat_handle_invite_routes(
        '/api/invite-codes/redeem',
        'POST',
        [
            ...$userRequestTemplate,
            'method' => 'POST',
            'body' => json_encode(['code' => $expiredInviteCode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $userAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_redeem_endpoint_assert(is_array($expiredRedeem), 'expired invite-redeem response must be an array');
    videochat_invite_redeem_endpoint_assert((int) ($expiredRedeem['status'] ?? 0) === 410, 'expired invite-redeem status should be 410');
    $expiredRedeemPayload = videochat_invite_redeem_endpoint_decode($expiredRedeem);
    videochat_invite_redeem_endpoint_assert(
        (string) (($expiredRedeemPayload['error'] ?? [])['code'] ?? '') === 'invite_codes_redeem_expired',
        'expired invite-redeem error code mismatch'
    );
    videochat_invite_redeem_endpoint_assert(
        (string) (((($expiredRedeemPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['code'] ?? '') === 'invite_code_expired',
        'expired invite-redeem field mismatch'
    );

    $cancelledCall = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Cancelled Redeem Endpoint Contract Call',
        'starts_at' => '2026-08-01T11:00:00Z',
        'ends_at' => '2026-08-01T12:00:00Z',
    ]);
    videochat_invite_redeem_endpoint_assert((bool) ($cancelledCall['ok'] ?? false), 'cancelled call setup should succeed');
    $cancelledCallId = (string) (($cancelledCall['call'] ?? [])['id'] ?? '');
    videochat_invite_redeem_endpoint_assert($cancelledCallId !== '', 'cancelled call id should be present');

    $cancelledCallInvite = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'call',
        'call_id' => $cancelledCallId,
    ], $fixedNow + 150);
    videochat_invite_redeem_endpoint_assert((bool) ($cancelledCallInvite['ok'] ?? false), 'cancelled call invite setup should succeed');
    $cancelledCallInviteCode = (string) (($cancelledCallInvite['invite_code'] ?? [])['code'] ?? '');
    $markCancelledCall = $pdo->prepare('UPDATE calls SET status = :status WHERE id = :id');
    $markCancelledCall->execute([
        ':status' => 'cancelled',
        ':id' => $cancelledCallId,
    ]);

    $cancelledCallRedeem = videochat_handle_invite_routes(
        '/api/invite-codes/redeem',
        'POST',
        [
            ...$userRequestTemplate,
            'method' => 'POST',
            'body' => json_encode(['code' => $cancelledCallInviteCode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $userAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_redeem_endpoint_assert(is_array($cancelledCallRedeem), 'cancelled-call invite-redeem response must be an array');
    videochat_invite_redeem_endpoint_assert((int) ($cancelledCallRedeem['status'] ?? 0) === 409, 'cancelled-call invite-redeem status should be 409');
    $cancelledCallRedeemPayload = videochat_invite_redeem_endpoint_decode($cancelledCallRedeem);
    videochat_invite_redeem_endpoint_assert(
        (string) (($cancelledCallRedeemPayload['error'] ?? [])['code'] ?? '') === 'invite_codes_redeem_conflict',
        'cancelled-call invite-redeem error code mismatch'
    );
    videochat_invite_redeem_endpoint_assert(
        (string) (((($cancelledCallRedeemPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['call_id'] ?? '') === 'call_not_joinable_from_status',
        'cancelled-call invite-redeem field mismatch'
    );

    $inactiveRoomInvite = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'room',
        'room_id' => 'lobby',
    ], $fixedNow + 200);
    videochat_invite_redeem_endpoint_assert((bool) ($inactiveRoomInvite['ok'] ?? false), 'inactive-room invite setup should succeed');
    $inactiveRoomInviteCode = (string) (($inactiveRoomInvite['invite_code'] ?? [])['code'] ?? '');
    $markLobbyInactive = $pdo->prepare('UPDATE rooms SET status = :status WHERE id = :id');
    $markLobbyInactive->execute([
        ':status' => 'inactive',
        ':id' => 'lobby',
    ]);

    $inactiveRoomRedeem = videochat_handle_invite_routes(
        '/api/invite-codes/redeem',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'body' => json_encode(['code' => $inactiveRoomInviteCode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_redeem_endpoint_assert(is_array($inactiveRoomRedeem), 'inactive-room invite-redeem response must be an array');
    videochat_invite_redeem_endpoint_assert((int) ($inactiveRoomRedeem['status'] ?? 0) === 404, 'inactive-room invite-redeem status should be 404');
    $inactiveRoomRedeemPayload = videochat_invite_redeem_endpoint_decode($inactiveRoomRedeem);
    videochat_invite_redeem_endpoint_assert(
        (string) (($inactiveRoomRedeemPayload['error'] ?? [])['code'] ?? '') === 'invite_codes_redeem_not_found',
        'inactive-room invite-redeem error code mismatch'
    );
    videochat_invite_redeem_endpoint_assert(
        (string) (((($inactiveRoomRedeemPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['room_id'] ?? '') === 'room_not_found_or_inactive',
        'inactive-room invite-redeem field mismatch'
    );

    $methodNotAllowed = videochat_handle_invite_routes(
        '/api/invite-codes/redeem',
        'GET',
        [
            ...$adminRequestTemplate,
            'method' => 'GET',
            'body' => '',
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_redeem_endpoint_assert(is_array($methodNotAllowed), 'method-not-allowed invite-redeem response must be an array');
    videochat_invite_redeem_endpoint_assert((int) ($methodNotAllowed['status'] ?? 0) === 405, 'method-not-allowed invite-redeem status should be 405');
    $methodNotAllowedPayload = videochat_invite_redeem_endpoint_decode($methodNotAllowed);
    videochat_invite_redeem_endpoint_assert(
        (string) (($methodNotAllowedPayload['error'] ?? [])['code'] ?? '') === 'method_not_allowed',
        'method-not-allowed invite-redeem error code mismatch'
    );

    $invalidAuth = videochat_handle_invite_routes(
        '/api/invite-codes/redeem',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'body' => json_encode(['code' => videochat_generate_uuid_v4()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        [
            'ok' => false,
            'reason' => 'invalid',
            'user' => ['id' => 0, 'role' => 'user'],
        ],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_redeem_endpoint_assert(is_array($invalidAuth), 'invalid-auth invite-redeem response must be an array');
    videochat_invite_redeem_endpoint_assert((int) ($invalidAuth['status'] ?? 0) === 401, 'invalid-auth invite-redeem status should be 401');
    $invalidAuthPayload = videochat_invite_redeem_endpoint_decode($invalidAuth);
    videochat_invite_redeem_endpoint_assert(
        (string) (($invalidAuthPayload['error'] ?? [])['code'] ?? '') === 'auth_failed',
        'invalid-auth invite-redeem error code mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[invite-code-redeem-endpoint-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[invite-code-redeem-endpoint-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
