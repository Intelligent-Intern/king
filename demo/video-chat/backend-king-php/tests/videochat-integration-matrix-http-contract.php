<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_directory.php';
require_once __DIR__ . '/../domain/calls/invite_codes.php';
require_once __DIR__ . '/../http/router.php';

function videochat_integration_http_fail(string $message): never
{
    fwrite(STDERR, "[videochat-integration-matrix-http-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_integration_http_assert(bool $condition, string $message): void
{
    if (!$condition) {
        videochat_integration_http_fail($message);
    }
}

/**
 * @return array<string, mixed>
 */
function videochat_integration_http_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

/**
 * @return array{email: string, display_name: string, role: string, password: string, time_format: string, theme: string}
 */
function videochat_integration_http_demo_user(string $role): array
{
    foreach (videochat_demo_user_blueprint() as $candidate) {
        if ((string) ($candidate['role'] ?? '') === $role) {
            return $candidate;
        }
    }

    videochat_integration_http_fail("demo user blueprint missing role {$role}");
}

function videochat_integration_http_uuid_v4_like(string $value): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        strtolower($value)
    ) === 1;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-integration-http-matrix-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUser = videochat_integration_http_demo_user('admin');
    $standardUser = videochat_integration_http_demo_user('user');

    $sessionIds = [
        'sess_http_admin_1',
        'sess_http_user_1',
        'sess_http_admin_rotated',
    ];
    $issueSessionId = static function () use (&$sessionIds): string {
        if ($sessionIds !== []) {
            return (string) array_shift($sessionIds);
        }

        return 'sess_http_' . bin2hex(random_bytes(12));
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

    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? null;
        if (!is_string($body) || trim($body) === '') {
            return [null, 'missing_body'];
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [null, 'invalid_json'];
        }

        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json_type'];
    };

    $methodFromRequest = static fn (array $request): string => strtoupper(trim((string) ($request['method'] ?? 'GET')));
    $pathFromRequest = static function (array $request): string {
        $path = $request['path'] ?? null;
        if (is_string($path) && $path !== '') {
            return $path;
        }

        $uri = $request['uri'] ?? null;
        if (is_string($uri) && $uri !== '') {
            return (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        }

        return '/';
    };
    $runtimeEnvelope = static function (): array {
        return [
            'service' => 'video-chat-backend-king-php',
            'app' => 'videochat-integration-matrix',
            'runtime' => [
                'king_version' => 'integration',
                'health' => [
                    'build' => 'integration',
                    'module_version' => 'integration',
                ],
            ],
            'time' => gmdate('c'),
        ];
    };

    $activeWebsocketsBySession = [];
    $presenceState = [];
    $lobbyState = [];
    $typingState = [];
    $reactionState = [];
    $avatarStorageRoot = sys_get_temp_dir() . '/videochat-integration-http-avatar-' . bin2hex(random_bytes(4));
    $avatarMaxBytes = 1024 * 1024;

    $dispatch = static function (
        string $method,
        string $path,
        ?string $body = null,
        ?string $sessionToken = null,
        array $extraHeaders = []
    ) use (
        &$activeWebsocketsBySession,
        &$presenceState,
        &$lobbyState,
        &$typingState,
        &$reactionState,
        $jsonResponse,
        $errorResponse,
        $methodFromRequest,
        $decodeJsonBody,
        $pdo,
        $issueSessionId,
        $pathFromRequest,
        $runtimeEnvelope,
        $avatarStorageRoot,
        $avatarMaxBytes
    ): array {
        $headers = $extraHeaders;
        if (is_string($sessionToken) && trim($sessionToken) !== '') {
            $headers['Authorization'] = 'Bearer ' . trim($sessionToken);
        }

        $request = [
            'method' => strtoupper($method),
            'uri' => $path,
            'path' => (string) (parse_url($path, PHP_URL_PATH) ?: '/'),
            'headers' => $headers,
            'remote_address' => '127.0.0.1',
        ];
        if ($body !== null) {
            $request['body'] = $body;
        }

        return videochat_dispatch_request(
            $request,
            $activeWebsocketsBySession,
            $presenceState,
            $lobbyState,
            $typingState,
            $reactionState,
            $jsonResponse,
            $errorResponse,
            $methodFromRequest,
            $decodeJsonBody,
            static fn (): PDO => $pdo,
            $issueSessionId,
            $pathFromRequest,
            $runtimeEnvelope,
            '/ws',
            $avatarStorageRoot,
            $avatarMaxBytes
        );
    };

    $login = static function (array $user) use ($dispatch): array {
        $response = $dispatch(
            'POST',
            '/api/auth/login',
            json_encode([
                'email' => $user['email'],
                'password' => $user['password'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        videochat_integration_http_assert((int) ($response['status'] ?? 0) === 200, sprintf('%s login should succeed', (string) $user['role']));
        $payload = videochat_integration_http_decode($response);
        videochat_integration_http_assert((string) ($payload['status'] ?? '') === 'ok', sprintf('%s login payload should be ok', (string) $user['role']));
        videochat_integration_http_assert((string) (($payload['user'] ?? [])['role'] ?? '') === (string) $user['role'], sprintf('%s login role mismatch', (string) $user['role']));

        $sessionId = (string) (($payload['session'] ?? [])['id'] ?? '');
        videochat_integration_http_assert($sessionId !== '', sprintf('%s login should return a session id', (string) $user['role']));

        return [
            'response' => $response,
            'payload' => $payload,
            'session_id' => $sessionId,
        ];
    };

    $invalidLogin = $dispatch('POST', '/api/auth/login', 'not-json');
    videochat_integration_http_assert((int) ($invalidLogin['status'] ?? 0) === 400, 'invalid login should fail closed with 400');
    videochat_integration_http_assert(
        (string) ((videochat_integration_http_decode($invalidLogin)['error'] ?? [])['code'] ?? '') === 'auth_invalid_request_body',
        'invalid login error code mismatch'
    );

    $getLogin = $dispatch('GET', '/api/auth/login');
    videochat_integration_http_assert((int) ($getLogin['status'] ?? 0) === 405, 'GET login should be rejected');
    videochat_integration_http_assert(
        (string) ((videochat_integration_http_decode($getLogin)['error'] ?? [])['code'] ?? '') === 'method_not_allowed',
        'GET login error code mismatch'
    );

    $missingSession = $dispatch('GET', '/api/auth/session');
    videochat_integration_http_assert((int) ($missingSession['status'] ?? 0) === 401, 'missing auth/session call should fail closed');
    videochat_integration_http_assert(
        (string) (((videochat_integration_http_decode($missingSession)['error'] ?? [])['details'] ?? [])['reason'] ?? '') === 'missing_session',
        'missing auth/session reason mismatch'
    );

    $adminLogin = $login($adminUser);
    $userLogin = $login($standardUser);

    $adminSessionId = (string) $adminLogin['session_id'];
    $userSessionId = (string) $userLogin['session_id'];

    $adminSessionResponse = $dispatch('GET', '/api/auth/session', null, $adminSessionId);
    videochat_integration_http_assert((int) ($adminSessionResponse['status'] ?? 0) === 200, 'admin session lookup should succeed');
    $adminSessionBody = videochat_integration_http_decode($adminSessionResponse);
    videochat_integration_http_assert((string) (($adminSessionBody['user'] ?? [])['role'] ?? '') === 'admin', 'admin session role mismatch');
    videochat_integration_http_assert((string) (($adminSessionBody['session'] ?? [])['id'] ?? '') === $adminSessionId, 'admin session id mismatch');

    $adminPing = $dispatch('GET', '/api/admin/ping', null, $adminSessionId);
    videochat_integration_http_assert((int) ($adminPing['status'] ?? 0) === 200, 'admin ping should succeed');

    $userAdminDenied = $dispatch('GET', '/api/admin/ping', null, $userSessionId);
    videochat_integration_http_assert((int) ($userAdminDenied['status'] ?? 0) === 403, 'user admin ping should be forbidden');
    videochat_integration_http_assert(
        (string) ((videochat_integration_http_decode($userAdminDenied)['error'] ?? [])['code'] ?? '') === 'rbac_forbidden',
        'user/admin deny error code mismatch'
    );

    $adminModerationPing = $dispatch('GET', '/api/moderation/ping', null, $adminSessionId);
    videochat_integration_http_assert((int) ($adminModerationPing['status'] ?? 0) === 200, 'admin moderation ping should succeed after global moderator role removal');

    $userModerationDenied = $dispatch('GET', '/api/moderation/ping', null, $userSessionId);
    videochat_integration_http_assert((int) ($userModerationDenied['status'] ?? 0) === 403, 'user moderation ping should be forbidden');

    $userPing = $dispatch('GET', '/api/user/ping', null, $userSessionId);
    videochat_integration_http_assert((int) ($userPing['status'] ?? 0) === 200, 'user ping should succeed');

    $invalidCallCreate = $dispatch('POST', '/api/calls', 'not-json', $adminSessionId);
    videochat_integration_http_assert((int) ($invalidCallCreate['status'] ?? 0) === 400, 'invalid call create should fail closed with 400');
    videochat_integration_http_assert(
        (string) ((videochat_integration_http_decode($invalidCallCreate)['error'] ?? [])['code'] ?? '') === 'calls_create_invalid_request_body',
        'invalid call create error code mismatch'
    );

    $callCreateResponse = $dispatch(
        'POST',
        '/api/calls',
        json_encode([
            'room_id' => 'lobby',
            'title' => 'Integration Matrix Call',
            'starts_at' => '2026-10-01T09:00:00Z',
            'ends_at' => '2026-10-01T10:00:00Z',
            'internal_participant_user_ids' => [(int) $userLogin['payload']['user']['id']],
            'external_participants' => [
                ['email' => 'guest-a.integration@example.com', 'display_name' => 'Guest A'],
                ['email' => 'guest-b.integration@example.com', 'display_name' => 'Guest B'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $adminSessionId
    );
    videochat_integration_http_assert((int) ($callCreateResponse['status'] ?? 0) === 201, 'call create should succeed');
    $callCreateBody = videochat_integration_http_decode($callCreateResponse);
    videochat_integration_http_assert((string) ($callCreateBody['status'] ?? '') === 'ok', 'call create payload status mismatch');
    $createdCall = is_array($callCreateBody['result']['call'] ?? null) ? $callCreateBody['result']['call'] : [];
    $callId = (string) ($createdCall['id'] ?? '');
    videochat_integration_http_assert($callId !== '', 'call create should return a call id');
    videochat_integration_http_assert((string) ($createdCall['title'] ?? '') === 'Integration Matrix Call', 'created call title mismatch');
    videochat_integration_http_assert((string) ($createdCall['status'] ?? '') === 'scheduled', 'created call status mismatch');
    videochat_integration_http_assert(
        (int) (((($createdCall['participants'] ?? [])['totals'] ?? [])['total'] ?? 0)) === 4,
        'created call total participant mismatch'
    );

    $callRow = $pdo->prepare('SELECT id, owner_user_id, status, title FROM calls WHERE id = :id LIMIT 1');
    $callRow->execute([':id' => $callId]);
    $callRowData = $callRow->fetch();
    videochat_integration_http_assert(is_array($callRowData), 'created call row should exist');
    videochat_integration_http_assert((string) ($callRowData['title'] ?? '') === 'Integration Matrix Call', 'persisted call title mismatch');
    videochat_integration_http_assert((int) ($callRowData['owner_user_id'] ?? 0) === (int) $adminLogin['payload']['user']['id'], 'persisted call owner mismatch');

    $participantCountQuery = $pdo->prepare('SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id');
    $participantCountQuery->execute([':call_id' => $callId]);
    $participantCount = (int) $participantCountQuery->fetchColumn();
    videochat_integration_http_assert($participantCount === 4, 'created call participant count mismatch');

    $callsList = $dispatch('GET', '/api/calls?scope=my&page=1&page_size=10', null, $adminSessionId);
    videochat_integration_http_assert((int) ($callsList['status'] ?? 0) === 200, 'calls list should succeed');
    $callsListBody = videochat_integration_http_decode($callsList);
    videochat_integration_http_assert((int) (($callsListBody['pagination'] ?? [])['total'] ?? 0) === 1, 'calls list total mismatch');
    videochat_integration_http_assert((string) (($callsListBody['calls'][0]['id'] ?? '')) === $callId, 'calls list should return created call');

    $cancelForbidden = $dispatch(
        'POST',
        '/api/calls/' . $callId . '/cancel',
        json_encode([
            'cancel_reason' => 'policy',
            'cancel_message' => 'User should not be able to cancel this call.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $userSessionId
    );
    videochat_integration_http_assert((int) ($cancelForbidden['status'] ?? 0) === 403, 'user cancel should be forbidden');
    videochat_integration_http_assert(
        (string) ((videochat_integration_http_decode($cancelForbidden)['error'] ?? [])['code'] ?? '') === 'calls_forbidden',
        'user cancel forbidden error code mismatch'
    );

    $callInviteCreate = $dispatch(
        'POST',
        '/api/invite-codes',
        json_encode([
            'scope' => 'call',
            'call_id' => $callId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $adminSessionId
    );
    videochat_integration_http_assert((int) ($callInviteCreate['status'] ?? 0) === 201, 'call invite create should succeed');
    $callInviteCreateBody = videochat_integration_http_decode($callInviteCreate);
    $callInvite = is_array($callInviteCreateBody['result']['invite_code'] ?? null) ? $callInviteCreateBody['result']['invite_code'] : [];
    $inviteId = (string) ($callInvite['id'] ?? '');
    videochat_integration_http_assert(videochat_integration_http_uuid_v4_like($inviteId), 'call invite id should be uuid-v4');
    videochat_integration_http_assert(!array_key_exists('code', $callInvite), 'call invite create preview must not expose raw code');
    videochat_integration_http_assert((string) ($callInvite['scope'] ?? '') === 'call', 'call invite scope mismatch');
    videochat_integration_http_assert((string) ($callInvite['call_id'] ?? '') === $callId, 'call invite call_id mismatch');
    $copyBoundary = is_array($callInviteCreateBody['result']['copy'] ?? null) ? $callInviteCreateBody['result']['copy'] : [];
    $copyEndpoint = (string) ($copyBoundary['endpoint'] ?? '');
    videochat_integration_http_assert($copyEndpoint === '/api/invite-codes/' . $inviteId . '/copy', 'call invite copy endpoint mismatch');

    $callInviteCopy = $dispatch('POST', $copyEndpoint, '', $adminSessionId);
    videochat_integration_http_assert((int) ($callInviteCopy['status'] ?? 0) === 200, 'call invite copy should succeed');
    $callInviteCopyBody = videochat_integration_http_decode($callInviteCopy);
    $inviteCopy = is_array($callInviteCopyBody['result']['copy'] ?? null) ? $callInviteCopyBody['result']['copy'] : [];
    $inviteCode = (string) ($inviteCopy['code'] ?? '');
    videochat_integration_http_assert(videochat_integration_http_uuid_v4_like($inviteCode), 'call invite copied code should be uuid-v4');

    $userInviteForbidden = $dispatch(
        'POST',
        '/api/invite-codes',
        json_encode([
            'scope' => 'call',
            'call_id' => $callId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $userSessionId
    );
    videochat_integration_http_assert((int) ($userInviteForbidden['status'] ?? 0) === 403, 'user invite create should be forbidden');
    videochat_integration_http_assert(
        (string) ((videochat_integration_http_decode($userInviteForbidden)['error'] ?? [])['code'] ?? '') === 'invite_codes_forbidden',
        'user invite forbidden error code mismatch'
    );

    $inviteRedeem = $dispatch(
        'POST',
        '/api/invite-codes/redeem',
        json_encode(['code' => $inviteCode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $userSessionId
    );
    videochat_integration_http_assert((int) ($inviteRedeem['status'] ?? 0) === 200, 'invite redeem should succeed');
    $inviteRedeemBody = videochat_integration_http_decode($inviteRedeem);
    videochat_integration_http_assert((string) ($inviteRedeemBody['status'] ?? '') === 'ok', 'invite redeem payload status mismatch');
    $redeemedInvitation = is_array($inviteRedeemBody['result']['redemption'] ?? null) ? $inviteRedeemBody['result']['redemption'] : [];
    videochat_integration_http_assert((string) (($redeemedInvitation['invite_code'] ?? [])['code'] ?? '') === strtolower($inviteCode), 'redeemed invite code mismatch');
    videochat_integration_http_assert((string) (($redeemedInvitation['join_context'] ?? [])['scope'] ?? '') === 'call', 'invite join context scope mismatch');
    videochat_integration_http_assert((string) ((($redeemedInvitation['join_context'] ?? [])['call'] ?? [])['id'] ?? '') === $callId, 'invite join context call mismatch');

    $unknownInviteRedeem = $dispatch(
        'POST',
        '/api/invite-codes/redeem',
        json_encode(['code' => videochat_generate_uuid_v4()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $adminSessionId
    );
    videochat_integration_http_assert((int) ($unknownInviteRedeem['status'] ?? 0) === 404, 'unknown invite redeem should fail closed');
    videochat_integration_http_assert(
        (string) ((videochat_integration_http_decode($unknownInviteRedeem)['error'] ?? [])['code'] ?? '') === 'invite_codes_redeem_not_found',
        'unknown invite redeem error code mismatch'
    );

    $refreshResponse = $dispatch('POST', '/api/auth/refresh', null, $adminSessionId);
    videochat_integration_http_assert((int) ($refreshResponse['status'] ?? 0) === 200, 'auth refresh should succeed');
    $refreshBody = videochat_integration_http_decode($refreshResponse);
    $rotatedAdminSessionId = (string) (($refreshBody['session'] ?? [])['id'] ?? '');
    videochat_integration_http_assert($rotatedAdminSessionId !== '', 'refresh should return a rotated session id');
    videochat_integration_http_assert($rotatedAdminSessionId !== $adminSessionId, 'refresh should rotate the session token');
    videochat_integration_http_assert((string) (($refreshBody['session'] ?? [])['replaces_session_id'] ?? '') === $adminSessionId, 'refresh should report replaced session id');

    $oldAdminSession = $dispatch('GET', '/api/auth/session', null, $adminSessionId);
    videochat_integration_http_assert((int) ($oldAdminSession['status'] ?? 0) === 401, 'old admin session should be revoked after refresh');
    videochat_integration_http_assert(
        (string) (((videochat_integration_http_decode($oldAdminSession)['error'] ?? [])['details'] ?? [])['reason'] ?? '') === 'revoked_session',
        'old admin revoked reason mismatch'
    );

    $newAdminSession = $dispatch('GET', '/api/auth/session', null, $rotatedAdminSessionId);
    videochat_integration_http_assert((int) ($newAdminSession['status'] ?? 0) === 200, 'rotated admin session should authenticate');

    $logoutResponse = $dispatch('POST', '/api/auth/logout', null, $userSessionId);
    videochat_integration_http_assert((int) ($logoutResponse['status'] ?? 0) === 200, 'logout should succeed');
    $logoutBody = videochat_integration_http_decode($logoutResponse);
    videochat_integration_http_assert((string) (($logoutBody['result'] ?? [])['session_id'] ?? '') === $userSessionId, 'logout session id mismatch');

    $postLogoutSession = $dispatch('GET', '/api/auth/session', null, $userSessionId);
    videochat_integration_http_assert((int) ($postLogoutSession['status'] ?? 0) === 401, 'post-logout session should fail closed');
    videochat_integration_http_assert(
        (string) (((videochat_integration_http_decode($postLogoutSession)['error'] ?? [])['details'] ?? [])['reason'] ?? '') === 'revoked_session',
        'post-logout revoked reason mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[videochat-integration-matrix-http-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[videochat-integration-matrix-http-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
