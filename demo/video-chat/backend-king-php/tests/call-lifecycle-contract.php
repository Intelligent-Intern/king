<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/audit/audit_events.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_connection_contract.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
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
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => is_string($body) ? $body : '{}',
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

function videochat_call_lifecycle_contract_call_status(PDO $pdo, string $callId): string
{
    $query = $pdo->prepare('SELECT status FROM calls WHERE id = :call_id LIMIT 1');
    $query->execute([':call_id' => $callId]);
    return strtolower(trim((string) ($query->fetchColumn() ?: '')));
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

    $deleteBeforeJoinCreate = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Lifecycle Delete Before Guests Join',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-12T07:00:00Z',
        'ends_at' => '2026-10-12T08:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($deleteBeforeJoinCreate['ok'] ?? false), 'pre-join delete call should be created');
    $deleteBeforeJoinCall = is_array($deleteBeforeJoinCreate['call'] ?? null) ? $deleteBeforeJoinCreate['call'] : [];
    $deleteBeforeJoinCallId = (string) ($deleteBeforeJoinCall['id'] ?? '');
    $deleteBeforeJoinGuest = videochat_call_lifecycle_contract_create_temp_guest($pdo, 'Lifecycle Delete Before Join Guest', $tenantId);
    $deleteBeforeJoinGuestId = (int) ($deleteBeforeJoinGuest['id'] ?? 0);
    videochat_ensure_internal_call_participant(
        $pdo,
        $deleteBeforeJoinCallId,
        $deleteBeforeJoinGuestId,
        (string) ($deleteBeforeJoinGuest['email'] ?? ''),
        (string) ($deleteBeforeJoinGuest['display_name'] ?? ''),
        'allowed'
    );
    $deleteBeforeJoinPersonalAccessId = videochat_call_lifecycle_contract_create_personal_link($pdo, $deleteBeforeJoinCallId, $adminUserId, $deleteBeforeJoinGuestId, $tenantId);
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_link_count($pdo, $deleteBeforeJoinCallId) === 1, 'pre-join delete setup should have a personalized temporary link');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_lobby_waiting_count($pdo, $deleteBeforeJoinCallId) === 1, 'pre-join delete setup should have one not-yet-joined guest');

    $deleteBeforeJoinResult = videochat_delete_call($pdo, $deleteBeforeJoinCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($deleteBeforeJoinResult['ok'] ?? false), 'owner should delete call before guests join');
    $deleteBeforeJoinLifecycle = is_array($deleteBeforeJoinResult['lifecycle'] ?? null) ? $deleteBeforeJoinResult['lifecycle'] : [];
    videochat_call_lifecycle_contract_assert((string) ($deleteBeforeJoinLifecycle['transition'] ?? '') === 'deleted', 'pre-join delete lifecycle transition mismatch');
    videochat_call_lifecycle_contract_assert((int) ($deleteBeforeJoinLifecycle['invalidated_link_count'] ?? 0) >= 1, 'pre-join delete should invalidate personalized temporary link');
    videochat_call_lifecycle_contract_assert((int) ($deleteBeforeJoinLifecycle['lobby_cleared_count'] ?? 0) >= 1, 'pre-join delete should cancel not-yet-joined guest state');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_count($pdo, 'SELECT COUNT(*) FROM calls WHERE id = :id', [':id' => $deleteBeforeJoinCallId]) === 0, 'pre-join deleted call row should be gone');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_link_count($pdo, $deleteBeforeJoinCallId) === 0, 'pre-join delete should remove all access links');
    videochat_call_lifecycle_contract_assert((string) (videochat_resolve_call_access_public($pdo, $deleteBeforeJoinPersonalAccessId)['reason'] ?? '') === 'not_found', 'pre-join deleted personalized link should be safe not-found');
    $deleteBeforeJoinLatePersonal = videochat_call_lifecycle_contract_issue_session(
        $pdo,
        $deleteBeforeJoinPersonalAccessId,
        'sess_call_lifecycle_deleted_prejoin_late_personal'
    );
    videochat_call_lifecycle_contract_assert(!(bool) ($deleteBeforeJoinLatePersonal['ok'] ?? true), 'pre-join deleted personalized link must not issue a late session');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_count($pdo, 'SELECT COUNT(*) FROM sessions WHERE id = :id', [
        ':id' => 'sess_call_lifecycle_deleted_prejoin_late_personal',
    ]) === 0, 'pre-join deleted denied late session must not be stored');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $deleteBeforeJoinGuestId)['status'] ?? '') === 'disabled', 'pre-join delete should disable scoped temporary guest');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $registeredUserId)['status'] ?? '') === 'active', 'pre-join delete must preserve registered users');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $unrelatedGuestId)['status'] ?? '') === 'active', 'pre-join delete must preserve unrelated temporary guest');
    videochat_call_lifecycle_contract_assert_lifecycle_audit($pdo, $tenantId, $deleteBeforeJoinCallId, 'call_deleted', 'deleted', 1, 0, 0);
    videochat_call_lifecycle_contract_assert_guest_cleanup_event($pdo, $tenantId, $deleteBeforeJoinCallId);
    videochat_call_lifecycle_contract_assert_no_audit_leak($pdo, $deleteBeforeJoinCallId, [
        $deleteBeforeJoinPersonalAccessId,
        (string) ($deleteBeforeJoinGuest['email'] ?? ''),
    ]);

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
    $deleteSessionId = 'sess_call_lifecycle_delete_guest';
    $deleteSession = videochat_call_lifecycle_contract_issue_session($pdo, $deleteAccessId, $deleteSessionId, ['guest_name' => 'Lifecycle Delete Guest']);
    videochat_call_lifecycle_contract_assert((bool) ($deleteSession['ok'] ?? false), 'delete open guest session should issue');
    $deleteGuestId = (int) (($deleteSession['user'] ?? [])['id'] ?? 0);
    videochat_call_lifecycle_contract_assert($deleteGuestId > 0, 'delete open guest id should be present');
    videochat_call_lifecycle_contract_add_presence($pdo, $deleteCallId, $deleteRoomId, $deleteGuestId, $deleteSessionId, 'delete_guest');

    $deleteResult = videochat_delete_call($pdo, $deleteCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($deleteResult['ok'] ?? false), 'delete call should succeed');
    $deleteLifecycle = is_array($deleteResult['lifecycle'] ?? null) ? $deleteResult['lifecycle'] : [];
    videochat_call_lifecycle_contract_assert((string) ($deleteLifecycle['transition'] ?? '') === 'deleted', 'delete lifecycle transition mismatch');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_count($pdo, 'SELECT COUNT(*) FROM calls WHERE id = :id', [':id' => $deleteCallId]) === 0, 'deleted call row should be gone');
    videochat_call_lifecycle_contract_assert((string) (videochat_resolve_call_access_public($pdo, $deleteAccessId)['reason'] ?? '') === 'not_found', 'deleted call stale link should be safe not-found');
    $deletedOwnerDecision = videochat_decide_call_access_for_user($pdo, $deleteCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_lifecycle_contract_assert(!(bool) ($deletedOwnerDecision['allowed'] ?? true), 'deleted call should deny owner/admin join');
    videochat_call_lifecycle_contract_assert((string) ($deletedOwnerDecision['reason'] ?? '') === 'not_found', 'deleted owner/admin denial reason mismatch');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_session_revoked($pdo, $deleteSessionId), 'delete guest session should be revoked');
    videochat_call_lifecycle_contract_assert_auth_denied($pdo, $deleteSessionId, $deleteCallId);
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $deleteGuestId)['status'] ?? '') === 'disabled', 'delete should disable scoped open-link guest');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_count($pdo, 'SELECT COUNT(*) FROM calls WHERE id = :id', [':id' => $unrelatedCallId]) === 1, 'delete must not remove unrelated call');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $unrelatedGuestId)['status'] ?? '') === 'active', 'delete must not disable unrelated temp guest');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $registeredUserId)['status'] ?? '') === 'active', 'delete must not disable registered user');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_presence_count($pdo, $deleteCallId) === 0, 'delete should clear stored presence');
    videochat_call_lifecycle_contract_assert_lifecycle_audit($pdo, $tenantId, $deleteCallId, 'call_deleted', 'deleted', 1, 1, 1);
    videochat_call_lifecycle_contract_assert_guest_cleanup_event($pdo, $tenantId, $deleteCallId);
    videochat_call_lifecycle_contract_assert_no_audit_leak($pdo, $deleteCallId, [$deleteAccessId, $deleteSessionId]);

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
    videochat_ensure_internal_call_participant($pdo, $endCallId, $endGuestId, (string) ($endGuest['email'] ?? ''), (string) ($endGuest['display_name'] ?? ''), 'allowed');
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

    $endResult = videochat_end_call($pdo, $endCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($endResult['ok'] ?? false), 'end call should succeed');
    videochat_call_lifecycle_contract_assert((string) (($endResult['call'] ?? [])['status'] ?? '') === 'ended', 'end should return ended call');
    videochat_call_lifecycle_contract_assert((string) (videochat_resolve_call_access_public($pdo, $endRegisteredAccessId)['reason'] ?? '') === 'not_found', 'ended registered stale link should be safe not-found');
    videochat_call_lifecycle_contract_assert((string) (videochat_resolve_call_access_public($pdo, $endGuestAccessId)['reason'] ?? '') === 'not_found', 'ended guest stale link should be safe not-found');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_session_revoked($pdo, $endRegisteredSessionId), 'end registered session should be revoked');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_session_revoked($pdo, $endGuestSessionId), 'end guest session should be revoked');
    videochat_call_lifecycle_contract_assert_auth_denied($pdo, $endRegisteredSessionId, $endCallId);
    videochat_call_lifecycle_contract_assert_auth_denied($pdo, $endGuestSessionId, $endCallId);
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $endGuestId)['status'] ?? '') === 'disabled', 'end should disable temp guest');
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $registeredUserId)['status'] ?? '') === 'active', 'end must not disable registered user');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_presence_count($pdo, $endCallId) === 0, 'end should clear stored presence');
    $endedRegisteredParticipant = videochat_call_lifecycle_contract_participant($pdo, $endCallId, $registeredUserId);
    videochat_call_lifecycle_contract_assert((string) ($endedRegisteredParticipant['invite_state'] ?? '') === 'cancelled', 'end should cancel registered participant');
    videochat_call_lifecycle_contract_assert(trim((string) ($endedRegisteredParticipant['left_at'] ?? '')) !== '', 'end should mark active registered participant left');
    $endedGuestParticipant = videochat_call_lifecycle_contract_participant($pdo, $endCallId, $endGuestId);
    videochat_call_lifecycle_contract_assert((string) ($endedGuestParticipant['invite_state'] ?? '') === 'cancelled', 'end should cancel guest participant');
    $endedOwnerDecision = videochat_decide_call_access_for_user($pdo, $endCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_lifecycle_contract_assert(!(bool) ($endedOwnerDecision['allowed'] ?? true), 'ended call should deny owner/admin join');
    videochat_call_lifecycle_contract_assert((string) ($endedOwnerDecision['reason'] ?? '') === 'conflict', 'ended owner denial reason mismatch');
    $endedRegisteredDecision = videochat_decide_call_access_for_user($pdo, $endCallId, $registeredUserId, 'user', $tenantId);
    videochat_call_lifecycle_contract_assert(!(bool) ($endedRegisteredDecision['allowed'] ?? true), 'ended call should deny active participant join');
    videochat_call_lifecycle_contract_assert((string) ($endedRegisteredDecision['reason'] ?? '') === 'conflict', 'ended participant denial reason mismatch');
    videochat_call_lifecycle_contract_assert_lifecycle_audit($pdo, $tenantId, $endCallId, 'call_ended', 'ended', 2, 2, 2);
    videochat_call_lifecycle_contract_assert_guest_cleanup_event($pdo, $tenantId, $endCallId);
    videochat_call_lifecycle_contract_assert_no_audit_leak($pdo, $endCallId, [
        $endRegisteredAccessId,
        $endGuestAccessId,
        $endRegisteredSessionId,
        $endGuestSessionId,
        (string) ($endGuest['email'] ?? ''),
    ]);

    $ownerLeaveCreate = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Lifecycle Owner Explicit Leave Call',
        'access_mode' => 'free_for_all',
        'starts_at' => gmdate('c', time() - 3600),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [$registeredUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($ownerLeaveCreate['ok'] ?? false), 'owner leave call should be created');
    $ownerLeaveCall = is_array($ownerLeaveCreate['call'] ?? null) ? $ownerLeaveCreate['call'] : [];
    $ownerLeaveCallId = (string) ($ownerLeaveCall['id'] ?? '');
    $ownerLeaveRoomId = (string) ($ownerLeaveCall['room_id'] ?? '');
    $pdo->prepare("UPDATE calls SET status = 'active' WHERE id = :call_id")->execute([':call_id' => $ownerLeaveCallId]);
    videochat_call_lifecycle_contract_mark_joined($pdo, $ownerLeaveCallId, $adminUserId, 'allowed');
    videochat_call_lifecycle_contract_mark_joined($pdo, $ownerLeaveCallId, $registeredUserId, 'allowed');
    $ownerLeaveOpenAccessId = videochat_call_lifecycle_contract_create_open_link($pdo, $ownerLeaveCallId, $adminUserId, $tenantId);
    $ownerLeaveOpenSessionId = 'sess_call_lifecycle_owner_leave_open';
    $ownerLeaveOpenSession = videochat_call_lifecycle_contract_issue_session($pdo, $ownerLeaveOpenAccessId, $ownerLeaveOpenSessionId, [
        'guest_name' => 'Lifecycle Owner Leave Anonymous Guest',
    ]);
    videochat_call_lifecycle_contract_assert((bool) ($ownerLeaveOpenSession['ok'] ?? false), 'owner leave anonymous session should issue');
    $ownerLeaveOpenGuestId = (int) (($ownerLeaveOpenSession['user'] ?? [])['id'] ?? 0);
    videochat_call_lifecycle_contract_assert($ownerLeaveOpenGuestId > 0, 'owner leave anonymous guest id should be present');
    videochat_call_lifecycle_contract_add_presence($pdo, $ownerLeaveCallId, $ownerLeaveRoomId, $adminUserId, 'sess_call_lifecycle_owner_leave_owner', 'owner_leave_owner', 'owner');
    videochat_call_lifecycle_contract_add_presence($pdo, $ownerLeaveCallId, $ownerLeaveRoomId, $registeredUserId, 'sess_call_lifecycle_owner_leave_registered', 'owner_leave_registered');
    videochat_call_lifecycle_contract_add_presence($pdo, $ownerLeaveCallId, $ownerLeaveRoomId, $ownerLeaveOpenGuestId, $ownerLeaveOpenSessionId, 'owner_leave_open_guest');

    $ownerLeavePath = '/api/calls/' . $ownerLeaveCallId . '/leave';
    $ownerLeaveResponse = videochat_handle_call_routes(
        $ownerLeavePath,
        'POST',
        ['method' => 'POST', 'uri' => $ownerLeavePath, 'headers' => [], 'body' => ''],
        [
            'ok' => true,
            'user' => ['id' => $adminUserId, 'role' => 'admin'],
            'session' => ['id' => 'sess_call_lifecycle_owner_leave_http'],
            'tenant' => ['id' => $tenantId],
        ],
        'videochat_call_lifecycle_contract_json_response',
        'videochat_call_lifecycle_contract_error_response',
        'videochat_call_lifecycle_contract_decode_body',
        $openDatabase
    );
    videochat_call_lifecycle_contract_assert(is_array($ownerLeaveResponse), 'owner leave route response should exist');
    videochat_call_lifecycle_contract_assert((int) ($ownerLeaveResponse['status'] ?? 0) === 200, 'owner leave route should return ok');
    $ownerLeavePayload = videochat_call_lifecycle_contract_response_payload($ownerLeaveResponse);
    videochat_call_lifecycle_contract_assert((string) (($ownerLeavePayload['result'] ?? [])['state'] ?? '') === 'ended', 'owner leave route should end the call');
    videochat_call_lifecycle_contract_assert((string) (($ownerLeavePayload['result'] ?? [])['reason'] ?? '') === 'owner_left_ended', 'owner leave route reason mismatch');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_call_status($pdo, $ownerLeaveCallId) === 'ended', 'owner leave should persist ended status');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_link_count($pdo, $ownerLeaveCallId) === 0, 'owner leave end should delete anonymous link');
    videochat_call_lifecycle_contract_assert((string) (videochat_resolve_call_access_public($pdo, $ownerLeaveOpenAccessId)['reason'] ?? '') === 'not_found', 'owner leave ended anonymous stale link should be safe not-found');
    $ownerLeaveLateOpen = videochat_call_lifecycle_contract_issue_session($pdo, $ownerLeaveOpenAccessId, 'sess_call_lifecycle_owner_leave_late_open', [
        'guest_name' => 'Lifecycle Owner Leave Late Open',
    ]);
    videochat_call_lifecycle_contract_assert(!(bool) ($ownerLeaveLateOpen['ok'] ?? true), 'owner leave ended anonymous link must not issue a late session');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_count($pdo, 'SELECT COUNT(*) FROM sessions WHERE id = :id', [':id' => 'sess_call_lifecycle_owner_leave_late_open']) === 0, 'owner leave denied late anonymous session must not be stored');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_session_revoked($pdo, $ownerLeaveOpenSessionId), 'owner leave should revoke anonymous call-access session');
    videochat_call_lifecycle_contract_assert_auth_denied($pdo, $ownerLeaveOpenSessionId, $ownerLeaveCallId);
    videochat_call_lifecycle_contract_assert((string) (videochat_call_lifecycle_contract_user($pdo, $ownerLeaveOpenGuestId)['status'] ?? '') === 'disabled', 'owner leave should disable anonymous temporary guest');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_presence_count($pdo, $ownerLeaveCallId) === 0, 'owner leave end should clear presence');
    $ownerLeaveRegisteredParticipant = videochat_call_lifecycle_contract_participant($pdo, $ownerLeaveCallId, $registeredUserId);
    videochat_call_lifecycle_contract_assert((string) ($ownerLeaveRegisteredParticipant['invite_state'] ?? '') === 'cancelled', 'owner leave should cancel registered participant');
    videochat_call_lifecycle_contract_assert(trim((string) ($ownerLeaveRegisteredParticipant['left_at'] ?? '')) !== '', 'owner leave should mark registered participant left');
    videochat_call_lifecycle_contract_assert_lifecycle_audit($pdo, $tenantId, $ownerLeaveCallId, 'call_ended', 'ended', 1, 1, 3);
    videochat_call_lifecycle_contract_assert_guest_cleanup_event($pdo, $tenantId, $ownerLeaveCallId);
    videochat_call_lifecycle_contract_assert_no_audit_leak($pdo, $ownerLeaveCallId, [
        $ownerLeaveOpenAccessId,
        $ownerLeaveOpenSessionId,
        (string) (($ownerLeaveOpenSession['user'] ?? [])['email'] ?? ''),
    ]);

    $participantLeaveCreate = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Lifecycle Participant Explicit Leave Call',
        'starts_at' => gmdate('c', time() - 3600),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [$registeredUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_lifecycle_contract_assert((bool) ($participantLeaveCreate['ok'] ?? false), 'participant leave call should be created');
    $participantLeaveCall = is_array($participantLeaveCreate['call'] ?? null) ? $participantLeaveCreate['call'] : [];
    $participantLeaveCallId = (string) ($participantLeaveCall['id'] ?? '');
    $pdo->prepare("UPDATE calls SET status = 'active' WHERE id = :call_id")->execute([':call_id' => $participantLeaveCallId]);
    videochat_call_lifecycle_contract_mark_joined($pdo, $participantLeaveCallId, $registeredUserId, 'allowed');
    $participantLeavePath = '/api/calls/' . $participantLeaveCallId . '/leave';
    $participantLeaveResponse = videochat_handle_call_routes(
        $participantLeavePath,
        'POST',
        ['method' => 'POST', 'uri' => $participantLeavePath, 'headers' => [], 'body' => ''],
        [
            'ok' => true,
            'user' => ['id' => $registeredUserId, 'role' => 'user'],
            'session' => ['id' => 'sess_call_lifecycle_participant_leave_http'],
            'tenant' => ['id' => $tenantId],
        ],
        'videochat_call_lifecycle_contract_json_response',
        'videochat_call_lifecycle_contract_error_response',
        'videochat_call_lifecycle_contract_decode_body',
        $openDatabase
    );
    videochat_call_lifecycle_contract_assert(is_array($participantLeaveResponse), 'participant leave route response should exist');
    videochat_call_lifecycle_contract_assert((int) ($participantLeaveResponse['status'] ?? 0) === 200, 'participant leave route should return ok');
    $participantLeavePayload = videochat_call_lifecycle_contract_response_payload($participantLeaveResponse);
    videochat_call_lifecycle_contract_assert((string) (($participantLeavePayload['result'] ?? [])['state'] ?? '') === 'left', 'participant leave route should mark non-terminal left state');
    videochat_call_lifecycle_contract_assert(videochat_call_lifecycle_contract_call_status($pdo, $participantLeaveCallId) === 'active', 'participant leave must not end the call');
    $participantLeaveRow = videochat_call_lifecycle_contract_participant($pdo, $participantLeaveCallId, $registeredUserId);
    videochat_call_lifecycle_contract_assert(trim((string) ($participantLeaveRow['left_at'] ?? '')) !== '', 'participant leave should persist left_at');

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
