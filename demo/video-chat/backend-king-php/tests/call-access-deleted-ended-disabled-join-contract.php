<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';
require_once __DIR__ . '/../http/module_calls_access.php';

function videochat_iam7_11_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-deleted-ended-disabled-join-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam7_11_json_response(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function videochat_iam7_11_error_response(int $status, string $code, string $message, array $details = []): array
{
    $error = [
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== []) {
        $error['details'] = $details;
    }

    return videochat_iam7_11_json_response($status, [
        'status' => 'error',
        'error' => $error,
        'time' => gmdate('c'),
    ]);
}

function videochat_iam7_11_decode_json_body(array $request): array
{
    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return [null, 'empty_body'];
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
}

function videochat_iam7_11_decode_response(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<int, string|int|null> $needles
 */
function videochat_iam7_11_assert_body_omits(array $response, array $needles, string $label): void
{
    $body = strtolower((string) ($response['body'] ?? ''));
    foreach ($needles as $needle) {
        $normalized = strtolower(trim((string) $needle));
        if ($normalized === '') {
            continue;
        }
        videochat_iam7_11_assert(!str_contains($body, $normalized), "{$label} leaked {$needle}");
    }
}

function videochat_iam7_11_create_user(PDO $pdo, int $tenantId, string $email, string $displayName): int
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_iam7_11_assert($roleId > 0, 'user role should exist');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower(trim($email)),
        ':display_name' => $displayName,
        ':password_hash' => password_hash('iam7-11-contract', PASSWORD_DEFAULT),
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_iam7_11_assert($userId > 0, 'created user id should be positive');
    videochat_tenant_attach_user($pdo, $userId, $tenantId, 'member');

    return $userId;
}

function videochat_iam7_11_create_call(
    PDO $pdo,
    int $ownerUserId,
    int $participantUserId,
    int $tenantId,
    string $title,
    string $accessMode = 'invite_only'
): array {
    $result = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => $accessMode,
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => $participantUserId > 0 ? [$participantUserId] : [],
        'external_participants' => [],
    ], $tenantId);
    videochat_iam7_11_assert((bool) ($result['ok'] ?? false), "{$title} should be created");
    $call = is_array($result['call'] ?? null) ? $result['call'] : [];
    videochat_iam7_11_assert((string) ($call['id'] ?? '') !== '', "{$title} call id should be present");
    videochat_iam7_11_assert((string) ($call['room_id'] ?? '') !== '', "{$title} room id should be present");

    return $call;
}

function videochat_iam7_11_create_access_link(
    PDO $pdo,
    string $callId,
    int $adminUserId,
    int $participantUserId,
    int $tenantId,
    string $kind
): string {
    $options = ['link_kind' => $kind];
    if ($kind === 'personal') {
        $options['participant_user_id'] = $participantUserId;
    }
    $result = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', $options, $tenantId);
    videochat_iam7_11_assert((bool) ($result['ok'] ?? false), "{$kind} link should be created");
    $accessId = (string) (($result['access_link'] ?? [])['id'] ?? '');
    videochat_iam7_11_assert($accessId !== '', "{$kind} access id should be present");

    return $accessId;
}

function videochat_iam7_11_issue_session(
    PDO $pdo,
    string $accessId,
    string $sessionId,
    string $guestName = ''
): array {
    $options = $guestName === '' ? [] : ['guest_name' => $guestName];
    $result = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-deleted-ended-disabled-join-contract'],
        $options
    );
    videochat_iam7_11_assert((bool) ($result['ok'] ?? false), "{$sessionId} should issue before terminal state");

    return $result;
}

function videochat_iam7_11_auth(int $userId, string $sessionId, int $tenantId, string $role = 'user'): array
{
    return [
        'ok' => true,
        'user' => ['id' => $userId, 'role' => $role],
        'session' => ['id' => $sessionId],
        'tenant' => ['id' => $tenantId],
    ];
}

function videochat_iam7_11_route(
    string $databasePath,
    string $path,
    string $method,
    string $body = '',
    ?callable $issueSessionId = null
): array {
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $response = videochat_handle_call_access_routes(
        $path,
        $method,
        ['method' => $method, 'uri' => $path, 'headers' => [], 'body' => $body, 'remote_address' => '127.0.0.1'],
        [],
        'videochat_iam7_11_json_response',
        'videochat_iam7_11_error_response',
        'videochat_iam7_11_decode_json_body',
        $openDatabase,
        $issueSessionId
    );
    videochat_iam7_11_assert(is_array($response), "{$path} should return a response");

    return $response;
}

function videochat_iam7_11_transition_call(PDO $pdo, string $state, string $callId, int $adminUserId, int $tenantId): void
{
    if ($state === 'ended') {
        $update = $pdo->prepare('UPDATE calls SET status = :status, updated_at = :updated_at WHERE id = :id');
        $update->execute([':status' => 'ended', ':updated_at' => gmdate('c'), ':id' => $callId]);
        videochat_iam7_11_assert($update->rowCount() === 1, 'ended transition should update one call');
        return;
    }

    if ($state === 'cancelled') {
        $cancelled = videochat_cancel_call($pdo, $callId, $adminUserId, 'admin', [
            'cancel_reason' => 'iam7_11_terminal_contract',
            'cancel_message' => 'Terminal call-access proof',
        ], $tenantId);
        videochat_iam7_11_assert((bool) ($cancelled['ok'] ?? false), 'cancel transition should succeed');
        return;
    }

    if ($state === 'deleted') {
        $deleted = videochat_delete_call($pdo, $callId, $adminUserId, 'admin', $tenantId);
        videochat_iam7_11_assert((bool) ($deleted['ok'] ?? false), 'delete transition should succeed');
        return;
    }

    videochat_iam7_11_assert(false, "unsupported terminal state {$state}");
}

function videochat_iam7_11_assert_failed_result_redacted(array $result, string $label): void
{
    videochat_iam7_11_assert(!(bool) ($result['ok'] ?? true), "{$label} should fail");
    videochat_iam7_11_assert(($result['access_link'] ?? null) === null, "{$label} must redact access link");
    videochat_iam7_11_assert(($result['call'] ?? null) === null, "{$label} must redact call");
}

function videochat_iam7_11_assert_terminal_case(
    PDO $pdo,
    string $databasePath,
    int $tenantId,
    int $adminUserId,
    int $participantUserId,
    string $state
): void {
    $personalCall = videochat_iam7_11_create_call($pdo, $adminUserId, $participantUserId, $tenantId, "IAM7-11 {$state} Personal Secret");
    $personalAccessId = videochat_iam7_11_create_access_link($pdo, (string) $personalCall['id'], $adminUserId, $participantUserId, $tenantId, 'personal');
    videochat_iam7_11_issue_session($pdo, $personalAccessId, "sess_iam7_11_{$state}_personal");

    $openCall = videochat_iam7_11_create_call($pdo, $adminUserId, 0, $tenantId, "IAM7-11 {$state} Open Secret", 'free_for_all');
    $openAccessId = videochat_iam7_11_create_access_link($pdo, (string) $openCall['id'], $adminUserId, 0, $tenantId, 'open');
    $openSession = videochat_iam7_11_issue_session($pdo, $openAccessId, "sess_iam7_11_{$state}_open", "Terminal {$state} Guest");
    $openUserId = (int) (($openSession['user'] ?? [])['id'] ?? 0);
    videochat_iam7_11_assert($openUserId > 0, "{$state} open session user should be present before transition");

    videochat_iam7_11_transition_call($pdo, $state, (string) $personalCall['id'], $adminUserId, $tenantId);
    videochat_iam7_11_transition_call($pdo, $state, (string) $openCall['id'], $adminUserId, $tenantId);

    $personalResolve = videochat_resolve_call_access_public($pdo, $personalAccessId);
    videochat_iam7_11_assert_failed_result_redacted($personalResolve, "{$state} personal public resolve");
    $openResolve = videochat_resolve_call_access_public($pdo, $openAccessId);
    videochat_iam7_11_assert_failed_result_redacted($openResolve, "{$state} open public resolve");

    $issuerCalls = 0;
    $openRoute = videochat_iam7_11_route(
        $databasePath,
        '/api/call-access/' . $openAccessId . '/session',
        'POST',
        '{"guest_name":"Terminal Route Guest"}',
        static function () use (&$issuerCalls, $state): string {
            $issuerCalls += 1;
            return "sess_iam7_11_{$state}_route_must_not_issue";
        }
    );
    $expectedOpenStatus = $state === 'deleted' ? 404 : 409;
    videochat_iam7_11_assert((int) ($openRoute['status'] ?? 0) === $expectedOpenStatus, "{$state} open session route status mismatch");
    videochat_iam7_11_assert($issuerCalls === 0, "{$state} terminal route must not call session issuer");
    videochat_iam7_11_assert_body_omits($openRoute, [
        $openCall['title'] ?? '',
        $openAccessId,
        "sess_iam7_11_{$state}_route_must_not_issue",
        'terminal route guest',
    ], "{$state} open session route");

    videochat_iam7_11_assert(videochat_fetch_call_access_session_binding($pdo, "sess_iam7_11_{$state}_personal") === null, "{$state} stale personal binding fetch should be quarantined");
    videochat_iam7_11_assert(videochat_fetch_call_access_session_binding($pdo, "sess_iam7_11_{$state}_open") === null, "{$state} stale open binding fetch should be quarantined");
    if ($state !== 'deleted') {
        $personalBinding = videochat_validate_call_access_session_binding($pdo, "sess_iam7_11_{$state}_personal", $participantUserId);
        videochat_iam7_11_assert(!(bool) ($personalBinding['ok'] ?? true), "{$state} stale personal binding should fail");
        $openBinding = videochat_validate_call_access_session_binding($pdo, "sess_iam7_11_{$state}_open", $openUserId);
        videochat_iam7_11_assert(!(bool) ($openBinding['ok'] ?? true), "{$state} stale open binding should fail");
    }

    $directJoin = videochat_user_can_direct_join_call($pdo, (string) $personalCall['id'], $participantUserId, 'user', $tenantId);
    videochat_iam7_11_assert(!(bool) ($directJoin['ok'] ?? true), "{$state} guest-list direct join should fail");

    $openAuth = videochat_iam7_11_auth($openUserId, "sess_iam7_11_{$state}_open", $tenantId);
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $roomResolution = videochat_realtime_resolve_connection_rooms(
        $openAuth,
        (string) ($openCall['room_id'] ?? ''),
        $openDatabase,
        (string) ($openCall['id'] ?? '')
    );
    videochat_iam7_11_assert(
        (string) ($roomResolution['initial_room_id'] ?? '') !== (string) ($openCall['room_id'] ?? ''),
        "{$state} stale call-access session must not reconnect directly to terminal room"
    );

    $staleConnection = [
        'user_id' => $adminUserId,
        'role' => 'user',
        'room_id' => (string) ($personalCall['room_id'] ?? ''),
        'requested_room_id' => (string) ($personalCall['room_id'] ?? ''),
        'pending_room_id' => (string) ($personalCall['room_id'] ?? ''),
        'requested_call_id' => (string) ($personalCall['id'] ?? ''),
        'active_call_id' => (string) ($personalCall['id'] ?? ''),
        'call_role' => 'owner',
        'invite_state' => 'allowed',
        'tenant_id' => $tenantId,
    ];
    videochat_iam7_11_assert(
        !videochat_realtime_connection_can_bypass_admission_for_room($staleConnection, (string) ($personalCall['room_id'] ?? ''), $openDatabase),
        "{$state} cached owner context must not bypass terminal admission"
    );
    videochat_iam7_11_assert(
        !videochat_realtime_connection_can_join_call_scoped_room($staleConnection, (string) ($personalCall['room_id'] ?? ''), $openDatabase),
        "{$state} cached owner connection must not rejoin terminal room"
    );
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-deleted-ended-disabled-join-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-iam7-11-deleted-ended-disabled-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $standardUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_iam7_11_assert($tenantId > 0 && $adminUserId > 0 && $standardUserId > 0, 'seed ids should exist');

    $activeCall = videochat_iam7_11_create_call($pdo, $adminUserId, $standardUserId, $tenantId, 'IAM7-11 Active Preserve Secret');
    $activeAccessId = videochat_iam7_11_create_access_link($pdo, (string) $activeCall['id'], $adminUserId, $standardUserId, $tenantId, 'personal');
    videochat_iam7_11_assert((bool) (videochat_resolve_call_access_public($pdo, $activeAccessId)['ok'] ?? false), 'active public access should resolve');
    $activeSession = videochat_iam7_11_issue_session($pdo, $activeAccessId, 'sess_iam7_11_active_personal');
    videochat_iam7_11_assert((int) (($activeSession['user'] ?? [])['id'] ?? 0) === $standardUserId, 'active session target user mismatch');
    videochat_iam7_11_assert((bool) (videochat_validate_call_access_session_binding($pdo, 'sess_iam7_11_active_personal', $standardUserId)['ok'] ?? false), 'active session binding should validate');
    videochat_iam7_11_assert((bool) (videochat_user_can_direct_join_call($pdo, (string) $activeCall['id'], $standardUserId, 'user', $tenantId)['ok'] ?? false), 'active guest-list user should direct join');
    $pdo->prepare("UPDATE call_participants SET invite_state = 'allowed' WHERE call_id = :call_id AND user_id = :user_id")->execute([
        ':call_id' => (string) $activeCall['id'],
        ':user_id' => $standardUserId,
    ]);
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $activeRooms = videochat_realtime_resolve_connection_rooms(
        videochat_iam7_11_auth($standardUserId, 'sess_iam7_11_active_personal', $tenantId),
        (string) $activeCall['room_id'],
        $openDatabase,
        (string) $activeCall['id']
    );
    videochat_iam7_11_assert((string) ($activeRooms['initial_room_id'] ?? '') === (string) $activeCall['room_id'], 'active allowed participant should resolve to call room');

    foreach (['ended', 'cancelled', 'deleted'] as $state) {
        videochat_iam7_11_assert_terminal_case($pdo, $databasePath, $tenantId, $adminUserId, $standardUserId, $state);
    }

    $disabledUserId = videochat_iam7_11_create_user($pdo, $tenantId, 'iam7-11-disabled@example.test', 'IAM7-11 Disabled User');
    $disabledCall = videochat_iam7_11_create_call($pdo, $adminUserId, $disabledUserId, $tenantId, 'IAM7-11 Disabled User Secret');
    $disabledAccessId = videochat_iam7_11_create_access_link($pdo, (string) $disabledCall['id'], $adminUserId, $disabledUserId, $tenantId, 'personal');
    videochat_iam7_11_issue_session($pdo, $disabledAccessId, 'sess_iam7_11_disabled_user');
    $pdo->prepare("UPDATE users SET status = 'disabled', updated_at = :updated_at WHERE id = :id")->execute([
        ':updated_at' => gmdate('c'),
        ':id' => $disabledUserId,
    ]);
    videochat_iam7_11_assert_failed_result_redacted(videochat_resolve_call_access_public($pdo, $disabledAccessId), 'disabled user public resolve');
    $disabledBinding = videochat_validate_call_access_session_binding($pdo, 'sess_iam7_11_disabled_user', $disabledUserId);
    videochat_iam7_11_assert(!(bool) ($disabledBinding['ok'] ?? true), 'disabled user stale binding should fail');
    videochat_iam7_11_assert((string) ($disabledBinding['reason'] ?? '') === 'call_access_user_inactive', 'disabled user stale binding reason mismatch');
    videochat_iam7_11_assert(videochat_fetch_call_access_session_binding($pdo, 'sess_iam7_11_disabled_user') === null, 'disabled user binding fetch should be quarantined');

    $staleInviteCall = videochat_iam7_11_create_call($pdo, $adminUserId, $standardUserId, $tenantId, 'IAM7-11 Stale Invitation Secret');
    $staleInviteAccessId = videochat_iam7_11_create_access_link($pdo, (string) $staleInviteCall['id'], $adminUserId, $standardUserId, $tenantId, 'personal');
    $pdo->prepare("UPDATE call_participants SET invite_state = 'cancelled' WHERE call_id = :call_id AND user_id = :user_id")->execute([
        ':call_id' => (string) $staleInviteCall['id'],
        ':user_id' => $standardUserId,
    ]);
    $staleRoute = videochat_iam7_11_route($databasePath, '/api/call-access/' . $staleInviteAccessId . '/join', 'GET');
    videochat_iam7_11_assert((int) ($staleRoute['status'] ?? 0) === 404, 'stale invitation route should return safe not found');
    videochat_iam7_11_assert_body_omits($staleRoute, [$staleInviteCall['title'] ?? '', $staleInviteAccessId, 'user@intelligent-intern.com'], 'stale invitation route');
    videochat_iam7_11_assert(!(bool) (videochat_user_can_direct_join_call($pdo, (string) $staleInviteCall['id'], $standardUserId, 'user', $tenantId)['ok'] ?? true), 'cancelled guest-list invite must not direct join');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-deleted-ended-disabled-join-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-deleted-ended-disabled-join-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
