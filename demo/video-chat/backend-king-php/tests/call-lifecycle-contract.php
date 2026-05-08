<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/audit/audit_events.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_connection_contract.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_presence_db.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_lifecycle_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-lifecycle-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_lifecycle_contract_user(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT id, email, display_name, status, password_hash FROM users WHERE id = :id LIMIT 1');
    $query->execute([':id' => $userId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function videochat_call_lifecycle_contract_json_response(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function videochat_call_lifecycle_contract_error_response(int $status, string $code, string $message, array $details = []): array
{
    $error = ['code' => $code, 'message' => $message];
    if ($details !== []) {
        $error['details'] = $details;
    }

    return videochat_call_lifecycle_contract_json_response($status, [
        'status' => 'error',
        'error' => $error,
        'time' => gmdate('c'),
    ]);
}

function videochat_call_lifecycle_contract_decode_body(array $request): array
{
    $payload = json_decode((string) ($request['body'] ?? ''), true);
    return is_array($payload) ? [$payload, null] : [null, 'invalid_json'];
}

function videochat_call_lifecycle_contract_response_payload(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function videochat_call_lifecycle_contract_participant(PDO $pdo, string $callId, int $userId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
LIMIT 1
SQL
    );
    $query->execute([
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function videochat_call_lifecycle_contract_count(PDO $pdo, string $sql, array $params = []): int
{
    $query = $pdo->prepare($sql);
    $query->execute($params);
    return max(0, (int) ($query->fetchColumn() ?: 0));
}

function videochat_call_lifecycle_contract_session_revoked(PDO $pdo, string $sessionId): bool
{
    return videochat_call_lifecycle_contract_count(
        $pdo,
        'SELECT COUNT(*) FROM sessions WHERE id = :id AND revoked_at IS NOT NULL AND revoked_at <> \'\'',
        [':id' => $sessionId]
    ) === 1;
}

function videochat_call_lifecycle_contract_presence_count(PDO $pdo, string $callId): int
{
    return videochat_call_lifecycle_contract_count(
        $pdo,
        'SELECT COUNT(*) FROM realtime_presence_connections WHERE call_id = :call_id',
        [':call_id' => $callId]
    );
}

function videochat_call_lifecycle_contract_link_count(PDO $pdo, string $callId): int
{
    return videochat_call_lifecycle_contract_count(
        $pdo,
        'SELECT COUNT(*) FROM call_access_links WHERE call_id = :call_id',
        [':call_id' => $callId]
    );
}

function videochat_call_lifecycle_contract_add_presence(
    PDO $pdo,
    string $callId,
    string $roomId,
    int $userId,
    string $sessionId,
    string $suffix,
    string $callRole = 'participant'
): void {
    $ok = videochat_realtime_presence_db_upsert($pdo, [
        'connection_id' => 'conn_call_lifecycle_' . $suffix,
        'session_id' => $sessionId,
        'room_id' => $roomId,
        'active_call_id' => $callId,
        'requested_call_id' => $callId,
        'user_id' => $userId,
        'display_name' => 'Lifecycle User ' . $suffix,
        'role' => 'user',
        'call_role' => $callRole,
        'connected_at' => gmdate('c'),
    ]);
    videochat_call_lifecycle_contract_assert($ok, 'presence upsert should succeed for ' . $suffix);
}

function videochat_call_lifecycle_contract_events(PDO $pdo, int $tenantId, string $callId, string $eventType): array
{
    return videochat_audit_fetch_events($pdo, [
        'tenant_id' => $tenantId,
        'call_id' => $callId,
        'event_type' => $eventType,
        'limit' => 20,
    ]);
}

function videochat_call_lifecycle_contract_latest_event(PDO $pdo, int $tenantId, string $callId, string $eventType): array
{
    $events = videochat_call_lifecycle_contract_events($pdo, $tenantId, $callId, $eventType);
    return $events === [] ? [] : $events[count($events) - 1];
}

function videochat_call_lifecycle_contract_assert_lifecycle_audit(
    PDO $pdo,
    int $tenantId,
    string $callId,
    string $eventType,
    string $transition,
    int $minInvalidatedLinks,
    int $minRevokedSessions,
    int $minClearedPresence
): void {
    $event = videochat_call_lifecycle_contract_latest_event($pdo, $tenantId, $callId, $eventType);
    videochat_call_lifecycle_contract_assert($event !== [], $eventType . ' audit event should exist');
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    videochat_call_lifecycle_contract_assert((string) ($payload['transition'] ?? '') === $transition, $eventType . ' transition mismatch');
    videochat_call_lifecycle_contract_assert((int) ($payload['link_invalidated_count'] ?? -1) >= $minInvalidatedLinks, $eventType . ' link count mismatch');
    videochat_call_lifecycle_contract_assert((int) ($payload['revoked_access_session_count'] ?? -1) >= $minRevokedSessions, $eventType . ' revoked session count mismatch');
    videochat_call_lifecycle_contract_assert((int) ($payload['presence_cleared_count'] ?? -1) >= $minClearedPresence, $eventType . ' presence count mismatch');
    videochat_call_lifecycle_contract_assert(($payload['registered_accounts_deleted'] ?? null) === false, $eventType . ' must record registered-account preservation');
    videochat_call_lifecycle_contract_assert(($payload['raw_access_identifier_logged'] ?? null) === false, $eventType . ' must not log raw access identifiers');
}

function videochat_call_lifecycle_contract_assert_guest_cleanup_event(PDO $pdo, int $tenantId, string $callId): void
{
    $event = videochat_call_lifecycle_contract_latest_event($pdo, $tenantId, $callId, 'guest_account_cleanup');
    videochat_call_lifecycle_contract_assert($event !== [], 'guest cleanup audit event should exist for ' . $callId);
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    videochat_call_lifecycle_contract_assert(($payload['idempotent_safe'] ?? null) === true, 'guest cleanup audit should document idempotency');
    videochat_call_lifecycle_contract_assert(($payload['raw_guest_identifiers_logged'] ?? null) === false, 'guest cleanup audit must not log raw guest identifiers');
}

function videochat_call_lifecycle_contract_assert_no_audit_leak(PDO $pdo, string $callId, array $forbiddenValues): void
{
    $events = videochat_audit_fetch_events($pdo, ['call_id' => $callId, 'limit' => 100]);
    $encoded = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_call_lifecycle_contract_assert(is_string($encoded), 'audit events should encode');
    foreach ($forbiddenValues as $value) {
        $needle = trim((string) $value);
        if ($needle === '') {
            continue;
        }
        videochat_call_lifecycle_contract_assert(
            !str_contains($encoded, $needle),
            'audit records must not leak raw lifecycle identifier: ' . $needle
        );
    }
}

function videochat_call_lifecycle_contract_access_id(array $result): string
{
    return (string) (($result['access_link'] ?? [])['id'] ?? '');
}

function videochat_call_lifecycle_contract_issue_session(
    PDO $pdo,
    string $accessId,
    string $sessionId,
    array $options = []
): array {
    return videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-lifecycle-contract'],
        $options
    );
}

function videochat_call_lifecycle_contract_assert_auth_denied(PDO $pdo, string $sessionId, string $callId): void
{
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $sessionId . '&room=' . $callId . '&call_id=' . $callId,
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_call_lifecycle_contract_assert(!(bool) ($auth['ok'] ?? true), 'stale active session must not authenticate: ' . $sessionId);
}

function videochat_call_lifecycle_contract_create_temp_guest(PDO $pdo, string $name, int $tenantId): array
{
    $result = videochat_create_guest_user_for_call_access($pdo, $name, $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($result['ok'] ?? false), 'guest should be created: ' . $name);
    $user = is_array($result['user'] ?? null) ? $result['user'] : [];
    videochat_call_lifecycle_contract_assert((int) ($user['id'] ?? 0) > 0, 'guest id should be present: ' . $name);
    return $user;
}

function videochat_call_lifecycle_contract_create_personal_link(
    PDO $pdo,
    string $callId,
    int $ownerUserId,
    int $participantUserId,
    int $tenantId
): string {
    $result = videochat_create_call_access_link_for_user($pdo, $callId, $ownerUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $participantUserId,
    ], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($result['ok'] ?? false), 'personal access link should be created');
    $accessId = videochat_call_lifecycle_contract_access_id($result);
    videochat_call_lifecycle_contract_assert($accessId !== '', 'personal access id should be present');
    return $accessId;
}

function videochat_call_lifecycle_contract_create_open_link(
    PDO $pdo,
    string $callId,
    int $ownerUserId,
    int $tenantId
): string {
    $result = videochat_create_call_access_link_for_user($pdo, $callId, $ownerUserId, 'admin', [
        'link_kind' => 'open',
    ], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($result['ok'] ?? false), 'open access link should be created');
    $accessId = videochat_call_lifecycle_contract_access_id($result);
    videochat_call_lifecycle_contract_assert($accessId !== '', 'open access id should be present');
    return $accessId;
}

function videochat_call_lifecycle_contract_lobby_waiting_count(PDO $pdo, string $callId): int
{
    return videochat_call_lifecycle_contract_count(
        $pdo,
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND source = 'internal'
  AND coalesce(call_role, 'participant') <> 'owner'
  AND invite_state IN ('pending', 'allowed')
  AND (joined_at IS NULL OR joined_at = '')
SQL,
        [':call_id' => $callId]
    );
}

function videochat_call_lifecycle_contract_insert_late_retained_link(
    PDO $pdo,
    string $callId,
    int $ownerUserId,
    int $participantUserId,
    int $tenantId
): string {
    $accessId = videochat_generate_call_access_uuid();
    $tenantColumn = videochat_tenant_table_has_column($pdo, 'call_access_links', 'tenant_id') ? ', tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :tenant_id' : '';
    $insert = $pdo->prepare(
        <<<SQL
INSERT INTO call_access_links(
    id,
    call_id,
    participant_user_id,
    participant_email,
    invite_code_id,
    created_by_user_id,
    created_at,
    expires_at,
    last_used_at,
    consumed_at{$tenantColumn}
) VALUES(
    :id,
    :call_id,
    :participant_user_id,
    NULL,
    NULL,
    :created_by_user_id,
    :created_at,
    :expires_at,
    NULL,
    NULL{$tenantValue}
)
SQL
    );
    $params = [
        ':id' => $accessId,
        ':call_id' => $callId,
        ':participant_user_id' => $participantUserId,
        ':created_by_user_id' => $ownerUserId,
        ':created_at' => gmdate('c'),
        ':expires_at' => gmdate('c', time() + 3600),
    ];
    if ($tenantColumn !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $insert->execute($params);

    return $accessId;
}

function videochat_call_lifecycle_contract_mark_joined(PDO $pdo, string $callId, int $userId, string $state): void
{
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = :invite_state,
    joined_at = :joined_at,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    );
    $update->execute([
        ':invite_state' => $state,
        ':joined_at' => '2026-10-10T09:05:00Z',
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
}

$databasePath = '';

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-lifecycle-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-lifecycle-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $openDatabase = static fn (): PDO => $pdo;

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $registeredUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_lifecycle_contract_assert($tenantId > 0 && $adminUserId > 0 && $registeredUserId > 0, 'fixture ids missing');
    $registeredBefore = videochat_call_lifecycle_contract_user($pdo, $registeredUserId);
    videochat_call_lifecycle_contract_assert((string) ($registeredBefore['status'] ?? '') === 'active', 'registered fixture must start active');

    $rescheduleCreate = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Lifecycle Reschedule Call',
        'starts_at' => '2026-10-10T09:00:00Z',
        'ends_at' => '2026-10-10T10:00:00Z',
        'internal_participant_user_ids' => [$registeredUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($rescheduleCreate['ok'] ?? false), 'reschedule call should be created');
    $rescheduleCall = is_array($rescheduleCreate['call'] ?? null) ? $rescheduleCreate['call'] : [];
    $rescheduleCallId = (string) ($rescheduleCall['id'] ?? '');
    $rescheduleRoomId = (string) ($rescheduleCall['room_id'] ?? '');
    videochat_call_lifecycle_contract_assert($rescheduleCallId !== '' && $rescheduleRoomId !== '', 'reschedule call identity missing');

    $rescheduleGuest = videochat_call_lifecycle_contract_create_temp_guest($pdo, 'Lifecycle Reschedule Guest', $tenantId);
    $rescheduleGuestId = (int) ($rescheduleGuest['id'] ?? 0);
    videochat_ensure_internal_call_participant($pdo, $rescheduleCallId, $rescheduleGuestId, (string) ($rescheduleGuest['email'] ?? ''), (string) ($rescheduleGuest['display_name'] ?? ''), 'allowed');
    videochat_call_lifecycle_contract_mark_joined($pdo, $rescheduleCallId, $registeredUserId, 'accepted');
    videochat_call_lifecycle_contract_mark_joined($pdo, $rescheduleCallId, $rescheduleGuestId, 'allowed');

    $rescheduleRegisteredAccessId = videochat_call_lifecycle_contract_create_personal_link($pdo, $rescheduleCallId, $adminUserId, $registeredUserId, $tenantId);
    $rescheduleGuestAccessId = videochat_call_lifecycle_contract_create_personal_link($pdo, $rescheduleCallId, $adminUserId, $rescheduleGuestId, $tenantId);
    $rescheduleRegisteredSessionId = 'sess_call_lifecycle_reschedule_registered';
    $rescheduleGuestSessionId = 'sess_call_lifecycle_reschedule_guest';
    videochat_call_lifecycle_contract_assert((bool) (videochat_call_lifecycle_contract_issue_session($pdo, $rescheduleRegisteredAccessId, $rescheduleRegisteredSessionId)['ok'] ?? false), 'registered reschedule session should issue');
    videochat_call_lifecycle_contract_assert((bool) (videochat_call_lifecycle_contract_issue_session($pdo, $rescheduleGuestAccessId, $rescheduleGuestSessionId)['ok'] ?? false), 'guest reschedule session should issue');
    videochat_call_lifecycle_contract_add_presence($pdo, $rescheduleCallId, $rescheduleRoomId, $registeredUserId, $rescheduleRegisteredSessionId, 'reschedule_registered');
    videochat_call_lifecycle_contract_add_presence($pdo, $rescheduleCallId, $rescheduleRoomId, $rescheduleGuestId, $rescheduleGuestSessionId, 'reschedule_guest');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_presence_count($pdo, $rescheduleCallId) === 2, 'reschedule setup should have two presence rows');

    $rescheduleUpdate = videochat_update_call($pdo, $rescheduleCallId, $adminUserId, 'admin', [
        'starts_at' => '2026-10-10T11:00:00Z',
        'ends_at' => '2026-10-10T12:00:00Z',
    ], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($rescheduleUpdate['ok'] ?? false), 'reschedule update should succeed');
    $rescheduleLifecycle = is_array($rescheduleUpdate['lifecycle'] ?? null) ? $rescheduleUpdate['lifecycle'] : [];
    videochat_call_lifecycle_contract_assert(($rescheduleLifecycle['applied'] ?? null) === true, 'reschedule lifecycle should be applied');
    videochat_call_lifecycle_contract_assert((int) ($rescheduleLifecycle['invalidated_link_count'] ?? 0) >= 2, 'reschedule should invalidate stale links');
    videochat_call_lifecycle_contract_assert((int) ($rescheduleLifecycle['revoked_access_session_count'] ?? 0) >= 2, 'reschedule should revoke active call sessions');
    videochat_call_lifecycle_contract_assert((int) ($rescheduleLifecycle['presence_cleared_count'] ?? 0) === 2, 'reschedule should clear presence rows');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_link_count($pdo, $rescheduleCallId) === 0, 'reschedule should delete old access links');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_presence_count($pdo, $rescheduleCallId) === 0, 'reschedule should clear stored presence');
    videochat_call_lifecycle_contract_assert((string) (videochat_resolve_call_access_public($pdo, $rescheduleRegisteredAccessId)['reason'] ?? '') === 'not_found', 'rescheduled registered stale link should be safe not-found');
    videochat_call_lifecycle_contract_assert((string) (videochat_resolve_call_access_public($pdo, $rescheduleGuestAccessId)['reason'] ?? '') === 'not_found', 'rescheduled guest stale link should be safe not-found');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_session_revoked($pdo, $rescheduleRegisteredSessionId), 'reschedule registered session should be revoked');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_session_revoked($pdo, $rescheduleGuestSessionId), 'reschedule guest session should be revoked');
    videochat_call_lifecycle_contract_assert_auth_denied($pdo, $rescheduleRegisteredSessionId, $rescheduleCallId);
    videochat_call_lifecycle_contract_assert_auth_denied($pdo, $rescheduleGuestSessionId, $rescheduleCallId);
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $rescheduleGuestId)['status'] ?? '') === 'disabled', 'reschedule should disable temp guest');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $registeredUserId)['status'] ?? '') === 'active', 'reschedule must not disable registered user');
    $rescheduledRegisteredParticipant = videochat_call_lifecycle_contract_participant($pdo, $rescheduleCallId, $registeredUserId);
    videochat_call_lifecycle_contract_assert((string) ($rescheduledRegisteredParticipant['invite_state'] ?? '') === 'invited', 'reschedule should reset registered active participant');
    videochat_call_lifecycle_contract_assert(trim((string) ($rescheduledRegisteredParticipant['left_at'] ?? '')) !== '', 'reschedule should mark active participant left');
    $rescheduledOwner = videochat_call_lifecycle_contract_participant($pdo, $rescheduleCallId, $adminUserId);
    videochat_call_lifecycle_contract_assert((string) ($rescheduledOwner['invite_state'] ?? '') === 'allowed', 'reschedule should preserve owner participant state');
    videochat_call_lifecycle_contract_assert_lifecycle_audit($pdo, $tenantId, $rescheduleCallId, 'call_rescheduled', 'rescheduled', 2, 2, 2);
    videochat_call_lifecycle_contract_assert_guest_cleanup_event($pdo, $tenantId, $rescheduleCallId);
    videochat_call_lifecycle_contract_assert_no_audit_leak($pdo, $rescheduleCallId, [
        $rescheduleRegisteredAccessId,
        $rescheduleGuestAccessId,
        $rescheduleRegisteredSessionId,
        $rescheduleGuestSessionId,
        (string) ($rescheduleGuest['email'] ?? ''),
    ]);
    $newRegisteredAccessId = videochat_call_lifecycle_contract_create_personal_link($pdo, $rescheduleCallId, $adminUserId, $registeredUserId, $tenantId);
    $newRegisteredResolve = videochat_resolve_call_access_public($pdo, $newRegisteredAccessId);
    videochat_call_lifecycle_contract_assert((bool) ($newRegisteredResolve['ok'] ?? false), 'fresh registered link should resolve after reschedule');
    videochat_call_lifecycle_contract_assert((string) (($newRegisteredResolve['call'] ?? [])['id'] ?? '') === $rescheduleCallId, 'fresh registered link should resolve same call');

    $unrelatedCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Lifecycle Unrelated Guest Scope',
        'starts_at' => '2026-10-11T09:00:00Z',
        'ends_at' => '2026-10-11T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($unrelatedCall['ok'] ?? false), 'unrelated call should be created');
    $unrelatedCallId = (string) (($unrelatedCall['call'] ?? [])['id'] ?? '');
    $unrelatedGuest = videochat_call_lifecycle_contract_create_temp_guest($pdo, 'Lifecycle Unrelated Guest', $tenantId);
    $unrelatedGuestId = (int) ($unrelatedGuest['id'] ?? 0);
    videochat_ensure_internal_call_participant($pdo, $unrelatedCallId, $unrelatedGuestId, (string) ($unrelatedGuest['email'] ?? ''), (string) ($unrelatedGuest['display_name'] ?? ''), 'allowed');

    $deleteCreate = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Lifecycle Delete Call',
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-10-12T09:00:00Z',
        'ends_at' => '2026-10-12T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($deleteCreate['ok'] ?? false), 'delete call should be created');
    $deleteCall = is_array($deleteCreate['call'] ?? null) ? $deleteCreate['call'] : [];
    $deleteCallId = (string) ($deleteCall['id'] ?? '');
    $deleteRoomId = (string) ($deleteCall['room_id'] ?? '');
    $deleteAccess = videochat_create_call_access_link_for_user($pdo, $deleteCallId, $adminUserId, 'admin', ['link_kind' => 'open'], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($deleteAccess['ok'] ?? false), 'open delete access link should be created');
    $deleteAccessId = videochat_call_lifecycle_contract_access_id($deleteAccess);
    $deletePendingGuest = videochat_call_lifecycle_contract_create_temp_guest($pdo, 'Lifecycle Delete Pending Guest', $tenantId);
    $deletePendingGuestId = (int) ($deletePendingGuest['id'] ?? 0);
    $deleteAdmittedGuest = videochat_call_lifecycle_contract_create_temp_guest($pdo, 'Lifecycle Delete Admitted Guest', $tenantId);
    $deleteAdmittedGuestId = (int) ($deleteAdmittedGuest['id'] ?? 0);
    videochat_ensure_internal_call_participant($pdo, $deleteCallId, $deletePendingGuestId, (string) ($deletePendingGuest['email'] ?? ''), (string) ($deletePendingGuest['display_name'] ?? ''), 'pending');
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'pending',
    joined_at = NULL,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    )->execute([
        ':call_id' => $deleteCallId,
        ':user_id' => $deletePendingGuestId,
    ]);
    videochat_ensure_internal_call_participant($pdo, $deleteCallId, $deleteAdmittedGuestId, (string) ($deleteAdmittedGuest['email'] ?? ''), (string) ($deleteAdmittedGuest['display_name'] ?? ''), 'allowed');
    videochat_call_lifecycle_contract_mark_joined($pdo, $deleteCallId, $deleteAdmittedGuestId, 'allowed');
    $deleteSessionId = 'sess_call_lifecycle_delete_guest';
    $deleteSession = videochat_call_lifecycle_contract_issue_session($pdo, $deleteAccessId, $deleteSessionId, ['guest_name' => 'Lifecycle Delete Guest']);
    videochat_call_lifecycle_contract_assert((bool) ($deleteSession['ok'] ?? false), 'delete open guest session should issue');
    $deleteGuestId = (int) (($deleteSession['user'] ?? [])['id'] ?? 0);
    videochat_call_lifecycle_contract_assert($deleteGuestId > 0, 'delete open guest id should be present');
    videochat_call_lifecycle_contract_add_presence($pdo, $deleteCallId, $deleteRoomId, $deleteGuestId, $deleteSessionId, 'delete_guest');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_lobby_waiting_count($pdo, $deleteCallId) >= 1, 'delete setup should have a queued lobby participant');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_link_count($pdo, $deleteCallId) >= 1, 'delete setup should have an open link');

    $deleteResult = videochat_delete_call($pdo, $deleteCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($deleteResult['ok'] ?? false), 'delete call should succeed');
    $deleteLifecycle = is_array($deleteResult['lifecycle'] ?? null) ? $deleteResult['lifecycle'] : [];
    videochat_call_lifecycle_contract_assert((string) ($deleteLifecycle['transition'] ?? '') === 'deleted', 'delete lifecycle transition mismatch');
    videochat_call_lifecycle_contract_assert((int) ($deleteLifecycle['invalidated_link_count'] ?? 0) >= 1, 'delete should invalidate open links');
    videochat_call_lifecycle_contract_assert((int) ($deleteLifecycle['revoked_access_session_count'] ?? 0) >= 1, 'delete should revoke open sessions');
    videochat_call_lifecycle_contract_assert((int) ($deleteLifecycle['lobby_cleared_count'] ?? 0) >= 3, 'delete should clear lobby and admitted participant state');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_count($pdo, 'SELECT COUNT(*) FROM calls WHERE id = :id', [':id' => $deleteCallId]) === 0, 'deleted call row should be gone');
    videochat_call_lifecycle_contract_assert((string) (videochat_resolve_call_access_public($pdo, $deleteAccessId)['reason'] ?? '') === 'not_found', 'deleted call stale link should be safe not-found');
    $deletedOwnerDecision = videochat_decide_call_access_for_user($pdo, $deleteCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_lifecycle_contract_assert(!(bool) ($deletedOwnerDecision['allowed'] ?? true), 'deleted call should deny owner/admin join');
    videochat_call_lifecycle_contract_assert((string) ($deletedOwnerDecision['reason'] ?? '') === 'not_found', 'deleted owner/admin denial reason mismatch');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_session_revoked($pdo, $deleteSessionId), 'delete guest session should be revoked');
    videochat_call_lifecycle_contract_assert_auth_denied($pdo, $deleteSessionId, $deleteCallId);
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $deleteGuestId)['status'] ?? '') === 'disabled', 'delete should disable scoped open-link guest');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $deletePendingGuestId)['status'] ?? '') === 'disabled', 'delete should disable queued temporary guest');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $deleteAdmittedGuestId)['status'] ?? '') === 'disabled', 'delete should disable admitted temporary guest');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_count($pdo, 'SELECT COUNT(*) FROM calls WHERE id = :id', [':id' => $unrelatedCallId]) === 1, 'delete must not remove unrelated call');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $unrelatedGuestId)['status'] ?? '') === 'active', 'delete must not disable unrelated temp guest');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $registeredUserId)['status'] ?? '') === 'active', 'delete must not disable registered user');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_presence_count($pdo, $deleteCallId) === 0, 'delete should clear stored presence');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_count(
        $pdo,
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND invite_state IN ('pending', 'allowed', 'accepted')
SQL,
        [':call_id' => $deleteCallId]
    ) === 0, 'delete should clear lobby and admitted participant states');
    videochat_call_lifecycle_contract_assert_lifecycle_audit($pdo, $tenantId, $deleteCallId, 'call_deleted', 'deleted', 1, 1, 1);
    videochat_call_lifecycle_contract_assert_guest_cleanup_event($pdo, $tenantId, $deleteCallId);
    videochat_call_lifecycle_contract_assert_no_audit_leak($pdo, $deleteCallId, [
        $deleteAccessId,
        $deleteSessionId,
        (string) ($deletePendingGuest['email'] ?? ''),
        (string) ($deleteAdmittedGuest['email'] ?? ''),
    ]);

    $deletePersonalCreate = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Lifecycle Delete Personalized Link Call',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-12T11:00:00Z',
        'ends_at' => '2026-10-12T12:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($deletePersonalCreate['ok'] ?? false), 'delete personalized call should be created');
    $deletePersonalCall = is_array($deletePersonalCreate['call'] ?? null) ? $deletePersonalCreate['call'] : [];
    $deletePersonalCallId = (string) ($deletePersonalCall['id'] ?? '');
    $deletePersonalGuest = videochat_call_lifecycle_contract_create_temp_guest($pdo, 'Lifecycle Delete Personalized Guest', $tenantId);
    $deletePersonalGuestId = (int) ($deletePersonalGuest['id'] ?? 0);
    videochat_ensure_internal_call_participant($pdo, $deletePersonalCallId, $deletePersonalGuestId, (string) ($deletePersonalGuest['email'] ?? ''), (string) ($deletePersonalGuest['display_name'] ?? ''), 'allowed');
    videochat_call_lifecycle_contract_mark_joined($pdo, $deletePersonalCallId, $deletePersonalGuestId, 'allowed');
    $deletePersonalAccessId = videochat_call_lifecycle_contract_create_personal_link($pdo, $deletePersonalCallId, $adminUserId, $deletePersonalGuestId, $tenantId);
    $deletePersonalSessionId = 'sess_call_lifecycle_delete_personal_guest';
    $deletePersonalSession = videochat_call_lifecycle_contract_issue_session($pdo, $deletePersonalAccessId, $deletePersonalSessionId);
    videochat_call_lifecycle_contract_assert((bool) ($deletePersonalSession['ok'] ?? false), 'delete personalized session should issue');

    $deletePersonalResult = videochat_delete_call($pdo, $deletePersonalCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($deletePersonalResult['ok'] ?? false), 'delete personalized call should succeed');
    $deletePersonalLifecycle = is_array($deletePersonalResult['lifecycle'] ?? null) ? $deletePersonalResult['lifecycle'] : [];
    videochat_call_lifecycle_contract_assert((int) ($deletePersonalLifecycle['invalidated_link_count'] ?? 0) >= 1, 'delete personalized should invalidate personal links');
    videochat_call_lifecycle_contract_assert((int) ($deletePersonalLifecycle['revoked_access_session_count'] ?? 0) >= 1, 'delete personalized should revoke personal sessions');
    videochat_call_lifecycle_contract_assert((string) (videochat_resolve_call_access_public($pdo, $deletePersonalAccessId)['reason'] ?? '') === 'not_found', 'deleted personalized stale link should be safe not-found');
    $deletedPersonalLateSession = videochat_call_lifecycle_contract_issue_session(
        $pdo,
        $deletePersonalAccessId,
        'sess_call_lifecycle_deleted_late_personal'
    );
    videochat_call_lifecycle_contract_assert(!(bool) ($deletedPersonalLateSession['ok'] ?? true), 'deleted personalized link must not issue a late session');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_count($pdo, 'SELECT COUNT(*) FROM sessions WHERE id = :id', [':id' => 'sess_call_lifecycle_deleted_late_personal']) === 0, 'deleted personalized late session must not be stored');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_session_revoked($pdo, $deletePersonalSessionId), 'delete personalized guest session should be revoked');
    videochat_call_lifecycle_contract_assert_auth_denied($pdo, $deletePersonalSessionId, $deletePersonalCallId);
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $deletePersonalGuestId)['status'] ?? '') === 'disabled', 'delete personalized should disable linked temporary guest');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_count(
        $pdo,
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND invite_state IN ('pending', 'allowed', 'accepted')
SQL,
        [':call_id' => $deletePersonalCallId]
    ) === 0, 'delete personalized should clear admitted participant state');
    videochat_call_lifecycle_contract_assert_lifecycle_audit($pdo, $tenantId, $deletePersonalCallId, 'call_deleted', 'deleted', 1, 1, 0);
    videochat_call_lifecycle_contract_assert_guest_cleanup_event($pdo, $tenantId, $deletePersonalCallId);
    videochat_call_lifecycle_contract_assert_no_audit_leak($pdo, $deletePersonalCallId, [
        $deletePersonalAccessId,
        $deletePersonalSessionId,
        (string) ($deletePersonalGuest['email'] ?? ''),
    ]);

    $endCreate = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Lifecycle End Call',
        'starts_at' => '2026-10-13T09:00:00Z',
        'ends_at' => '2026-10-13T10:00:00Z',
        'internal_participant_user_ids' => [$registeredUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($endCreate['ok'] ?? false), 'end call should be created');
    $endCall = is_array($endCreate['call'] ?? null) ? $endCreate['call'] : [];
    $endCallId = (string) ($endCall['id'] ?? '');
    $endRoomId = (string) ($endCall['room_id'] ?? '');
    $endGuest = videochat_call_lifecycle_contract_create_temp_guest($pdo, 'Lifecycle End Guest', $tenantId);
    $endGuestId = (int) ($endGuest['id'] ?? 0);
    $endPendingGuest = videochat_call_lifecycle_contract_create_temp_guest($pdo, 'Lifecycle End Pending Guest', $tenantId);
    $endPendingGuestId = (int) ($endPendingGuest['id'] ?? 0);
    $endAdmittedGuest = videochat_call_lifecycle_contract_create_temp_guest($pdo, 'Lifecycle End Admitted Guest', $tenantId);
    $endAdmittedGuestId = (int) ($endAdmittedGuest['id'] ?? 0);
    videochat_ensure_internal_call_participant($pdo, $endCallId, $endGuestId, (string) ($endGuest['email'] ?? ''), (string) ($endGuest['display_name'] ?? ''), 'allowed');
    videochat_ensure_internal_call_participant($pdo, $endCallId, $endPendingGuestId, (string) ($endPendingGuest['email'] ?? ''), (string) ($endPendingGuest['display_name'] ?? ''), 'pending');
    videochat_ensure_internal_call_participant($pdo, $endCallId, $endAdmittedGuestId, (string) ($endAdmittedGuest['email'] ?? ''), (string) ($endAdmittedGuest['display_name'] ?? ''), 'allowed');
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = :invite_state,
    joined_at = NULL,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    )->execute([
        ':invite_state' => 'pending',
        ':call_id' => $endCallId,
        ':user_id' => $endPendingGuestId,
    ]);
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = :invite_state,
    joined_at = NULL,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    )->execute([
        ':invite_state' => 'allowed',
        ':call_id' => $endCallId,
        ':user_id' => $endAdmittedGuestId,
    ]);
    videochat_call_lifecycle_contract_mark_joined($pdo, $endCallId, $registeredUserId, 'allowed');
    videochat_call_lifecycle_contract_mark_joined($pdo, $endCallId, $endGuestId, 'allowed');
    $endRegisteredAccessId = videochat_call_lifecycle_contract_create_personal_link($pdo, $endCallId, $adminUserId, $registeredUserId, $tenantId);
    $endGuestAccessId = videochat_call_lifecycle_contract_create_personal_link($pdo, $endCallId, $adminUserId, $endGuestId, $tenantId);
    $endRegisteredSessionId = 'sess_call_lifecycle_end_registered';
    $endGuestSessionId = 'sess_call_lifecycle_end_guest';
    videochat_call_lifecycle_contract_assert((bool) (videochat_call_lifecycle_contract_issue_session($pdo, $endRegisteredAccessId, $endRegisteredSessionId)['ok'] ?? false), 'end registered session should issue');
    videochat_call_lifecycle_contract_assert((bool) (videochat_call_lifecycle_contract_issue_session($pdo, $endGuestAccessId, $endGuestSessionId)['ok'] ?? false), 'end guest session should issue');
    videochat_call_lifecycle_contract_add_presence($pdo, $endCallId, $endRoomId, $registeredUserId, $endRegisteredSessionId, 'end_registered');
    videochat_call_lifecycle_contract_add_presence($pdo, $endCallId, $endRoomId, $endGuestId, $endGuestSessionId, 'end_guest');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_lobby_waiting_count($pdo, $endCallId) === 2, 'end setup should have queued and admitted lobby entries');

    $endResult = videochat_end_call($pdo, $endCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($endResult['ok'] ?? false), 'end call should succeed');
    videochat_call_lifecycle_contract_assert((string) (($endResult['call'] ?? [])['status'] ?? '') === 'ended', 'end should return ended call');
    videochat_call_lifecycle_contract_assert((string) (videochat_resolve_call_access_public($pdo, $endRegisteredAccessId)['reason'] ?? '') === 'not_found', 'ended registered stale link should be safe not-found');
    videochat_call_lifecycle_contract_assert((string) (videochat_resolve_call_access_public($pdo, $endGuestAccessId)['reason'] ?? '') === 'not_found', 'ended guest stale link should be safe not-found');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_link_count($pdo, $endCallId) === 0, 'end should delete personalized links');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_session_revoked($pdo, $endRegisteredSessionId), 'end registered session should be revoked');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_session_revoked($pdo, $endGuestSessionId), 'end guest session should be revoked');
    videochat_call_lifecycle_contract_assert_auth_denied($pdo, $endRegisteredSessionId, $endCallId);
    videochat_call_lifecycle_contract_assert_auth_denied($pdo, $endGuestSessionId, $endCallId);
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $endGuestId)['status'] ?? '') === 'disabled', 'end should disable temp guest');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $endPendingGuestId)['status'] ?? '') === 'disabled', 'end should disable pending temp guest');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $endAdmittedGuestId)['status'] ?? '') === 'disabled', 'end should disable admitted temp guest');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $registeredUserId)['status'] ?? '') === 'active', 'end must not disable registered user');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_presence_count($pdo, $endCallId) === 0, 'end should clear stored presence');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_lobby_waiting_count($pdo, $endCallId) === 0, 'end should clear lobby entries');
    $endedRegisteredParticipant = videochat_call_lifecycle_contract_participant($pdo, $endCallId, $registeredUserId);
    videochat_call_lifecycle_contract_assert((string) ($endedRegisteredParticipant['invite_state'] ?? '') === 'cancelled', 'end should cancel registered participant');
    videochat_call_lifecycle_contract_assert(trim((string) ($endedRegisteredParticipant['left_at'] ?? '')) !== '', 'end should mark active registered participant left');
    $endedGuestParticipant = videochat_call_lifecycle_contract_participant($pdo, $endCallId, $endGuestId);
    videochat_call_lifecycle_contract_assert((string) ($endedGuestParticipant['invite_state'] ?? '') === 'cancelled', 'end should cancel guest participant');
    $endedPendingParticipant = videochat_call_lifecycle_contract_participant($pdo, $endCallId, $endPendingGuestId);
    videochat_call_lifecycle_contract_assert((string) ($endedPendingParticipant['invite_state'] ?? '') === 'cancelled', 'end should cancel queued lobby participant');
    $endedAdmittedParticipant = videochat_call_lifecycle_contract_participant($pdo, $endCallId, $endAdmittedGuestId);
    videochat_call_lifecycle_contract_assert((string) ($endedAdmittedParticipant['invite_state'] ?? '') === 'cancelled', 'end should cancel admitted lobby participant');
    $endedOwnerDecision = videochat_decide_call_access_for_user($pdo, $endCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_lifecycle_contract_assert(!(bool) ($endedOwnerDecision['allowed'] ?? true), 'ended call should deny owner/admin join');
    videochat_call_lifecycle_contract_assert((string) ($endedOwnerDecision['reason'] ?? '') === 'call_not_joinable_from_status', 'ended owner denial reason mismatch');
    $endedResolvePath = '/api/calls/resolve/' . $endCallId;
    $endedResolveResponse = videochat_handle_call_routes(
        $endedResolvePath,
        'GET',
        ['method' => 'GET', 'uri' => $endedResolvePath, 'headers' => []],
        [
            'ok' => true,
            'user' => ['id' => $adminUserId, 'role' => 'admin'],
            'session' => ['id' => 'sess_call_lifecycle_admin_resolve'],
            'tenant' => ['id' => $tenantId],
        ],
        'videochat_call_lifecycle_contract_json_response',
        'videochat_call_lifecycle_contract_error_response',
        'videochat_call_lifecycle_contract_decode_body',
        $openDatabase
    );
    videochat_call_lifecycle_contract_assert(is_array($endedResolveResponse), 'ended direct resolve response should exist');
    videochat_call_lifecycle_contract_assert((int) ($endedResolveResponse['status'] ?? 0) === 200, 'ended direct resolve should use safe ok envelope');
    $endedResolvePayload = videochat_call_lifecycle_contract_response_payload($endedResolveResponse);
    videochat_call_lifecycle_contract_assert((string) (($endedResolvePayload['result'] ?? [])['state'] ?? '') === 'forbidden', 'ended direct resolve should be forbidden');
    videochat_call_lifecycle_contract_assert((string) (($endedResolvePayload['result'] ?? [])['reason'] ?? '') === 'call_not_joinable_from_status', 'ended direct resolve reason mismatch');
    videochat_call_lifecycle_contract_assert(($endedResolvePayload['result']['call'] ?? null) === null, 'ended direct resolve must not expose call payload');
    videochat_call_lifecycle_contract_assert(!str_contains((string) ($endedResolveResponse['body'] ?? ''), 'Lifecycle End Call'), 'ended direct resolve must not leak title');
    $endedRegisteredDecision = videochat_decide_call_access_for_user($pdo, $endCallId, $registeredUserId, 'user', $tenantId);
    videochat_call_lifecycle_contract_assert(!(bool) ($endedRegisteredDecision['allowed'] ?? true), 'ended call should deny active participant join');
    videochat_call_lifecycle_contract_assert((string) ($endedRegisteredDecision['reason'] ?? '') === 'call_not_joinable_from_status', 'ended participant denial reason mismatch');
    $lateEndedAccessId = videochat_call_lifecycle_contract_insert_late_retained_link($pdo, $endCallId, $adminUserId, $unrelatedGuestId, $tenantId);
    $lateEndedResolve = videochat_resolve_call_access_public($pdo, $lateEndedAccessId);
    videochat_call_lifecycle_contract_assert(!(bool) ($lateEndedResolve['ok'] ?? true), 'late retained ended link must not resolve');
    videochat_call_lifecycle_contract_assert((string) ($lateEndedResolve['reason'] ?? '') === 'conflict', 'late retained ended link should expose safe ended-call conflict');
    videochat_call_lifecycle_contract_assert(($lateEndedResolve['call'] ?? null) === null, 'late retained ended link must not expose call payload');
    $lateEndedSession = videochat_call_lifecycle_contract_issue_session(
        $pdo,
        $lateEndedAccessId,
        'sess_call_lifecycle_late_ended'
    );
    videochat_call_lifecycle_contract_assert(!(bool) ($lateEndedSession['ok'] ?? true), 'late retained ended link must not issue a session');
    videochat_call_lifecycle_contract_assert((string) ($lateEndedSession['reason'] ?? '') === 'conflict', 'late retained ended session reason mismatch');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_count($pdo, 'SELECT COUNT(*) FROM sessions WHERE id = :id', [':id' => 'sess_call_lifecycle_late_ended']) === 0, 'late retained ended session must not be inserted');
    videochat_call_lifecycle_contract_assert_lifecycle_audit($pdo, $tenantId, $endCallId, 'call_ended', 'ended', 2, 2, 2);
    videochat_call_lifecycle_contract_assert_guest_cleanup_event($pdo, $tenantId, $endCallId);
    videochat_call_lifecycle_contract_assert_no_audit_leak($pdo, $endCallId, [
        $endRegisteredAccessId,
        $endGuestAccessId,
        $lateEndedAccessId,
        $endRegisteredSessionId,
        $endGuestSessionId,
        (string) ($endGuest['email'] ?? ''),
        (string) ($endPendingGuest['email'] ?? ''),
        (string) ($endAdmittedGuest['email'] ?? ''),
    ]);

    $openEndCreate = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Lifecycle End Anonymous Link Call',
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-10-14T09:00:00Z',
        'ends_at' => '2026-10-14T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($openEndCreate['ok'] ?? false), 'open end call should be created');
    $openEndCall = is_array($openEndCreate['call'] ?? null) ? $openEndCreate['call'] : [];
    $openEndCallId = (string) ($openEndCall['id'] ?? '');
    $openEndAccessId = videochat_call_lifecycle_contract_create_open_link($pdo, $openEndCallId, $adminUserId, $tenantId);
    $openEndSessionId = 'sess_call_lifecycle_end_open_guest';
    $openEndSession = videochat_call_lifecycle_contract_issue_session($pdo, $openEndAccessId, $openEndSessionId, [
        'guest_name' => 'Lifecycle End Anonymous Guest',
    ]);
    videochat_call_lifecycle_contract_assert((bool) ($openEndSession['ok'] ?? false), 'end open guest session should issue');
    $openEndGuestId = (int) (($openEndSession['user'] ?? [])['id'] ?? 0);
    videochat_call_lifecycle_contract_assert($openEndGuestId > 0, 'end open guest id should be present');
    $openEndResult = videochat_end_call($pdo, $openEndCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($openEndResult['ok'] ?? false), 'open end call should end');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_link_count($pdo, $openEndCallId) === 0, 'end should delete anonymous open link');
    videochat_call_lifecycle_contract_assert((string) (videochat_resolve_call_access_public($pdo, $openEndAccessId)['reason'] ?? '') === 'not_found', 'ended anonymous stale link should be safe not-found');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_session_revoked($pdo, $openEndSessionId), 'end anonymous session should be revoked');
    videochat_call_lifecycle_contract_assert_auth_denied($pdo, $openEndSessionId, $openEndCallId);
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $openEndGuestId)['status'] ?? '') === 'disabled', 'end should disable anonymous temp guest');
    $openEndedAdminDecision = videochat_decide_call_access_for_user($pdo, $openEndCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_lifecycle_contract_assert(!(bool) ($openEndedAdminDecision['allowed'] ?? true), 'ended open call should deny system admin normal join');
    videochat_call_lifecycle_contract_assert_lifecycle_audit($pdo, $tenantId, $openEndCallId, 'call_ended', 'ended', 1, 1, 0);
    videochat_call_lifecycle_contract_assert_guest_cleanup_event($pdo, $tenantId, $openEndCallId);
    videochat_call_lifecycle_contract_assert_no_audit_leak($pdo, $openEndCallId, [
        $openEndAccessId,
        $openEndSessionId,
        (string) (($openEndSession['user'] ?? [])['email'] ?? ''),
    ]);

    @unlink($databasePath);
    fwrite(STDOUT, "[call-lifecycle-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    if ($databasePath !== '') {
        @unlink($databasePath);
    }
    fwrite(STDERR, '[call-lifecycle-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
