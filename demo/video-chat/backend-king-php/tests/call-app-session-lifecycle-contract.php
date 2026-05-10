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

function videochat_call_app_session_lifecycle_diagnostics(array $payload): array
{
    $diagnostics = [];
    $resultDiagnostics = ($payload['result'] ?? [])['diagnostics'] ?? [];
    if (is_array($resultDiagnostics)) {
        $diagnostics = array_merge($diagnostics, $resultDiagnostics);
    }
    $errorDiagnostic = (($payload['error'] ?? [])['details'] ?? [])['diagnostic'] ?? null;
    if (is_array($errorDiagnostic)) {
        $diagnostics[] = $errorDiagnostic;
    }
    return $diagnostics;
}

function videochat_call_app_session_lifecycle_assert_diagnostic(array $payload, string $eventType, string $message): void
{
    foreach (videochat_call_app_session_lifecycle_diagnostics($payload) as $diagnostic) {
        if ((string) ($diagnostic['event_type'] ?? '') === $eventType) {
            return;
        }
    }
    videochat_call_app_session_lifecycle_assert(false, $message);
}

function videochat_call_app_session_lifecycle_error_reason(array $payload): string
{
    return (string) (((($payload['error'] ?? [])['details'] ?? [])['reason'] ?? ''));
}

function videochat_call_app_session_lifecycle_last_frame(array $frames, string $socket, string $type = ''): array
{
    $rows = $frames[$socket] ?? [];
    if (!is_array($rows)) {
        return [];
    }
    for ($index = count($rows) - 1; $index >= 0; $index--) {
        $frame = $rows[$index] ?? null;
        if (!is_array($frame)) {
            continue;
        }
        if ($type === '' || (string) ($frame['type'] ?? '') === $type) {
            return $frame;
        }
    }
    return [];
}

function videochat_call_app_session_lifecycle_grant_for_user(array $session, int $userId): array
{
    foreach ((array) ($session['grants'] ?? []) as $grant) {
        if (is_array($grant) && (int) ($grant['user_id'] ?? 0) === $userId) {
            return $grant;
        }
    }
    return [];
}

function videochat_call_app_session_lifecycle_auth(PDO $pdo, int $userId, string $role): array
{
    $tenant = videochat_tenant_context_for_user($pdo, $userId);
    videochat_call_app_session_lifecycle_assert(is_array($tenant), 'tenant context missing');
    static $sessionCounter = 0;
    $sessionCounter += 1;
    $token = 'sess_call_app_session_lifecycle_' . $userId . '_' . $sessionCounter;
    $hasActiveTenant = videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id');
    $insert = $hasActiveTenant
        ? 'INSERT OR IGNORE INTO sessions(id, user_id, active_tenant_id, issued_at, expires_at, revoked_at, client_ip, user_agent) VALUES(:id, :user_id, :active_tenant_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)'
        : 'INSERT OR IGNORE INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent) VALUES(:id, :user_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)';
    $expiresAt = gmdate('c', time() + 3600);
    $params = [':id' => $token, ':user_id' => $userId, ':issued_at' => gmdate('c'), ':expires_at' => $expiresAt, ':client_ip' => '127.0.0.1', ':user_agent' => 'call-app-session-lifecycle-contract'];
    if ($hasActiveTenant) {
        $params[':active_tenant_id'] = (int) ($tenant['id'] ?? 0);
    }
    $pdo->prepare($insert)->execute($params);
    return ['ok' => true, 'token' => $token, 'user' => ['id' => $userId, 'role' => $role, 'status' => 'active'], 'session' => ['id' => $token, 'expires_at' => $expiresAt], 'tenant' => videochat_tenant_auth_payload($tenant)];
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
    $realtimeFrames = [];
    $sender = static function (mixed $socket, array $payload) use (&$realtimeFrames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        if (!isset($realtimeFrames[$key]) || !is_array($realtimeFrames[$key])) {
            $realtimeFrames[$key] = [];
        }
        $realtimeFrames[$key][] = $payload;
        return true;
    };
    $presenceState = videochat_presence_state_init();
    $tenantPayload = ['id' => $tenantId];
    $adminConnection = videochat_presence_connection_descriptor([
        'id' => $adminUserId,
        'display_name' => 'Admin',
        'role' => 'admin',
        'tenant' => $tenantPayload,
    ], 'sess-admin-realtime', 'conn-admin-realtime', 'socket-admin-realtime', $roomId);
    $adminConnection['active_call_id'] = $callId;
    $adminConnection['requested_call_id'] = $callId;
    $adminConnection['call_role'] = 'owner';
    $adminConnection['effective_call_role'] = 'owner';
    $adminConnection['can_moderate_call'] = true;
    $adminJoin = videochat_presence_join_room($presenceState, $adminConnection, $roomId, $sender);
    $adminConnection = (array) ($adminJoin['connection'] ?? $adminConnection);

    $userConnection = videochat_presence_connection_descriptor([
        'id' => $regularUserId,
        'display_name' => 'User',
        'role' => 'user',
        'tenant' => $tenantPayload,
    ], 'sess-user-realtime', 'conn-user-realtime', 'socket-user-realtime', $roomId);
    $userConnection['active_call_id'] = $callId;
    $userConnection['requested_call_id'] = $callId;
    $userConnection['call_role'] = 'participant';
    $userConnection['effective_call_role'] = 'participant';
    $userJoin = videochat_presence_join_room($presenceState, $userConnection, $roomId, $sender);
    $userConnection = (array) ($userJoin['connection'] ?? $userConnection);

    $broadcastRoomSnapshot = static function (string $broadcastCallId, int $broadcastTenantId, string $reason) use (
        &$presenceState,
        $openDatabase,
        $sender
    ): int {
        return videochat_realtime_broadcast_call_room_snapshots(
            $presenceState,
            $broadcastCallId,
            $broadcastTenantId,
            $openDatabase,
            $reason,
            '',
            $sender
        );
    };

    $dispatch = static function (string $method, string $uri, array $auth, ?array $payload = null) use (
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        $broadcastRoomSnapshot
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
            $decodeJsonBody,
            $broadcastRoomSnapshot
        );
        videochat_call_app_session_lifecycle_assert(is_array($response), 'route should return a response for ' . $uri);
        return $response;
    };

    videochat_call_app_refresh_catalog($pdo);
    videochat_call_app_create_organization_order($pdo, $tenantId, $adminUserId, 'whiteboard');
    videochat_call_app_create_organization_installation($pdo, $tenantId, $adminUserId, 'whiteboard');

    $availableAfterInstall = $dispatch('GET', '/api/calls/' . rawurlencode($callId) . '/call-apps/available?query=whiteboard&page=1&page_size=8', $adminAuth);
    $availableAfterInstallPayload = videochat_call_app_session_lifecycle_decode($availableAfterInstall);
    $availableApps = is_array(($availableAfterInstallPayload['result'] ?? [])['apps'] ?? null) ? ($availableAfterInstallPayload['result'] ?? [])['apps'] : [];
    videochat_call_app_session_lifecycle_assert((int) ($availableAfterInstall['status'] ?? 0) === 200, 'installed whiteboard availability should return 200');
    videochat_call_app_session_lifecycle_assert(count($availableApps) === 1 && (string) ($availableApps[0]['app_key'] ?? '') === 'whiteboard', 'installed whiteboard must appear in available Call Apps');
    videochat_call_app_session_lifecycle_assert((($availableApps[0]['availability'] ?? [])['installed'] ?? false) === true, 'available Call App must be organization-installed');
    videochat_call_app_session_lifecycle_assert((string) (($availableApps[0]['installation'] ?? [])['status'] ?? '') === 'enabled', 'available Call App installation must be enabled');
    videochat_call_app_session_lifecycle_assert((string) ((($availableAfterInstallPayload['result'] ?? [])['discovery'] ?? [])['source'] ?? '') === 'semantic_dns_mcp', 'available Call Apps must come from Semantic-DNS/MCP discovery');

    $emptyList = $dispatch('GET', '/api/calls/' . rawurlencode($callId) . '/call-app-sessions', $adminAuth);
    $emptyListPayload = videochat_call_app_session_lifecycle_decode($emptyList);
    videochat_call_app_session_lifecycle_assert(((array) (($emptyListPayload['result'] ?? [])['sessions'] ?? [])) === [], 'sessions must be empty before attach');

    $forbiddenCreate = $dispatch('POST', '/api/calls/' . rawurlencode($callId) . '/call-app-sessions', $userAuth, [
        'app_key' => 'whiteboard',
        'default_app_policy' => 'allowed_by_default',
    ]);
    videochat_call_app_session_lifecycle_assert((int) ($forbiddenCreate['status'] ?? 0) === 403, 'non-owner participant must not attach Call App');

    $realtimeFrames = [];
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
    videochat_call_app_session_lifecycle_assert(((array) ($session['permission_actions'] ?? [])) === ['read', 'write', 'delete'], 'session must advertise supported read/write/delete permission actions');
    foreach ((array) ($session['grants'] ?? []) as $seededGrant) {
        videochat_call_app_session_lifecycle_assert(((array) ($seededGrant['permission_actions'] ?? [])) === ['read', 'write', 'delete'], 'seeded binary grants must retain full read/write/delete actions');
        videochat_call_app_session_lifecycle_assert((bool) (($seededGrant['permissions'] ?? [])['read'] ?? false) === true, 'seeded grants must expose read permission map');
        videochat_call_app_session_lifecycle_assert((bool) (($seededGrant['permissions'] ?? [])['write'] ?? false) === true, 'seeded grants must expose write permission map');
        videochat_call_app_session_lifecycle_assert((bool) (($seededGrant['permissions'] ?? [])['delete'] ?? false) === true, 'seeded grants must expose delete permission map');
    }
    videochat_call_app_session_lifecycle_assert((array) (videochat_call_app_session_lifecycle_grant_for_user($session, $regularUserId)['permission_actions'] ?? []) === ['read', 'write', 'delete'], 'default allowed grant must expose canonical permission_actions');
    videochat_call_app_session_lifecycle_assert((int) ((($createdPayload['result'] ?? [])['room_snapshot_broadcast'] ?? [])['sent_count'] ?? 0) === 2, 'session attach should broadcast refreshed room snapshots to connected call participants');
    $createdSnapshot = videochat_call_app_session_lifecycle_last_frame($realtimeFrames, 'socket-user-realtime', 'room/snapshot');
    videochat_call_app_session_lifecycle_assert((string) ($createdSnapshot['reason'] ?? '') === 'call_app_session_changed', 'session attach snapshot reason mismatch');
    videochat_call_app_session_lifecycle_assert((int) (($createdSnapshot['call_apps'] ?? [])['active_session_count'] ?? 0) === 1, 'session attach snapshot must expose active Call App session');

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

    $sessionRowId = (int) $pdo->query("SELECT id FROM call_app_sessions WHERE public_id = " . $pdo->quote($sessionId) . " LIMIT 1")->fetchColumn();
    videochat_call_app_session_lifecycle_assert($sessionRowId > 0, 'created session database id missing');
    $guestId = videochat_call_app_session_guest_id('guest@example.test');

    $regularAllowedLaunch = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token', $userAuth);
    $regularAllowedLaunchPayload = videochat_call_app_session_lifecycle_decode($regularAllowedLaunch);
    $regularAllowedLaunchResult = is_array($regularAllowedLaunchPayload['result'] ?? null) ? $regularAllowedLaunchPayload['result'] : [];
    $regularAllowedCapabilities = (array) ((($regularAllowedLaunchResult['context'] ?? [])['capabilities'] ?? []));
    $regularAllowedLaunchToken = (string) ($regularAllowedLaunchResult['launch_token'] ?? '');
    $regularAllowedLaunchTokenId = (string) ($regularAllowedLaunchResult['launch_token_id'] ?? '');
    videochat_call_app_session_lifecycle_assert((int) ($regularAllowedLaunch['status'] ?? 0) === 201, 'default-allowed participant launch token should return 201');
    videochat_call_app_session_lifecycle_assert(in_array('call_apps.crdt.read', $regularAllowedCapabilities, true), 'default-allowed participant launch must allow CRDT read');
    videochat_call_app_session_lifecycle_assert(in_array('call_apps.crdt.append', $regularAllowedCapabilities, true), 'default-allowed participant launch must allow CRDT append');

    $forbiddenGrantPatch = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/participant-grants', $userAuth, [
        'grants' => [[
            'subject_type' => 'user',
            'user_id' => $regularUserId,
            'grant_state' => 'denied',
        ]],
    ]);
    videochat_call_app_session_lifecycle_assert((int) ($forbiddenGrantPatch['status'] ?? 0) === 403, 'non-owner participant must not update app grants');

    $realtimeFrames = [];
    $grantPatch = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/participant-grants', $adminAuth, [
        'grants' => [[
            'subject_type' => 'user',
            'user_id' => $regularUserId,
            'grant_state' => 'denied',
        ]],
    ]);
    $grantPatchPayload = videochat_call_app_session_lifecycle_decode($grantPatch);
    videochat_call_app_session_lifecycle_assert((int) ($grantPatch['status'] ?? 0) === 200, 'owner grant patch should return 200');
    videochat_call_app_session_lifecycle_assert_diagnostic($grantPatchPayload, 'call_app_grants_changed', 'grant patch must emit a grant-change diagnostic');
    videochat_call_app_session_lifecycle_assert((string) (($grantPatchPayload['result'] ?? [])['state'] ?? '') === 'updated', 'grant patch result state should be updated');
    videochat_call_app_session_lifecycle_assert(count((array) (($grantPatchPayload['result'] ?? [])['audit_events'] ?? [])) === 1, 'grant patch should create one audit event');
    videochat_call_app_session_lifecycle_assert((int) (((($grantPatchPayload['result'] ?? [])['changed_grants'] ?? [])[0] ?? [])['retired_launch_tokens'] ?? 0) === 1, 'denying a participant must revoke their active launch token');
    videochat_call_app_session_lifecycle_assert((int) (((($grantPatchPayload['result'] ?? [])['audit_events'] ?? [])[0] ?? [])['payload']['retired_launch_tokens'] ?? 0) === 1, 'grant audit must record retired launch tokens');
    $patchedSession = is_array(($grantPatchPayload['result'] ?? [])['session'] ?? null) ? ($grantPatchPayload['result'] ?? [])['session'] : [];
    $regularGrant = videochat_call_app_session_lifecycle_grant_for_user($patchedSession, $regularUserId);
    videochat_call_app_session_lifecycle_assert((string) ($regularGrant['grant_state'] ?? '') === 'denied', 'regular user grant should be denied after patch');
    videochat_call_app_session_lifecycle_assert((array) ($regularGrant['permission_actions'] ?? ['unexpected']) === [], 'denied grant must expose empty canonical permission_actions');
    videochat_call_app_session_lifecycle_assert((int) ((($grantPatchPayload['result'] ?? [])['room_snapshot_broadcast'] ?? [])['sent_count'] ?? 0) === 2, 'grant patch should broadcast refreshed room snapshots to connected call participants');
    $grantPatchSnapshot = videochat_call_app_session_lifecycle_last_frame($realtimeFrames, 'socket-user-realtime', 'room/snapshot');
    $grantPatchSnapshotSession = (($grantPatchSnapshot['call_apps'] ?? [])['active_sessions'] ?? [])[0] ?? [];
    $regularSnapshotGrant = is_array($grantPatchSnapshotSession) ? videochat_call_app_session_lifecycle_grant_for_user($grantPatchSnapshotSession, $regularUserId) : [];
    videochat_call_app_session_lifecycle_assert((string) ($grantPatchSnapshot['reason'] ?? '') === 'call_app_grants_changed', 'grant patch snapshot reason mismatch');
    videochat_call_app_session_lifecycle_assert((string) ($regularSnapshotGrant['grant_state'] ?? '') === 'denied', 'grant patch snapshot must expose the updated denied grant');
    videochat_call_app_session_lifecycle_assert((array) ($regularSnapshotGrant['permission_actions'] ?? ['unexpected']) === [], 'grant patch snapshot must expose canonical permission_actions from fetched session grants');
    $regularTokenRevokedAt = (string) $pdo->query("SELECT revoked_at FROM call_app_launch_tokens WHERE public_id = " . $pdo->quote($regularAllowedLaunchTokenId) . " LIMIT 1")->fetchColumn();
    videochat_call_app_session_lifecycle_assert($regularTokenRevokedAt !== '', 'denied participant active launch token must be revoked');
    $revokedRegularValidate = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], [
        'launch_token' => $regularAllowedLaunchToken,
    ]);
    videochat_call_app_session_lifecycle_assert((int) ($revokedRegularValidate['status'] ?? 0) === 401, 'revoked participant launch token must fail reconnect validation');

    $grantList = $dispatch('GET', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/participant-grants', $adminAuth);
    $grantListPayload = videochat_call_app_session_lifecycle_decode($grantList);
    videochat_call_app_session_lifecycle_assert((int) ($grantList['status'] ?? 0) === 200, 'grant list should return 200');
    videochat_call_app_session_lifecycle_assert((string) (($grantListPayload['result'] ?? [])['session_id'] ?? '') === $sessionId, 'grant list must expose exact GET payload session and call ids');
    videochat_call_app_session_lifecycle_assert((string) (($grantListPayload['result'] ?? [])['call_id'] ?? '') === $callId, 'grant list must expose exact GET payload call id');
    videochat_call_app_session_lifecycle_assert((string) (($grantListPayload['result'] ?? [])['default_app_policy'] ?? '') === 'allowed_by_default', 'grant list must expose default app policy for frontend fallback labels');
    $grantListRegularGrant = array_values(array_filter((array) (($grantListPayload['result'] ?? [])['grants'] ?? []), static fn (array $grant): bool => (int) ($grant['user_id'] ?? 0) === $regularUserId))[0] ?? [];
    videochat_call_app_session_lifecycle_assert((string) ($grantListRegularGrant['grant_state'] ?? '') === 'denied', 'grant list must include the denied participant grant state');
    videochat_call_app_session_lifecycle_assert(count((array) (($grantListPayload['result'] ?? [])['audit_events'] ?? [])) >= 1, 'grant list should include audit events');
    videochat_call_app_session_lifecycle_assert((int) (((($grantListPayload['result'] ?? [])['audit_events'] ?? [])[0] ?? [])['payload']['retired_launch_tokens'] ?? 0) === 1, 'grant list audit trail should expose revocation metadata');
    $auditCount = (int) $pdo->query("SELECT COUNT(*) FROM call_app_audit_events WHERE app_session_id = {$sessionRowId} AND event_type = 'participant_grant_changed'")->fetchColumn();
    videochat_call_app_session_lifecycle_assert($auditCount === 1, 'grant patch should persist exactly one audit event');
    $deniedGrantCount = (int) $pdo->query("SELECT COUNT(*) FROM call_app_participant_grants WHERE app_session_id = {$sessionRowId} AND user_id = {$regularUserId} AND grant_state = 'denied' AND source = 'explicit'")->fetchColumn();
    videochat_call_app_session_lifecycle_assert($deniedGrantCount === 1, 'grant patch should persist the denied state in call_app_participant_grants');

    $sessionRecordAfterUserDeny = videochat_call_app_fetch_session_record($pdo, $tenantId, $sessionId);
    videochat_call_app_session_lifecycle_assert(is_array($sessionRecordAfterUserDeny), 'session record should still exist after user deny');
    videochat_call_app_session_lifecycle_assert(videochat_call_app_launch_guest_grant_state($pdo, $tenantId, $sessionRecordAfterUserDeny, $guestId) === 'allowed', 'guest grant should inherit default allow before explicit patch');
    $guestGrantPatch = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/participant-grants', $adminAuth, [
        'grants' => [[
            'subject_type' => 'guest',
            'guest_id' => $guestId,
            'grant_state' => 'denied',
        ]],
    ]);
    videochat_call_app_session_lifecycle_assert((int) ($guestGrantPatch['status'] ?? 0) === 200, 'owner should update guest app grants');
    $sessionRecordAfterGuestDeny = videochat_call_app_fetch_session_record($pdo, $tenantId, $sessionId);
    videochat_call_app_session_lifecycle_assert(is_array($sessionRecordAfterGuestDeny), 'session record should still exist after guest deny');
    videochat_call_app_session_lifecycle_assert(videochat_call_app_launch_guest_grant_state($pdo, $tenantId, $sessionRecordAfterGuestDeny, $guestId) === 'denied', 'guest grant state must apply across reconnect lookups');

    $unknownGrant = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/participant-grants', $adminAuth, [
        'grants' => [[
            'subject_type' => 'user',
            'user_id' => 999999,
            'grant_state' => 'allowed',
        ]],
    ]);
    videochat_call_app_session_lifecycle_assert((int) ($unknownGrant['status'] ?? 0) === 422, 'unknown user grant patch should fail closed');

    $deniedLaunch = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token', $userAuth);
    $deniedLaunchPayload = videochat_call_app_session_lifecycle_decode($deniedLaunch);
    $deniedCapabilities = (array) (((($deniedLaunchPayload['result'] ?? [])['context'] ?? [])['capabilities'] ?? []));
    $deniedStatusOnlyLaunchToken = (string) ((($deniedLaunchPayload['result'] ?? [])['launch_token'] ?? ''));
    videochat_call_app_session_lifecycle_assert((int) ($deniedLaunch['status'] ?? 0) === 201, 'denied participant should receive only a status launch token');
    videochat_call_app_session_lifecycle_assert(in_array('call_apps.launch', $deniedCapabilities, true), 'denied participant launch must allow app status bootstrap');
    videochat_call_app_session_lifecycle_assert(!in_array('call_apps.crdt.read', $deniedCapabilities, true), 'denied participant launch must not allow CRDT read');
    videochat_call_app_session_lifecycle_assert(!in_array('call_apps.crdt.append', $deniedCapabilities, true), 'denied participant launch must not allow CRDT append');

    $launch = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token', $adminAuth);
    $launchPayload = videochat_call_app_session_lifecycle_decode($launch);
    $launchResult = is_array($launchPayload['result'] ?? null) ? $launchPayload['result'] : [];
    $launchToken = (string) ($launchResult['launch_token'] ?? '');
    $launchTokenId = (string) ($launchResult['launch_token_id'] ?? '');
    videochat_call_app_session_lifecycle_assert((int) ($launch['status'] ?? 0) === 201, 'allowed participant launch token should return 201');
    videochat_call_app_session_lifecycle_assert(strlen($launchToken) >= 68 && $launchTokenId !== '', 'launch token and token id must be present');
    videochat_call_app_session_lifecycle_assert(!str_contains(json_encode($launchPayload, JSON_UNESCAPED_SLASHES), (string) ($adminAuth['token'] ?? '')), 'launch payload must not expose the primary session token');
    videochat_call_app_session_lifecycle_assert((string) (((($launchResult['context'] ?? [])['participant'] ?? [])['actor_id'] ?? '')) !== '', 'launch context must expose a pseudonymous actor id');
    videochat_call_app_session_lifecycle_assert(!array_key_exists('user_id', (array) ((($launchResult['context'] ?? [])['participant'] ?? []))), 'launch context must not expose raw user ids to the iframe');

    $validatedLaunch = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], [
        'launch_token' => $launchToken,
    ]);
    $validatedPayload = videochat_call_app_session_lifecycle_decode($validatedLaunch);
    videochat_call_app_session_lifecycle_assert((int) ($validatedLaunch['status'] ?? 0) === 200, 'launch token validation should return 200');
    videochat_call_app_session_lifecycle_assert((string) (($validatedPayload['result'] ?? [])['state'] ?? '') === 'valid', 'validated launch token state mismatch');

    $otherCallId = 'call_app_session_lifecycle_other_call';
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
        ':id' => $otherCallId,
        ':tenant_id' => $tenantId,
        ':room_id' => $roomId,
        ':title' => 'Call App Session Lifecycle Other Call',
        ':owner_user_id' => $adminUserId,
        ':starts_at' => '2026-05-07T11:00:00Z',
        ':ends_at' => '2026-05-07T11:45:00Z',
        ':schedule_date' => '2026-05-07',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $participantInsert->execute([
        ':call_id' => $otherCallId,
        ':user_id' => $adminUserId,
        ':email' => 'admin@intelligent-intern.com',
        ':display_name' => 'Admin',
        ':source' => 'internal',
    ]);
    $otherSessionResult = videochat_call_app_create_session($pdo, $tenantId, $otherCallId, $adminUserId, 'whiteboard', 'allowed_by_default');
    $otherSessionId = (string) ((($otherSessionResult['session'] ?? [])['id'] ?? ''));
    videochat_call_app_session_lifecycle_assert((bool) ($otherSessionResult['ok'] ?? false) && $otherSessionId !== '', 'other call session should be created for cross-call replay proof');
    $crossCallReplay = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($otherSessionId) . '/launch-token/validate', [], [
        'launch_token' => $launchToken,
    ]);
    $crossCallReplayPayload = videochat_call_app_session_lifecycle_decode($crossCallReplay);
    videochat_call_app_session_lifecycle_assert((int) ($crossCallReplay['status'] ?? 0) === 401, 'cross-call launch token replay must fail closed');
    videochat_call_app_session_lifecycle_assert(videochat_call_app_session_lifecycle_error_reason($crossCallReplayPayload) === 'token_not_found', 'cross-call launch token replay reason mismatch');

    $expiredLaunch = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token', $adminAuth);
    $expiredLaunchPayload = videochat_call_app_session_lifecycle_decode($expiredLaunch);
    $expiredLaunchToken = (string) ((($expiredLaunchPayload['result'] ?? [])['launch_token'] ?? ''));
    $expiredLaunchTokenId = (string) ((($expiredLaunchPayload['result'] ?? [])['launch_token_id'] ?? ''));
    videochat_call_app_session_lifecycle_assert((int) ($expiredLaunch['status'] ?? 0) === 201 && $expiredLaunchToken !== '' && $expiredLaunchTokenId !== '', 'fresh launch token should exist before expiry proof');
    $pdo->prepare(
        'UPDATE call_app_launch_tokens SET expires_at = :expires_at, updated_at = :updated_at WHERE public_id = :public_id'
    )->execute([
        ':expires_at' => gmdate('c', time() - 60),
        ':updated_at' => gmdate('c'),
        ':public_id' => $expiredLaunchTokenId,
    ]);
    $expiredValidate = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], [
        'launch_token' => $expiredLaunchToken,
    ]);
    $expiredValidatePayload = videochat_call_app_session_lifecycle_decode($expiredValidate);
    videochat_call_app_session_lifecycle_assert((int) ($expiredValidate['status'] ?? 0) === 401, 'expired launch token must fail reconnect validation');
    videochat_call_app_session_lifecycle_assert(videochat_call_app_session_lifecycle_error_reason($expiredValidatePayload) === 'token_expired', 'expired launch token reason mismatch');

    $pdo->prepare(
        <<<'SQL'
UPDATE organization_call_app_entitlements
SET status = 'revoked',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND app_key = 'whiteboard'
SQL
    )->execute([':updated_at' => gmdate('c'), ':tenant_id' => $tenantId]);
    $revokedEntitlementValidate = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], [
        'launch_token' => $launchToken,
    ]);
    $revokedEntitlementValidatePayload = videochat_call_app_session_lifecycle_decode($revokedEntitlementValidate);
    videochat_call_app_session_lifecycle_assert((int) ($revokedEntitlementValidate['status'] ?? 0) === 401, 'launch token validation must fail after entitlement revocation');
    videochat_call_app_session_lifecycle_assert(videochat_call_app_session_lifecycle_error_reason($revokedEntitlementValidatePayload) === 'entitlement_not_active', 'entitlement-revoked reconnect reason mismatch');
    $revokedEntitlementMint = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token', $adminAuth);
    $revokedEntitlementMintPayload = videochat_call_app_session_lifecycle_decode($revokedEntitlementMint);
    videochat_call_app_session_lifecycle_assert((int) ($revokedEntitlementMint['status'] ?? 0) === 409, 'launch token mint must fail while entitlement is revoked');
    videochat_call_app_session_lifecycle_assert(videochat_call_app_session_lifecycle_error_reason($revokedEntitlementMintPayload) === 'entitlement_not_active', 'revoked entitlement mint reason mismatch');
    $pdo->prepare(
        <<<'SQL'
UPDATE organization_call_app_entitlements
SET status = 'active',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND app_key = 'whiteboard'
SQL
    )->execute([':updated_at' => gmdate('c'), ':tenant_id' => $tenantId]);

    $invalidLaunch = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], [
        'launch_token' => 'not-a-real-launch-token',
    ]);
    $invalidLaunchPayload = videochat_call_app_session_lifecycle_decode($invalidLaunch);
    videochat_call_app_session_lifecycle_assert((int) ($invalidLaunch['status'] ?? 0) === 401, 'invalid launch token should fail closed');
    videochat_call_app_session_lifecycle_assert_diagnostic($invalidLaunchPayload, 'call_app_launch_token_failed', 'invalid launch token validation must emit a launch-token failure diagnostic');

    $bootstrap = $dispatch('GET', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/bootstrap', $adminAuth);
    $bootstrapPayload = videochat_call_app_session_lifecycle_decode($bootstrap);
    videochat_call_app_session_lifecycle_assert((int) ($bootstrap['status'] ?? 0) === 200, 'CRDT bootstrap should return 200');
    videochat_call_app_session_lifecycle_assert((string) (((($bootstrapPayload['result'] ?? [])['document'] ?? [])['document_id'] ?? '')) === (string) ($session['document_id'] ?? ''), 'CRDT bootstrap document id mismatch');

    $deniedAppend = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/ops', $userAuth, [
        'operation' => [
            'operation_id' => 'op_denied_user',
            'payload_type' => 'stroke.add',
            'payload' => ['points' => [[1, 1], [2, 2]]],
        ],
    ]);
    videochat_call_app_session_lifecycle_assert((int) ($deniedAppend['status'] ?? 0) === 403, 'denied participant must not append CRDT ops');

    $append = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/ops', $adminAuth, [
        'operation' => [
            'operation_id' => 'op_admin_stroke_1',
            'payload_type' => 'stroke.add',
            'payload' => ['points' => [[10, 10], [20, 20]], 'color' => '#1582BF'],
            'causal_dependencies' => [],
        ],
    ]);
    $appendPayload = videochat_call_app_session_lifecycle_decode($append);
    videochat_call_app_session_lifecycle_assert((int) ($append['status'] ?? 0) === 201, 'allowed participant append should admit CRDT op');
    videochat_call_app_session_lifecycle_assert_diagnostic($appendPayload, 'call_app_crdt_append_latency', 'admitted CRDT append must emit append-latency diagnostic');
    videochat_call_app_session_lifecycle_assert((string) (((($appendPayload['result'] ?? [])['operation'] ?? [])['server_admission_stamp'] ?? [])['duplicate_policy'] ?? '') === 'ignore_after_first_admission', 'CRDT op must carry server admission stamp');
    $adminActorId = (string) (((($appendPayload['result'] ?? [])['operation'] ?? [])['actor_id'] ?? ''));

    $presenceAppend = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/ops', $adminAuth, [
        'operation' => [
            'operation_id' => 'op_admin_cursor_presence',
            'payload_type' => 'cursor.move',
            'payload' => ['x' => 20, 'y' => 30],
        ],
    ]);
    $presenceAppendPayload = videochat_call_app_session_lifecycle_decode($presenceAppend);
    videochat_call_app_session_lifecycle_assert((int) ($presenceAppend['status'] ?? 0) === 422, 'presence updates must not be persisted as CRDT ops');
    videochat_call_app_session_lifecycle_assert((string) ((((($presenceAppendPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['payload_type'] ?? '')) === 'presence_must_not_be_persisted', 'presence append must report the non-persistent payload contract');
    $presencePersistedCount = (int) $pdo->query("SELECT COUNT(*) FROM call_app_crdt_ops WHERE payload_type IN ('cursor.move', 'selection.update', 'tool.preview')")->fetchColumn();
    videochat_call_app_session_lifecycle_assert($presencePersistedCount === 0, 'presence payloads must not be written to call_app_crdt_ops');

    $deniedBootstrap = $dispatch('GET', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/bootstrap', $userAuth);
    videochat_call_app_session_lifecycle_assert((int) ($deniedBootstrap['status'] ?? 0) === 403, 'denied participant must not bootstrap private CRDT state');
    $deniedOps = $dispatch('GET', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/ops?after_clock=0&limit=10', $userAuth);
    videochat_call_app_session_lifecycle_assert((int) ($deniedOps['status'] ?? 0) === 403, 'denied participant must not replay private CRDT state');

    $readOnlyPatch = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/participant-grants', $adminAuth, [
        'grants' => [[
            'subject_type' => 'user',
            'user_id' => $regularUserId,
            'grant_state' => 'allowed',
            'permissions' => ['read' => true, 'write' => false, 'delete' => false],
        ]],
    ]);
    $readOnlyPatchPayload = videochat_call_app_session_lifecycle_decode($readOnlyPatch);
    videochat_call_app_session_lifecycle_assert((int) ($readOnlyPatch['status'] ?? 0) === 200, 'owner should re-allow participant read-only app access');
    $readOnlySession = is_array(($readOnlyPatchPayload['result'] ?? [])['session'] ?? null) ? ($readOnlyPatchPayload['result'] ?? [])['session'] : [];
    $readOnlyGrant = array_values(array_filter((array) ($readOnlySession['grants'] ?? []), static fn (array $grant): bool => (int) ($grant['user_id'] ?? 0) === $regularUserId))[0] ?? [];
    videochat_call_app_session_lifecycle_assert((int) (((($readOnlyPatchPayload['result'] ?? [])['changed_grants'] ?? [])[0] ?? [])['retired_launch_tokens'] ?? 0) === 1, 'changing a denied user grant to read-only must rotate the status-only launch token');
    videochat_call_app_session_lifecycle_assert(((array) ($readOnlyGrant['permission_actions'] ?? [])) === ['read'], 'read-only grant patch must persist only the read action');
    videochat_call_app_session_lifecycle_assert((bool) (($readOnlyGrant['permissions'] ?? [])['read'] ?? false) === true, 'read-only grant response must expose read=true');
    videochat_call_app_session_lifecycle_assert((bool) (($readOnlyGrant['permissions'] ?? [])['write'] ?? true) === false, 'read-only grant response must expose write=false');
    videochat_call_app_session_lifecycle_assert((bool) (($readOnlyGrant['permissions'] ?? [])['delete'] ?? true) === false, 'read-only grant response must expose delete=false');
    $staleDeniedLaunchValidate = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], [
        'launch_token' => $deniedStatusOnlyLaunchToken,
    ]);
    $staleDeniedLaunchValidatePayload = videochat_call_app_session_lifecycle_decode($staleDeniedLaunchValidate);
    videochat_call_app_session_lifecycle_assert((int) ($staleDeniedLaunchValidate['status'] ?? 0) === 401, 'status-only launch token must not gain CRDT rights after read-only reconnect');
    videochat_call_app_session_lifecycle_assert(videochat_call_app_session_lifecycle_error_reason($staleDeniedLaunchValidatePayload) === 'token_revoked', 'stale status-only reconnect reason mismatch');
    $regularReadOnlyLaunch = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token', $userAuth);
    $regularReadOnlyLaunchPayload = videochat_call_app_session_lifecycle_decode($regularReadOnlyLaunch);
    $regularReadOnlyCapabilities = (array) (((($regularReadOnlyLaunchPayload['result'] ?? [])['context'] ?? [])['capabilities'] ?? []));
    $regularReadOnlyActions = (array) (((($regularReadOnlyLaunchPayload['result'] ?? [])['context'] ?? [])['permission_actions'] ?? []));
    videochat_call_app_session_lifecycle_assert((int) ($regularReadOnlyLaunch['status'] ?? 0) === 201, 'read-only participant launch token should return 201');
    videochat_call_app_session_lifecycle_assert($regularReadOnlyActions === ['read'], 'read-only launch context must expose the persisted action set');
    videochat_call_app_session_lifecycle_assert(in_array('call_apps.crdt.read', $regularReadOnlyCapabilities, true), 'read-only participant launch must allow CRDT read');
    videochat_call_app_session_lifecycle_assert(!in_array('call_apps.crdt.append', $regularReadOnlyCapabilities, true), 'read-only participant launch must not allow CRDT append');
    $readOnlyBootstrap = $dispatch('GET', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/bootstrap', $userAuth);
    videochat_call_app_session_lifecycle_assert((int) ($readOnlyBootstrap['status'] ?? 0) === 200, 'read-only participant must bootstrap CRDT state');
    $readOnlyAppend = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/ops', $userAuth, [
        'operation' => [
            'operation_id' => 'op_read_only_denied',
            'payload_type' => 'sticky_note.add',
            'payload' => ['id' => 'note-read-only-denied', 'text' => 'Blocked', 'x' => 10, 'y' => 10],
        ],
    ]);
    videochat_call_app_session_lifecycle_assert((int) ($readOnlyAppend['status'] ?? 0) === 403, 'read-only participant must not append CRDT ops');

    $reallowPatch = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/participant-grants', $adminAuth, [
        'grants' => [[
            'subject_type' => 'user',
            'user_id' => $regularUserId,
            'grant_state' => 'allowed',
            'permission_actions' => ['read', 'write', 'delete'],
        ]],
    ]);
    videochat_call_app_session_lifecycle_assert((int) ($reallowPatch['status'] ?? 0) === 200, 'owner should re-allow participant app write access');
    $reallowPatchPayload = videochat_call_app_session_lifecycle_decode($reallowPatch);
    videochat_call_app_session_lifecycle_assert((int) (((($reallowPatchPayload['result'] ?? [])['changed_grants'] ?? [])[0] ?? [])['retired_launch_tokens'] ?? 0) === 1, 'broadening an active user grant must rotate stale launch tokens with old permission actions');
    $allowedGrantCount = (int) $pdo->query("SELECT COUNT(*) FROM call_app_participant_grants WHERE app_session_id = {$sessionRowId} AND user_id = {$regularUserId} AND grant_state = 'allowed' AND source = 'explicit'")->fetchColumn();
    videochat_call_app_session_lifecycle_assert($allowedGrantCount === 1, 'grant patch should persist the re-allowed state in call_app_participant_grants');
    $regularCollabLaunch = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token', $userAuth);
    $regularCollabLaunchPayload = videochat_call_app_session_lifecycle_decode($regularCollabLaunch);
    $regularCollabCapabilities = (array) (((($regularCollabLaunchPayload['result'] ?? [])['context'] ?? [])['capabilities'] ?? []));
    videochat_call_app_session_lifecycle_assert((int) ($regularCollabLaunch['status'] ?? 0) === 201, 're-allowed participant launch token should return 201');
    videochat_call_app_session_lifecycle_assert(in_array('call_apps.crdt.read', $regularCollabCapabilities, true), 're-allowed participant launch must allow CRDT read');
    videochat_call_app_session_lifecycle_assert(in_array('call_apps.crdt.append', $regularCollabCapabilities, true), 're-allowed participant launch must allow CRDT append');
    $regularAppend = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/ops', $userAuth, [
        'operation' => [
            'operation_id' => 'op_user_sticky_1',
            'payload_type' => 'sticky_note.add',
            'payload' => ['id' => 'note-user-1', 'text' => 'Second editor note', 'x' => 140, 'y' => 160],
            'causal_dependencies' => [[
                'logical_clock' => (int) (((($appendPayload['result'] ?? [])['operation'] ?? [])['logical_clock'] ?? 0)),
            ]],
        ],
    ]);
    $regularAppendPayload = videochat_call_app_session_lifecycle_decode($regularAppend);
    $regularActorId = (string) (((($regularAppendPayload['result'] ?? [])['operation'] ?? [])['actor_id'] ?? ''));
    videochat_call_app_session_lifecycle_assert((int) ($regularAppend['status'] ?? 0) === 201, 'second participant append should admit CRDT op');
    videochat_call_app_session_lifecycle_assert($regularActorId !== '' && $regularActorId !== $adminActorId, 'collaborative CRDT op must carry the second participant actor id');

    $duplicateAppend = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/ops', $adminAuth, [
        'operation' => [
            'operation_id' => 'op_admin_stroke_1',
            'payload_type' => 'stroke.add',
            'payload' => ['points' => [[10, 10], [20, 20]], 'color' => '#1582BF'],
        ],
    ]);
    $duplicatePayload = videochat_call_app_session_lifecycle_decode($duplicateAppend);
    videochat_call_app_session_lifecycle_assert((int) ($duplicateAppend['status'] ?? 0) === 200, 'duplicate CRDT op should return 200');
    videochat_call_app_session_lifecycle_assert((string) (($duplicatePayload['result'] ?? [])['state'] ?? '') === 'duplicate', 'duplicate CRDT op must be suppressed');
    videochat_call_app_session_lifecycle_assert_diagnostic($duplicatePayload, 'call_app_crdt_duplicate_suppressed', 'duplicate CRDT op must emit duplicate-suppression diagnostic');

    $ops = $dispatch('GET', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/ops?after_clock=0&limit=10', $adminAuth);
    $opsPayload = videochat_call_app_session_lifecycle_decode($ops);
    $replayedOps = (array) (($opsPayload['result'] ?? [])['ops'] ?? []);
    videochat_call_app_session_lifecycle_assert_diagnostic($opsPayload, 'call_app_crdt_replay_latency', 'CRDT replay must emit replay-latency diagnostic');
    videochat_call_app_session_lifecycle_assert(count($replayedOps) === 2, 'CRDT replay should return both collaborative admitted ops');
    videochat_call_app_session_lifecycle_assert((string) ($replayedOps[0]['payload_type'] ?? '') === 'stroke.add' && (string) ($replayedOps[1]['payload_type'] ?? '') === 'sticky_note.add', 'CRDT replay should preserve collaborative operation order');

    $snapshot = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/snapshots', $adminAuth);
    $snapshotPayload = videochat_call_app_session_lifecycle_decode($snapshot);
    videochat_call_app_session_lifecycle_assert_diagnostic($snapshotPayload, 'call_app_crdt_snapshot_compacted', 'CRDT snapshot must emit snapshot-compaction diagnostic');
    videochat_call_app_session_lifecycle_assert((int) (((($snapshotPayload['result'] ?? [])['snapshot'] ?? [])['compacted_through_clock'] ?? 0)) === 2, 'CRDT snapshot must compact through collaborative admitted clock');

    $compactedBootstrap = $dispatch('GET', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/bootstrap', $adminAuth);
    $compactedPayload = videochat_call_app_session_lifecycle_decode($compactedBootstrap);
    videochat_call_app_session_lifecycle_assert((int) (((($compactedPayload['result'] ?? [])['document'] ?? [])['snapshot_clock'] ?? 0)) === 2, 'CRDT bootstrap should expose collaborative snapshot clock after compaction');

    $sessionReactivationLaunch = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token', $adminAuth);
    $sessionReactivationLaunchPayload = videochat_call_app_session_lifecycle_decode($sessionReactivationLaunch);
    $sessionReactivationLaunchToken = (string) ((($sessionReactivationLaunchPayload['result'] ?? [])['launch_token'] ?? ''));
    videochat_call_app_session_lifecycle_assert((int) ($sessionReactivationLaunch['status'] ?? 0) === 201 && $sessionReactivationLaunchToken !== '', 'fresh admin launch token should exist before session reactivation proof');
    $inactive = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId), $adminAuth, ['status' => 'inactive']);
    videochat_call_app_session_lifecycle_assert((int) ($inactive['status'] ?? 0) === 200, 'inactive update should return 200');
    $inactiveValidate = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], [
        'launch_token' => $sessionReactivationLaunchToken,
    ]);
    $inactiveValidatePayload = videochat_call_app_session_lifecycle_decode($inactiveValidate);
    videochat_call_app_session_lifecycle_assert((int) ($inactiveValidate['status'] ?? 0) === 401, 'launch token validation must fail while Call App session is inactive');
    videochat_call_app_session_lifecycle_assert(videochat_call_app_session_lifecycle_error_reason($inactiveValidatePayload) === 'session_not_active', 'inactive launch token reason mismatch');
    $inactiveSnapshot = videochat_call_app_room_snapshot($pdo, $tenantId, $callId);
    videochat_call_app_session_lifecycle_assert((int) ($inactiveSnapshot['active_session_count'] ?? 0) === 0, 'inactive session must leave active room snapshot');

    sleep(1);
    $active = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId), $adminAuth, ['status' => 'active']);
    videochat_call_app_session_lifecycle_assert((int) ($active['status'] ?? 0) === 200, 'active update should return 200');
    $reactivatedValidate = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], [
        'launch_token' => $sessionReactivationLaunchToken,
    ]);
    $reactivatedValidatePayload = videochat_call_app_session_lifecycle_decode($reactivatedValidate);
    videochat_call_app_session_lifecycle_assert((int) ($reactivatedValidate['status'] ?? 0) === 401, 'pre-inactivation launch token must not revive after session reactivation');
    videochat_call_app_session_lifecycle_assert(videochat_call_app_session_lifecycle_error_reason($reactivatedValidatePayload) === 'token_stale_after_session_reactivation', 'session reactivation reconnect reason mismatch');
    $activeSnapshot = videochat_call_app_room_snapshot($pdo, $tenantId, $callId);
    videochat_call_app_session_lifecycle_assert((int) ($activeSnapshot['active_session_count'] ?? 0) === 1, 'reactivated session must return to active room snapshot');

    $remainingLaunchTokens = (int) $pdo->query(
        "SELECT COUNT(*) FROM call_app_launch_tokens WHERE app_session_id = {$sessionRowId} AND revoked_at IS NULL"
    )->fetchColumn();
    $realtimeFrames = [];
    $removed = $dispatch('DELETE', '/api/call-app-sessions/' . rawurlencode($sessionId), $adminAuth);
    $removedPayload = videochat_call_app_session_lifecycle_decode($removed);
    videochat_call_app_session_lifecycle_assert((int) ($removed['status'] ?? 0) === 200, 'remove should return 200');
    videochat_call_app_session_lifecycle_assert((int) (($removedPayload['result'] ?? [])['retired_launch_tokens'] ?? 0) === $remainingLaunchTokens, 'remove must retire remaining launch tokens for the collaborative journey');
    videochat_call_app_session_lifecycle_assert((int) ((($removedPayload['result'] ?? [])['room_snapshot_broadcast'] ?? [])['sent_count'] ?? 0) === 2, 'session delete should broadcast refreshed room snapshots');
    $removedRealtimeSnapshot = videochat_call_app_session_lifecycle_last_frame($realtimeFrames, 'socket-user-realtime', 'room/snapshot');
    videochat_call_app_session_lifecycle_assert((string) ($removedRealtimeSnapshot['reason'] ?? '') === 'call_app_session_removed', 'session delete snapshot reason mismatch');
    videochat_call_app_session_lifecycle_assert((int) (($removedRealtimeSnapshot['call_apps'] ?? [])['active_session_count'] ?? 0) === 0, 'session delete snapshot must remove active Call App sessions');
    $removedSnapshot = videochat_call_app_room_snapshot($pdo, $tenantId, $callId);
    videochat_call_app_session_lifecycle_assert((int) ($removedSnapshot['active_session_count'] ?? 0) === 0, 'removed session must leave active room snapshot');
    $revokedAt = (string) $pdo->query("SELECT revoked_at FROM call_app_launch_tokens WHERE public_id = " . $pdo->quote($launchTokenId) . " LIMIT 1")->fetchColumn();
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
