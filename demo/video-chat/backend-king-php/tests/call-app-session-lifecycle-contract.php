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

    $grantPatch = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/participant-grants', $adminAuth, [
        'grants' => [[
            'subject_type' => 'user',
            'user_id' => $regularUserId,
            'grant_state' => 'denied',
        ]],
    ]);
    $grantPatchPayload = videochat_call_app_session_lifecycle_decode($grantPatch);
    videochat_call_app_session_lifecycle_assert((int) ($grantPatch['status'] ?? 0) === 200, 'owner grant patch should return 200');
    videochat_call_app_session_lifecycle_assert(count((array) (($grantPatchPayload['result'] ?? [])['audit_events'] ?? [])) === 1, 'grant patch should create one audit event');
    videochat_call_app_session_lifecycle_assert((int) (((($grantPatchPayload['result'] ?? [])['changed_grants'] ?? [])[0] ?? [])['retired_launch_tokens'] ?? 0) === 1, 'denying a participant must revoke their active launch token');
    videochat_call_app_session_lifecycle_assert((int) (((($grantPatchPayload['result'] ?? [])['audit_events'] ?? [])[0] ?? [])['payload']['retired_launch_tokens'] ?? 0) === 1, 'grant audit must record retired launch tokens');
    $patchedSession = is_array(($grantPatchPayload['result'] ?? [])['session'] ?? null) ? ($grantPatchPayload['result'] ?? [])['session'] : [];
    $regularGrant = array_values(array_filter((array) ($patchedSession['grants'] ?? []), static fn (array $grant): bool => (int) ($grant['user_id'] ?? 0) === $regularUserId))[0] ?? [];
    videochat_call_app_session_lifecycle_assert((string) ($regularGrant['grant_state'] ?? '') === 'denied', 'regular user grant should be denied after patch');
    $regularTokenRevokedAt = (string) $pdo->query("SELECT revoked_at FROM call_app_launch_tokens WHERE public_id = " . $pdo->quote($regularAllowedLaunchTokenId) . " LIMIT 1")->fetchColumn();
    videochat_call_app_session_lifecycle_assert($regularTokenRevokedAt !== '', 'denied participant active launch token must be revoked');
    $revokedRegularValidate = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], [
        'launch_token' => $regularAllowedLaunchToken,
    ]);
    videochat_call_app_session_lifecycle_assert((int) ($revokedRegularValidate['status'] ?? 0) === 401, 'revoked participant launch token must fail reconnect validation');

    $grantList = $dispatch('GET', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/participant-grants', $adminAuth);
    $grantListPayload = videochat_call_app_session_lifecycle_decode($grantList);
    videochat_call_app_session_lifecycle_assert((int) ($grantList['status'] ?? 0) === 200, 'grant list should return 200');
    videochat_call_app_session_lifecycle_assert(count((array) (($grantListPayload['result'] ?? [])['audit_events'] ?? [])) >= 1, 'grant list should include audit events');
    videochat_call_app_session_lifecycle_assert((int) (((($grantListPayload['result'] ?? [])['audit_events'] ?? [])[0] ?? [])['payload']['retired_launch_tokens'] ?? 0) === 1, 'grant list audit trail should expose revocation metadata');
    $auditCount = (int) $pdo->query("SELECT COUNT(*) FROM call_app_audit_events WHERE app_session_id = {$sessionRowId} AND event_type = 'participant_grant_changed'")->fetchColumn();
    videochat_call_app_session_lifecycle_assert($auditCount === 1, 'grant patch should persist exactly one audit event');

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

    $invalidLaunch = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/launch-token/validate', [], [
        'launch_token' => 'not-a-real-launch-token',
    ]);
    videochat_call_app_session_lifecycle_assert((int) ($invalidLaunch['status'] ?? 0) === 401, 'invalid launch token should fail closed');

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

    $reallowPatch = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/participant-grants', $adminAuth, [
        'grants' => [[
            'subject_type' => 'user',
            'user_id' => $regularUserId,
            'grant_state' => 'allowed',
        ]],
    ]);
    videochat_call_app_session_lifecycle_assert((int) ($reallowPatch['status'] ?? 0) === 200, 'owner should re-allow participant app access');
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

    $ops = $dispatch('GET', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/ops?after_clock=0&limit=10', $adminAuth);
    $opsPayload = videochat_call_app_session_lifecycle_decode($ops);
    $replayedOps = (array) (($opsPayload['result'] ?? [])['ops'] ?? []);
    videochat_call_app_session_lifecycle_assert(count($replayedOps) === 2, 'CRDT replay should return both collaborative admitted ops');
    videochat_call_app_session_lifecycle_assert((string) ($replayedOps[0]['payload_type'] ?? '') === 'stroke.add' && (string) ($replayedOps[1]['payload_type'] ?? '') === 'sticky_note.add', 'CRDT replay should preserve collaborative operation order');

    $snapshot = $dispatch('POST', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/snapshots', $adminAuth);
    $snapshotPayload = videochat_call_app_session_lifecycle_decode($snapshot);
    videochat_call_app_session_lifecycle_assert((int) (((($snapshotPayload['result'] ?? [])['snapshot'] ?? [])['compacted_through_clock'] ?? 0)) === 2, 'CRDT snapshot must compact through collaborative admitted clock');

    $compactedBootstrap = $dispatch('GET', '/api/call-app-sessions/' . rawurlencode($sessionId) . '/crdt/bootstrap', $adminAuth);
    $compactedPayload = videochat_call_app_session_lifecycle_decode($compactedBootstrap);
    videochat_call_app_session_lifecycle_assert((int) (((($compactedPayload['result'] ?? [])['document'] ?? [])['snapshot_clock'] ?? 0)) === 2, 'CRDT bootstrap should expose collaborative snapshot clock after compaction');

    $inactive = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId), $adminAuth, ['status' => 'inactive']);
    videochat_call_app_session_lifecycle_assert((int) ($inactive['status'] ?? 0) === 200, 'inactive update should return 200');
    $inactiveSnapshot = videochat_call_app_room_snapshot($pdo, $tenantId, $callId);
    videochat_call_app_session_lifecycle_assert((int) ($inactiveSnapshot['active_session_count'] ?? 0) === 0, 'inactive session must leave active room snapshot');

    $active = $dispatch('PATCH', '/api/call-app-sessions/' . rawurlencode($sessionId), $adminAuth, ['status' => 'active']);
    videochat_call_app_session_lifecycle_assert((int) ($active['status'] ?? 0) === 200, 'active update should return 200');
    $activeSnapshot = videochat_call_app_room_snapshot($pdo, $tenantId, $callId);
    videochat_call_app_session_lifecycle_assert((int) ($activeSnapshot['active_session_count'] ?? 0) === 1, 'reactivated session must return to active room snapshot');

    $removed = $dispatch('DELETE', '/api/call-app-sessions/' . rawurlencode($sessionId), $adminAuth);
    $removedPayload = videochat_call_app_session_lifecycle_decode($removed);
    videochat_call_app_session_lifecycle_assert((int) ($removed['status'] ?? 0) === 200, 'remove should return 200');
    videochat_call_app_session_lifecycle_assert((int) (($removedPayload['result'] ?? [])['retired_launch_tokens'] ?? 0) === 3, 'remove must retire active launch tokens for the collaborative journey');
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
