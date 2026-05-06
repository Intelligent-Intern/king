<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_call_apps.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_call_app_session_lifecycle_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-app-session-lifecycle-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_app_session_lifecycle_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_call_app_session_lifecycle_auth(PDO $pdo, int $userId, string $role): array
{
    $tenant = videochat_tenant_context_for_user($pdo, $userId);
    videochat_call_app_session_lifecycle_assert(is_array($tenant), 'tenant context missing');

    return [
        'ok' => true,
        'token' => 'sess_call_app_session_lifecycle_' . $userId,
        'user' => ['id' => $userId, 'role' => $role, 'status' => 'active'],
        'session' => ['id' => 'sess_call_app_session_lifecycle_' . $userId],
        'tenant' => videochat_tenant_auth_payload($tenant),
    ];
}

try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[call-app-session-lifecycle-contract] SKIP: PDO sqlite driver not available\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-app-session-lifecycle-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $regularUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_app_session_lifecycle_assert($tenantId > 0 && $adminUserId > 0 && $regularUserId > 0, 'fixture ids missing');

    $callId = 'call_app_session_lifecycle_contract_call';
    $roomId = 'room_call_app_session_lifecycle_contract';
    $now = gmdate('c');
    $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO rooms(id, tenant_id, name, visibility, status, created_at, updated_at)
VALUES(:id, :tenant_id, :name, 'private', 'active', :created_at, :updated_at)
SQL
    )->execute([
        ':id' => $roomId,
        ':tenant_id' => $tenantId,
        ':name' => 'Call App Session Lifecycle Room',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $pdo->prepare(
        <<<'SQL'
INSERT INTO calls(
    id, tenant_id, room_id, title, access_mode, owner_user_id, status,
    starts_at, ends_at, schedule_timezone, schedule_date,
    schedule_duration_minutes, schedule_all_day, created_at, updated_at
) VALUES(
    :id, :tenant_id, :room_id, :title, 'invite_only', :owner_user_id, 'active',
    :starts_at, :ends_at, 'UTC', :schedule_date,
    45, 0, :created_at, :updated_at
)
SQL
    )->execute([
        ':id' => $callId,
        ':tenant_id' => $tenantId,
        ':room_id' => $roomId,
        ':title' => 'Call App Session Lifecycle Contract',
        ':owner_user_id' => $adminUserId,
        ':starts_at' => '2026-05-07T10:00:00Z',
        ':ends_at' => '2026-05-07T10:45:00Z',
        ':schedule_date' => '2026-05-07',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $participantInsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, invite_state)
VALUES(:call_id, :user_id, :email, :display_name, :source, 'accepted')
SQL
    );
    $participantInsert->execute([
        ':call_id' => $callId,
        ':user_id' => $adminUserId,
        ':email' => 'admin@intelligent-intern.com',
        ':display_name' => 'Admin',
        ':source' => 'internal',
    ]);
    $participantInsert->execute([
        ':call_id' => $callId,
        ':user_id' => $regularUserId,
        ':email' => 'user@intelligent-intern.com',
        ':display_name' => 'User',
        ':source' => 'internal',
    ]);
    $participantInsert->execute([
        ':call_id' => $callId,
        ':user_id' => null,
        ':email' => 'guest@example.test',
        ':display_name' => 'Guest',
        ':source' => 'external',
    ]);

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, [
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'time' => gmdate('c'),
        ]);
    };
    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return [null, 'empty_body'];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    };
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $adminAuth = videochat_call_app_session_lifecycle_auth($pdo, $adminUserId, 'admin');
    $userAuth = videochat_call_app_session_lifecycle_auth($pdo, $regularUserId, 'user');

    $dispatch = static function (string $method, string $uri, array $auth, ?array $payload = null) use (
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    ): array {
        $routePath = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
        $request = [
            'method' => $method,
            'uri' => $uri,
            'path' => $routePath,
            'body' => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
        ];
        $response = videochat_handle_call_app_routes(
            $routePath,
            $method,
            $request,
            $auth,
            $jsonResponse,
            $errorResponse,
            $openDatabase,
            $decodeJsonBody
        );
        videochat_call_app_session_lifecycle_assert(is_array($response), 'route should return a response for ' . $uri);
        return $response;
    };

    videochat_call_app_refresh_catalog($pdo);
    videochat_call_app_create_organization_order($pdo, $tenantId, $adminUserId, 'whiteboard');
    videochat_call_app_create_organization_installation($pdo, $tenantId, $adminUserId, 'whiteboard');

    $emptyList = $dispatch('GET', '/api/calls/' . rawurlencode($callId) . '/call-app-sessions', $adminAuth);
    $emptyListPayload = videochat_call_app_session_lifecycle_decode($emptyList);
    videochat_call_app_session_lifecycle_assert(((array) (($emptyListPayload['result'] ?? [])['sessions'] ?? [])) === [], 'sessions must be empty before attach');

    $forbiddenCreate = $dispatch('POST', '/api/calls/' . rawurlencode($callId) . '/call-app-sessions', $userAuth, [
        'app_key' => 'whiteboard',
        'default_app_policy' => 'allowed_by_default',
    ]);
    videochat_call_app_session_lifecycle_assert((int) ($forbiddenCreate['status'] ?? 0) === 403, 'non-owner participant must not attach Call App');

    $created = $dispatch('POST', '/api/calls/' . rawurlencode($callId) . '/call-app-sessions', $adminAuth, [
        'app_key' => 'whiteboard',
        'default_app_policy' => 'allowed_by_default',
    ]);
    $createdPayload = videochat_call_app_session_lifecycle_decode($created);
    videochat_call_app_session_lifecycle_assert((int) ($created['status'] ?? 0) === 201, 'owner attach should create session');
    $session = is_array(($createdPayload['result'] ?? [])['session'] ?? null) ? ($createdPayload['result'] ?? [])['session'] : [];
    $sessionId = (string) ($session['id'] ?? '');
    videochat_call_app_session_lifecycle_assert($sessionId !== '', 'created session id missing');
    videochat_call_app_session_lifecycle_assert((string) ($session['status'] ?? '') === 'active', 'created session should be active');
    videochat_call_app_session_lifecycle_assert((string) ($session['document_id'] ?? '') !== '', 'created session document id missing');
    videochat_call_app_session_lifecycle_assert(count((array) ($session['grants'] ?? [])) === 3, 'default grants should cover owner, internal participant, and guest');

    $listed = $dispatch('GET', '/api/calls/' . rawurlencode($callId) . '/call-app-sessions', $adminAuth);
    $listedPayload = videochat_call_app_session_lifecycle_decode($listed);
    $sessions = is_array(($listedPayload['result'] ?? [])['sessions'] ?? null) ? ($listedPayload['result'] ?? [])['sessions'] : [];
    videochat_call_app_session_lifecycle_assert(count($sessions) === 1 && (string) ($sessions[0]['id'] ?? '') === $sessionId, 'session list should include created session');

    $snapshot = videochat_realtime_room_snapshot_payload(videochat_presence_state_init(), [
        'room_id' => $roomId,
        'active_call_id' => $callId,
        'requested_call_id' => $callId,
        'tenant_id' => $tenantId,
        'user_id' => $adminUserId,
        'role' => 'admin',
        'call_role' => 'owner',
        'effective_call_role' => 'owner',
        'socket' => null,
    ], $openDatabase, 'call_app_session_contract');
    videochat_call_app_session_lifecycle_assert((int) (($snapshot['call_apps'] ?? [])['active_session_count'] ?? 0) === 1, 'room snapshot must include active Call App session');
    videochat_call_app_session_lifecycle_assert((string) (((($snapshot['call_apps'] ?? [])['active_sessions'] ?? [])[0] ?? [])['id'] ?? '') === $sessionId, 'room snapshot session id mismatch');

    $inactive = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId), $adminAuth, ['status' => 'inactive']);
    videochat_call_app_session_lifecycle_assert((int) ($inactive['status'] ?? 0) === 200, 'inactive update should return 200');
    $inactiveSnapshot = videochat_call_app_room_snapshot($pdo, $tenantId, $callId);
    videochat_call_app_session_lifecycle_assert((int) ($inactiveSnapshot['active_session_count'] ?? 0) === 0, 'inactive session must leave active room snapshot');

    $active = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId), $adminAuth, ['status' => 'active']);
    videochat_call_app_session_lifecycle_assert((int) ($active['status'] ?? 0) === 200, 'active update should return 200');
    $activeSnapshot = videochat_call_app_room_snapshot($pdo, $tenantId, $callId);
    videochat_call_app_session_lifecycle_assert((int) ($activeSnapshot['active_session_count'] ?? 0) === 1, 'reactivated session must return to active room snapshot');

    $sessionRowId = (int) $pdo->query("SELECT id FROM call_app_sessions WHERE public_id = " . $pdo->quote($sessionId) . " LIMIT 1")->fetchColumn();
    $pdo->prepare(
        <<<'SQL'
INSERT INTO call_app_launch_tokens(
    public_id, tenant_id, app_session_id, token_hash, issued_to_user_id,
    issued_at, expires_at, created_at, updated_at
) VALUES(
    :public_id, :tenant_id, :app_session_id, :token_hash, :issued_to_user_id,
    :issued_at, :expires_at, :created_at, :updated_at
)
SQL
    )->execute([
        ':public_id' => 'launch_contract_token',
        ':tenant_id' => $tenantId,
        ':app_session_id' => $sessionRowId,
        ':token_hash' => hash('sha256', 'launch_contract_token'),
        ':issued_to_user_id' => $adminUserId,
        ':issued_at' => $now,
        ':expires_at' => '2026-05-07T11:00:00Z',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $removed = $dispatch('DELETE', '/api/call-app-sessions/' . rawurlencode($sessionId), $adminAuth);
    $removedPayload = videochat_call_app_session_lifecycle_decode($removed);
    videochat_call_app_session_lifecycle_assert((int) ($removed['status'] ?? 0) === 200, 'remove should return 200');
    videochat_call_app_session_lifecycle_assert((int) (($removedPayload['result'] ?? [])['retired_launch_tokens'] ?? 0) === 1, 'remove must retire launch tokens');
    $removedSnapshot = videochat_call_app_room_snapshot($pdo, $tenantId, $callId);
    videochat_call_app_session_lifecycle_assert((int) ($removedSnapshot['active_session_count'] ?? 0) === 0, 'removed session must leave active room snapshot');
    $revokedAt = (string) $pdo->query("SELECT revoked_at FROM call_app_launch_tokens WHERE public_id = 'launch_contract_token' LIMIT 1")->fetchColumn();
    videochat_call_app_session_lifecycle_assert($revokedAt !== '', 'removed session must revoke launch token');

    $afterRemoveList = $dispatch('GET', '/api/calls/' . rawurlencode($callId) . '/call-app-sessions', $adminAuth);
    $afterRemovePayload = videochat_call_app_session_lifecycle_decode($afterRemoveList);
    videochat_call_app_session_lifecycle_assert(((array) (($afterRemovePayload['result'] ?? [])['sessions'] ?? [])) === [], 'removed sessions must be hidden by default');
    $historyList = $dispatch('GET', '/api/calls/' . rawurlencode($callId) . '/call-app-sessions?include_removed=1', $adminAuth);
    $historyPayload = videochat_call_app_session_lifecycle_decode($historyList);
    $history = is_array(($historyPayload['result'] ?? [])['sessions'] ?? null) ? ($historyPayload['result'] ?? [])['sessions'] : [];
    videochat_call_app_session_lifecycle_assert(count($history) === 1 && (string) ($history[0]['status'] ?? '') === 'removed', 'include_removed should expose removed history');

    $removedPatch = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId), $adminAuth, ['status' => 'active']);
    videochat_call_app_session_lifecycle_assert((int) ($removedPatch['status'] ?? 0) === 409, 'removed sessions must not reactivate');

    fwrite(STDOUT, "[call-app-session-lifecycle-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-app-session-lifecycle-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
