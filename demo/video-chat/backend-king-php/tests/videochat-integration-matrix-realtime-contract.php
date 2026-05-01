<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_chat.php';
require_once __DIR__ . '/../domain/realtime/realtime_typing.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../domain/realtime/realtime_signaling.php';
require_once __DIR__ . '/../domain/realtime/realtime_reaction.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_integration_realtime_fail(string $message): never
{
    fwrite(STDERR, "[videochat-integration-matrix-realtime-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_integration_realtime_assert(bool $condition, string $message): void
{
    if (!$condition) {
        videochat_integration_realtime_fail($message);
    }
}

/**
 * @return array<string, mixed>
 */
function videochat_integration_realtime_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-integration-realtime-matrix-' . bin2hex(random_bytes(6)) . '.sqlite';
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
    videochat_integration_realtime_assert($adminUserId > 0, 'expected seeded admin user');

    $userUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('user@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_integration_realtime_assert($userUserId > 0, 'expected seeded user user');

    $now = time();
    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, :revoked_at, '127.0.0.1', 'videochat-integration-matrix-realtime-contract')
SQL
    );
    $insertSession->execute([
        ':id' => 'sess_rt_valid',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', $now - 30),
        ':expires_at' => gmdate('c', $now + 3600),
        ':revoked_at' => null,
    ]);
    $insertSession->execute([
        ':id' => 'sess_rt_expired',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', $now - 7200),
        ':expires_at' => gmdate('c', $now - 5),
        ':revoked_at' => null,
    ]);
    $insertSession->execute([
        ':id' => 'sess_rt_revoked',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', $now - 7200),
        ':expires_at' => gmdate('c', $now + 3600),
        ':revoked_at' => gmdate('c', $now - 60),
    ]);

    $authenticateRequest = static function (array $request, string $transport) use ($pdo): array {
        return videochat_authenticate_request($pdo, $request, $transport);
    };

    $authCallCount = 0;
    $authenticateRequestCounted = static function (array $request, string $transport) use (&$authCallCount, $pdo): array {
        $authCallCount++;
        return videochat_authenticate_request($pdo, $request, $transport);
    };

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

    $authFailureResponse = static function (string $transport, string $reason) use ($errorResponse): array {
        $code = $transport === 'websocket' ? 'websocket_auth_failed' : 'auth_failed';
        return $errorResponse(401, $code, 'A valid session token is required.', [
            'reason' => $reason,
        ]);
    };

    $rbacFailureResponse = static function (string $transport, array $rbacDecision, string $requestPath) use ($errorResponse): array {
        $code = $transport === 'websocket' ? 'websocket_forbidden' : 'rbac_forbidden';
        return $errorResponse(403, $code, 'Session role is not allowed.', [
            'reason' => (string) ($rbacDecision['reason'] ?? 'role_not_allowed'),
            'rule_id' => (string) ($rbacDecision['rule_id'] ?? 'unknown'),
            'role' => (string) ($rbacDecision['role'] ?? 'unknown'),
            'allowed_roles' => is_array($rbacDecision['allowed_roles'] ?? null) ? array_values($rbacDecision['allowed_roles']) : [],
            'path' => $requestPath,
        ]);
    };

    $openDatabase = static function (): PDO {
        throw new RuntimeException('database access must not happen in realtime fail-closed paths');
    };

    $activeWebsocketsBySession = [];
    $presenceState = [];
    $lobbyState = [];
    $typingState = [];
    $reactionState = [];
    $wsPath = '/ws';
    $validKey = base64_encode(random_bytes(16));
    videochat_integration_realtime_assert(is_string($validKey) && $validKey !== '', 'websocket key generation failed');

    $validRequest = [
        'method' => 'GET',
        'uri' => '/ws?session=sess_rt_valid',
        'path' => '/ws',
        'headers' => [
            'Connection' => 'keep-alive, Upgrade',
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Key' => $validKey,
            'Sec-WebSocket-Version' => '13',
        ],
    ];

    videochat_integration_realtime_assert(
        videochat_realtime_normalize_ws_path('ws') === '/ws',
        'ws path normalization mismatch'
    );
    videochat_integration_realtime_assert(
        videochat_realtime_connection_has_upgrade_token('keep-alive, Upgrade') === true,
        'upgrade token should be detected'
    );
    videochat_integration_realtime_assert(
        videochat_realtime_connection_has_upgrade_token('keep-alive') === false,
        'missing upgrade token should be rejected'
    );

    $validHandshake = videochat_realtime_validate_websocket_handshake($validRequest, $wsPath);
    videochat_integration_realtime_assert((bool) ($validHandshake['ok'] ?? false), 'valid websocket handshake should pass');

    $pathMismatch = videochat_realtime_validate_websocket_handshake(
        ['method' => 'GET', 'uri' => '/socket', 'headers' => []],
        $wsPath
    );
    videochat_integration_realtime_assert(!(bool) ($pathMismatch['ok'] ?? true), 'path mismatch should fail');
    videochat_integration_realtime_assert((int) ($pathMismatch['status'] ?? 0) === 400, 'path mismatch status mismatch');
    videochat_integration_realtime_assert(
        (string) (($pathMismatch['details'] ?? [])['reason'] ?? '') === 'ws_path_mismatch',
        'path mismatch reason mismatch'
    );

    $unsupportedVersion = videochat_realtime_validate_websocket_handshake(
        [
            ...$validRequest,
            'headers' => [
                ...$validRequest['headers'],
                'Sec-WebSocket-Version' => '12',
            ],
        ],
        $wsPath
    );
    videochat_integration_realtime_assert(!(bool) ($unsupportedVersion['ok'] ?? true), 'unsupported version should fail');
    videochat_integration_realtime_assert((int) ($unsupportedVersion['status'] ?? 0) === 426, 'unsupported version status mismatch');

    $adminRoleAllowed = videochat_authorize_role_for_path(['role' => 'admin'], $wsPath);
    videochat_integration_realtime_assert((bool) ($adminRoleAllowed['ok'] ?? false), 'admin should be allowed on websocket path');
    $guestRoleDenied = videochat_authorize_role_for_path(['role' => 'guest'], $wsPath);
    videochat_integration_realtime_assert(!(bool) ($guestRoleDenied['ok'] ?? true), 'guest role should fail closed on websocket path');
    videochat_integration_realtime_assert(
        (string) ($guestRoleDenied['reason'] ?? '') === 'invalid_role',
        'guest websocket role reason mismatch'
    );

    $probeRequest = videochat_realtime_session_probe_request('sess_rt_valid', $wsPath);
    videochat_integration_realtime_assert((string) ($probeRequest['method'] ?? '') === 'GET', 'probe request method mismatch');
    videochat_integration_realtime_assert((string) ($probeRequest['uri'] ?? '') === '/ws', 'probe request uri mismatch');
    videochat_integration_realtime_assert(
        (string) (($probeRequest['headers'] ?? [])['Authorization'] ?? '') === 'Bearer sess_rt_valid',
        'probe request authorization header mismatch'
    );

    $validLiveness = videochat_realtime_validate_session_liveness($authenticateRequest, 'sess_rt_valid', $wsPath);
    videochat_integration_realtime_assert((bool) ($validLiveness['ok'] ?? false), 'valid session should pass realtime liveness');
    videochat_integration_realtime_assert((string) ($validLiveness['reason'] ?? '') === 'ok', 'valid session liveness reason mismatch');

    $expiredLiveness = videochat_realtime_validate_session_liveness($authenticateRequest, 'sess_rt_expired', $wsPath);
    videochat_integration_realtime_assert(!(bool) ($expiredLiveness['ok'] ?? true), 'expired session should fail realtime liveness');
    videochat_integration_realtime_assert((string) ($expiredLiveness['reason'] ?? '') === 'expired_session', 'expired session reason mismatch');

    $revokedLiveness = videochat_realtime_validate_session_liveness($authenticateRequest, 'sess_rt_revoked', $wsPath);
    videochat_integration_realtime_assert(!(bool) ($revokedLiveness['ok'] ?? true), 'revoked session should fail realtime liveness');
    videochat_integration_realtime_assert((string) ($revokedLiveness['reason'] ?? '') === 'revoked_session', 'revoked session reason mismatch');

    $missingLiveness = videochat_realtime_validate_session_liveness($authenticateRequest, '', $wsPath);
    videochat_integration_realtime_assert(!(bool) ($missingLiveness['ok'] ?? true), 'missing session should fail realtime liveness');
    videochat_integration_realtime_assert((string) ($missingLiveness['reason'] ?? '') === 'missing_session', 'missing session liveness reason mismatch');

    $admissionRoomId = 'sfu-admission-room';
    $admissionCallId = 'sfu-admission-call';
    $nowIso = gmdate('c', $now);
    $pdo->prepare(
        <<<'SQL'
INSERT INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at)
VALUES(:id, 'SFU Admission Room', 'private', 'active', :owner_user_id, :created_at, :updated_at)
SQL
    )->execute([
        ':id' => $admissionRoomId,
        ':owner_user_id' => $adminUserId,
        ':created_at' => $nowIso,
        ':updated_at' => $nowIso,
    ]);
    $pdo->prepare(
        <<<'SQL'
INSERT INTO calls(id, room_id, title, owner_user_id, status, starts_at, ends_at, created_at, updated_at)
VALUES(:id, :room_id, 'SFU Admission Call', :owner_user_id, 'active', :starts_at, :ends_at, :created_at, :updated_at)
SQL
    )->execute([
        ':id' => $admissionCallId,
        ':room_id' => $admissionRoomId,
        ':owner_user_id' => $adminUserId,
        ':starts_at' => gmdate('c', $now - 60),
        ':ends_at' => gmdate('c', $now + 3600),
        ':created_at' => $nowIso,
        ':updated_at' => $nowIso,
    ]);
    $insertParticipant = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'internal', :call_role, :invite_state, :joined_at, NULL)
SQL
    );
    $insertParticipant->execute([
        ':call_id' => $admissionCallId,
        ':user_id' => $adminUserId,
        ':email' => 'admin@intelligent-intern.com',
        ':display_name' => 'Platform Admin',
        ':call_role' => 'owner',
        ':invite_state' => 'allowed',
        ':joined_at' => $nowIso,
    ]);
    $insertParticipant->execute([
        ':call_id' => $admissionCallId,
        ':user_id' => $userUserId,
        ':email' => 'user@intelligent-intern.com',
        ':display_name' => 'Call User',
        ':call_role' => 'participant',
        ':invite_state' => 'allowed',
        ':joined_at' => $nowIso,
    ]);
    $openAdmissionDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };
    videochat_integration_realtime_assert(
        videochat_realtime_user_has_sfu_room_admission(
            $openAdmissionDatabase,
            $userUserId,
            'user',
            $admissionRoomId,
            $admissionCallId
        ),
        'SFU admission should accept DB-admitted call participants without process-local /ws presence'
    );
    $pdo->prepare(
        "UPDATE call_participants SET invite_state = 'pending', joined_at = NULL WHERE call_id = :call_id AND user_id = :user_id"
    )->execute([
        ':call_id' => $admissionCallId,
        ':user_id' => $userUserId,
    ]);
    videochat_integration_realtime_assert(
        !videochat_realtime_user_has_sfu_room_admission(
            $openAdmissionDatabase,
            $userUserId,
            'user',
            $admissionRoomId,
            $admissionCallId
        ),
        'SFU admission must still reject pending lobby participants'
    );

    $closeAuthBackend = videochat_realtime_close_descriptor_for_reason('auth_backend_error');
    videochat_integration_realtime_assert((int) ($closeAuthBackend['close_code'] ?? 0) === 1011, 'auth backend close code mismatch');
    videochat_integration_realtime_assert((string) ($closeAuthBackend['close_reason'] ?? '') === 'auth_backend_error', 'auth backend close reason mismatch');
    videochat_integration_realtime_assert((string) ($closeAuthBackend['close_category'] ?? '') === 'internal', 'auth backend close category mismatch');

    $closeRevoked = videochat_realtime_close_descriptor_for_reason('revoked_session');
    videochat_integration_realtime_assert((int) ($closeRevoked['close_code'] ?? 0) === 1008, 'revoked close code mismatch');
    videochat_integration_realtime_assert((string) ($closeRevoked['close_reason'] ?? '') === 'session_invalidated', 'revoked close reason mismatch');
    videochat_integration_realtime_assert((string) ($closeRevoked['close_category'] ?? '') === 'policy', 'revoked close category mismatch');

    $invalidMethodResponse = videochat_handle_realtime_routes(
        $wsPath,
        [...$validRequest, 'method' => 'POST'],
        $wsPath,
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $authenticateRequestCounted,
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_integration_realtime_assert((int) ($invalidMethodResponse['status'] ?? 0) === 405, 'invalid websocket method should fail with 405');
    videochat_integration_realtime_assert($authCallCount === 0, 'auth callback must not run for invalid websocket method');

    $missingUpgradeResponse = videochat_handle_realtime_routes(
        $wsPath,
        [
            ...$validRequest,
            'headers' => [
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => $validKey,
                'Sec-WebSocket-Version' => '13',
            ],
        ],
        $wsPath,
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $authenticateRequestCounted,
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_integration_realtime_assert((int) ($missingUpgradeResponse['status'] ?? 0) === 400, 'missing websocket upgrade should fail with 400');
    videochat_integration_realtime_assert($authCallCount === 0, 'auth callback must not run for missing websocket upgrade');

    $missingSessionResponse = videochat_handle_realtime_routes(
        $wsPath,
        [
            'method' => 'GET',
            'uri' => '/ws',
            'path' => '/ws',
            'headers' => [
                'Connection' => 'keep-alive, Upgrade',
                'Upgrade' => 'websocket',
                'Sec-WebSocket-Key' => $validKey,
                'Sec-WebSocket-Version' => '13',
            ],
        ],
        $wsPath,
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $authenticateRequestCounted,
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_integration_realtime_assert((int) ($missingSessionResponse['status'] ?? 0) === 401, 'missing websocket session should fail with 401');
    $missingSessionBody = videochat_integration_realtime_decode($missingSessionResponse);
    videochat_integration_realtime_assert(
        (string) (($missingSessionBody['error'] ?? [])['code'] ?? '') === 'websocket_auth_failed',
        'missing websocket session error code mismatch'
    );
    videochat_integration_realtime_assert(
        (string) (((($missingSessionBody['error'] ?? [])['details'] ?? [])['reason'] ?? '')) === 'missing_session',
        'missing websocket session reason mismatch'
    );
    videochat_integration_realtime_assert($authCallCount === 1, 'auth callback should run once for missing websocket session');

    $revokedSessionResponse = videochat_handle_realtime_routes(
        $wsPath,
        [
            'method' => 'GET',
            'uri' => '/ws?session=sess_rt_revoked',
            'path' => '/ws',
            'headers' => [
                'Connection' => 'keep-alive, Upgrade',
                'Upgrade' => 'websocket',
                'Sec-WebSocket-Key' => $validKey,
                'Sec-WebSocket-Version' => '13',
            ],
        ],
        $wsPath,
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $authenticateRequestCounted,
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_integration_realtime_assert((int) ($revokedSessionResponse['status'] ?? 0) === 401, 'revoked websocket session should fail with 401');
    $revokedSessionBody = videochat_integration_realtime_decode($revokedSessionResponse);
    videochat_integration_realtime_assert(
        (string) (($revokedSessionBody['error'] ?? [])['code'] ?? '') === 'websocket_auth_failed',
        'revoked websocket session error code mismatch'
    );
    videochat_integration_realtime_assert(
        (string) (((($revokedSessionBody['error'] ?? [])['details'] ?? [])['reason'] ?? '')) === 'revoked_session',
        'revoked websocket session reason mismatch'
    );
    videochat_integration_realtime_assert($authCallCount === 2, 'auth callback should run twice after revoked websocket session');

    $expiredSessionResponse = videochat_handle_realtime_routes(
        $wsPath,
        [
            'method' => 'GET',
            'uri' => '/ws?session=sess_rt_expired',
            'path' => '/ws',
            'headers' => [
                'Connection' => 'keep-alive, Upgrade',
                'Upgrade' => 'websocket',
                'Sec-WebSocket-Key' => $validKey,
                'Sec-WebSocket-Version' => '13',
            ],
        ],
        $wsPath,
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $authenticateRequestCounted,
        $authFailureResponse,
        $rbacFailureResponse,
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_integration_realtime_assert((int) ($expiredSessionResponse['status'] ?? 0) === 401, 'expired websocket session should fail with 401');
    $expiredSessionBody = videochat_integration_realtime_decode($expiredSessionResponse);
    videochat_integration_realtime_assert(
        (string) (($expiredSessionBody['error'] ?? [])['code'] ?? '') === 'websocket_auth_failed',
        'expired websocket session error code mismatch'
    );
    videochat_integration_realtime_assert(
        (string) (((($expiredSessionBody['error'] ?? [])['details'] ?? [])['reason'] ?? '')) === 'expired_session',
        'expired websocket session reason mismatch'
    );
    videochat_integration_realtime_assert($authCallCount === 3, 'auth callback should run three times after expired websocket session');

    @unlink($databasePath);
    fwrite(STDOUT, "[videochat-integration-matrix-realtime-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[videochat-integration-matrix-realtime-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
