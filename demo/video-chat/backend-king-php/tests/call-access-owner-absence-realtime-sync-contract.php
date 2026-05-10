<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby_sync.php';
require_once __DIR__ . '/../domain/realtime/realtime_room_snapshot.php';
require_once __DIR__ . '/../http/module_realtime_lobby_security.php';

$label = 'call-access-owner-absence-realtime-sync-contract';

function videochat_iam720_assert(bool $condition, string $message): void
{
    global $label;
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[{$label}] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam720_role_id(PDO $pdo, string $slug): int
{
    $statement = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $statement->execute([':slug' => $slug]);

    return (int) ($statement->fetchColumn() ?: 0);
}

function videochat_iam720_seed_user(
    PDO $pdo,
    string $email,
    string $displayName,
    int $tenantId,
    int $organizationId,
    string $organizationRole = 'member'
): int {
    $roleId = videochat_iam720_role_id($pdo, 'user');
    videochat_iam720_assert($roleId > 0, 'expected seeded user role');

    $now = gmdate('c');
    $passwordHash = password_hash('iam720-contract-password', PASSWORD_DEFAULT);
    videochat_iam720_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be generated');

    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insertUser->execute([
        ':email' => strtolower($email),
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => $now,
    ]);
    $userId = (int) $pdo->lastInsertId();
    videochat_iam720_assert($userId > 0, 'inserted user id should be positive');

    $insertTenantMembership = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, status, permissions_json, default_membership, created_at, updated_at)
VALUES(:tenant_id, :user_id, 'member', 'active', :permissions_json, 1, :created_at, :updated_at)
SQL
    );
    $insertTenantMembership->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':permissions_json' => json_encode([], JSON_THROW_ON_ERROR),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $insertOrganizationMembership = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :created_at, :updated_at)
SQL
    );
    $insertOrganizationMembership->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
        ':membership_role' => strtolower(trim($organizationRole)) === 'admin' ? 'admin' : 'member',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return $userId;
}

function videochat_iam720_iso(int $nowMs): string
{
    return gmdate('c', (int) floor(max(0, $nowMs) / 1000));
}

function videochat_iam720_set_participant_times(PDO $pdo, string $callId, int $userId, ?int $joinedAtMs, ?int $leftAtMs): void
{
    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET joined_at = CASE
        WHEN :joined_at IS NULL THEN joined_at
        ELSE :joined_at
    END,
    left_at = :left_at
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    );
    $statement->execute([
        ':joined_at' => is_int($joinedAtMs) ? videochat_iam720_iso($joinedAtMs) : null,
        ':left_at' => is_int($leftAtMs) ? videochat_iam720_iso($leftAtMs) : null,
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
}

function videochat_iam720_left_at(PDO $pdo, string $callId, int $userId): string
{
    $statement = $pdo->prepare(
        'SELECT left_at FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1'
    );
    $statement->execute([':call_id' => $callId, ':user_id' => $userId]);

    return trim((string) ($statement->fetchColumn() ?: ''));
}

function videochat_iam720_call_status(PDO $pdo, string $callId): string
{
    $statement = $pdo->prepare('SELECT status FROM calls WHERE id = :call_id LIMIT 1');
    $statement->execute([':call_id' => $callId]);

    return strtolower(trim((string) ($statement->fetchColumn() ?: '')));
}

function videochat_iam720_audit_events_by_type(PDO $pdo, string $callId): array
{
    $eventsByType = [];
    foreach (videochat_audit_fetch_events($pdo, ['call_id' => $callId, 'limit' => 100]) as $event) {
        if (is_array($event)) {
            $eventsByType[(string) ($event['event_type'] ?? '')][] = $event;
        }
    }

    return $eventsByType;
}

function videochat_iam720_audit_payload_dump(array $eventsByType, array $eventTypes): string
{
    $payloads = [];
    foreach ($eventTypes as $eventType) {
        foreach ((array) ($eventsByType[(string) $eventType] ?? []) as $event) {
            if (is_array($event)) {
                $payloads[] = (array) ($event['payload'] ?? []);
            }
        }
    }

    return json_encode($payloads, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
}

function videochat_iam720_count(PDO $pdo, string $sql, array $params = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return max(0, (int) ($statement->fetchColumn() ?: 0));
}

function videochat_iam720_link_count(PDO $pdo, string $callId): int
{
    return videochat_iam720_count(
        $pdo,
        'SELECT COUNT(*) FROM call_access_links WHERE call_id = :call_id',
        [':call_id' => $callId]
    );
}

function videochat_iam720_session_exists(PDO $pdo, string $sessionId): bool
{
    return videochat_iam720_count(
        $pdo,
        'SELECT COUNT(*) FROM sessions WHERE id = :session_id',
        [':session_id' => $sessionId]
    ) === 1;
}

function videochat_iam720_session_revoked(PDO $pdo, string $sessionId): bool
{
    return videochat_iam720_count(
        $pdo,
        'SELECT COUNT(*) FROM sessions WHERE id = :session_id AND revoked_at IS NOT NULL AND revoked_at <> \'\'',
        [':session_id' => $sessionId]
    ) === 1;
}

function videochat_iam720_user_status(PDO $pdo, int $userId): string
{
    $statement = $pdo->prepare('SELECT status FROM users WHERE id = :user_id LIMIT 1');
    $statement->execute([':user_id' => $userId]);

    return strtolower(trim((string) ($statement->fetchColumn() ?: '')));
}

function videochat_iam720_invite_state(PDO $pdo, string $callId, int $userId): string
{
    $statement = $pdo->prepare('SELECT invite_state FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
    $statement->execute([':call_id' => $callId, ':user_id' => $userId]);

    return strtolower(trim((string) ($statement->fetchColumn() ?: '')));
}

function videochat_iam720_create_open_link(PDO $pdo, string $callId, int $ownerUserId, int $tenantId): string
{
    $link = videochat_create_call_access_link_for_user(
        $pdo,
        $callId,
        $ownerUserId,
        'user',
        ['link_kind' => 'open'],
        $tenantId
    );
    videochat_iam720_assert((bool) ($link['ok'] ?? false), 'anonymous owner absence timeout link should be created');
    $accessId = (string) (($link['access_link'] ?? [])['id'] ?? '');
    videochat_iam720_assert($accessId !== '', 'anonymous owner absence timeout access id should be present');

    return $accessId;
}

function videochat_iam720_set_invite_state(PDO $pdo, string $callId, int $userId, string $inviteState): void
{
    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = :invite_state
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    );
    $statement->execute([
        ':invite_state' => videochat_normalize_call_invite_state($inviteState),
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
}

function videochat_iam720_connection(
    PDO $pdo,
    array &$presenceState,
    string $roomId,
    string $callId,
    int $userId,
    string $displayName,
    string $callRole,
    int $tenantId,
    int $nowMs,
    string $suffix
): array {
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => 'user',
            'tenant' => ['id' => $tenantId],
        ],
        'sess-iam720-' . $suffix,
        'conn-iam720-' . $suffix,
        'socket-iam720-' . $suffix,
        $roomId,
        (int) floor($nowMs / 1000)
    );
    $connection['tenant_id'] = $tenantId;
    $connection['requested_call_id'] = $callId;
    $connection['active_call_id'] = $callId;
    $connection['call_role'] = videochat_normalize_call_participant_role($callRole);
    $connection['effective_call_role'] = $connection['call_role'];
    $connection['invite_state'] = 'allowed';
    $connection['can_moderate_call'] = in_array($connection['call_role'], ['owner', 'moderator'], true);
    $connection['can_manage_call_owner'] = $connection['call_role'] === 'owner';

    $join = videochat_presence_join_room($presenceState, $connection, $roomId);
    $connection = (array) ($join['connection'] ?? $connection);
    videochat_realtime_presence_db_upsert($pdo, $connection, $nowMs);
    videochat_iam720_set_participant_times($pdo, $callId, $userId, $nowMs, null);

    return $connection;
}

function videochat_iam720_leave(PDO $pdo, array &$presenceState, array $connection, int $nowMs): array
{
    $connectionId = (string) ($connection['connection_id'] ?? '');
    $leftConnection = videochat_presence_remove_connection($presenceState, $connectionId);
    $effectiveConnection = is_array($leftConnection) ? $leftConnection : $connection;
    videochat_realtime_remove_call_presence(static fn (): PDO => $pdo, $effectiveConnection);
    videochat_iam720_set_participant_times(
        $pdo,
        videochat_realtime_connection_call_id($effectiveConnection),
        (int) ($effectiveConnection['user_id'] ?? 0),
        null,
        $nowMs
    );

    return $effectiveConnection;
}

function videochat_iam720_snapshot(PDO $pdo, array $presenceState, array $viewerConnection, int $nowMs, string $reason): array
{
    return videochat_realtime_room_snapshot_payload(
        $presenceState,
        $viewerConnection,
        static fn (): PDO => $pdo,
        $reason,
        $nowMs
    );
}

function videochat_iam720_prepare_call(
    PDO $pdo,
    int $tenantId,
    int $ownerUserId,
    array $participantUserIds,
    string $title
): array {
    $created = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => 'invite_only',
        'starts_at' => '2026-11-20T09:00:00Z',
        'ends_at' => '2026-11-20T10:00:00Z',
        'internal_participant_user_ids' => $participantUserIds,
        'external_participants' => [],
    ], $tenantId);
    videochat_iam720_assert((bool) ($created['ok'] ?? false), "{$title} should be created");
    $call = (array) ($created['call'] ?? []);
    $callId = (string) ($call['id'] ?? '');
    $roomId = (string) ($call['room_id'] ?? '');
    videochat_iam720_assert($callId !== '' && $roomId !== '', "{$title} should expose call ids");

    foreach ($participantUserIds as $participantUserId) {
        videochat_iam720_set_invite_state($pdo, $callId, (int) $participantUserId, 'allowed');
    }

    return $call;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[{$label}] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-iam720-owner-absence-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $openDatabase = static fn (): PDO => $pdo;

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $organizationRow = $pdo->query("SELECT id FROM organizations WHERE tenant_id = {$tenantId} ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    videochat_iam720_assert($tenantId > 0, 'expected default tenant');
    videochat_iam720_assert(is_array($organizationRow), 'expected default organization');
    $organizationId = (int) ($organizationRow['id'] ?? 0);

    $ownerUserId = videochat_iam720_seed_user($pdo, 'iam720-owner@example.test', 'IAM720 Owner', $tenantId, $organizationId);
    $participantOneId = videochat_iam720_seed_user($pdo, 'iam720-participant-one@example.test', 'IAM720 Participant One', $tenantId, $organizationId);
    $participantTwoId = videochat_iam720_seed_user($pdo, 'iam720-participant-two@example.test', 'IAM720 Participant Two', $tenantId, $organizationId);
    $waitingUserId = videochat_iam720_seed_user($pdo, 'iam720-waiting@example.test', 'IAM720 Waiting', $tenantId, $organizationId);

    $staleCall = videochat_iam720_prepare_call(
        $pdo,
        $tenantId,
        $ownerUserId,
        [$participantOneId, $waitingUserId],
        'IAM720 Stale Owner Heartbeat'
    );
    $staleCallId = (string) ($staleCall['id'] ?? '');
    $staleRoomId = (string) ($staleCall['room_id'] ?? '');
    videochat_iam720_set_invite_state($pdo, $staleCallId, $waitingUserId, 'pending');
    $stalePresence = videochat_presence_state_init();
    $staleStartMs = 1_779_000_000_000;
    $staleOwner = videochat_iam720_connection(
        $pdo,
        $stalePresence,
        $staleRoomId,
        $staleCallId,
        $ownerUserId,
        'IAM720 Owner',
        'owner',
        $tenantId,
        $staleStartMs,
        'stale-owner'
    );
    $staleParticipant = videochat_iam720_connection(
        $pdo,
        $stalePresence,
        $staleRoomId,
        $staleCallId,
        $participantOneId,
        'IAM720 Participant One',
        'participant',
        $tenantId,
        $staleStartMs + 1000,
        'stale-participant'
    );
    $staleDetectedMs = $staleStartMs + videochat_realtime_presence_db_ttl_ms() + 1000;
    videochat_realtime_presence_db_upsert($pdo, $staleParticipant, $staleDetectedMs);

    $staleSnapshot = videochat_iam720_snapshot($pdo, $stalePresence, $staleParticipant, $staleDetectedMs, 'owner_stale_heartbeat');
    $staleAbsence = (array) (($staleSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam720_assert((string) ($staleAbsence['status'] ?? '') === 'monitoring', 'stale owner heartbeat should start monitoring');
    videochat_iam720_assert((bool) ($staleAbsence['owner_present'] ?? true) === false, 'stale owner should not be reported present');
    videochat_iam720_assert((int) ($staleAbsence['absent_since_ms'] ?? 0) === $staleStartMs + videochat_realtime_presence_db_ttl_ms(), 'stale owner absent_since should use heartbeat TTL');
    videochat_iam720_assert(videochat_iam720_left_at($pdo, $staleCallId, $ownerUserId) !== '', 'stale owner heartbeat should materialize left_at');
    foreach ((array) ($staleSnapshot['participants'] ?? []) as $participant) {
        videochat_iam720_assert(
            (int) (($participant['user'] ?? [])['id'] ?? 0) !== $ownerUserId,
            'snapshot must not keep a stale owner participant from local presence'
        );
    }
    $staleViewer = (array) ($staleSnapshot['viewer'] ?? []);
    videochat_iam720_assert((string) ($staleViewer['effective_call_role'] ?? '') === 'participant', 'participant viewer should stay participant');
    videochat_iam720_assert(!(bool) ($staleViewer['can_moderate'] ?? true), 'participant viewer should not receive moderation controls');

    $allowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'target_user_id' => $waitingUserId,
    ], JSON_UNESCAPED_SLASHES));
    videochat_iam720_assert((bool) ($allowCommand['ok'] ?? false), 'lobby allow command should decode');
    $pdo->prepare(
        <<<'SQL'
UPDATE realtime_presence_connections
SET last_seen_at_ms = :last_seen_at_ms
WHERE call_id = :call_id
  AND room_id = :room_id
  AND user_id = :user_id
SQL
    )->execute([
        ':last_seen_at_ms' => videochat_realtime_presence_db_now_ms() - videochat_realtime_presence_db_ttl_ms() - 1000,
        ':call_id' => $staleCallId,
        ':room_id' => $staleRoomId,
        ':user_id' => $ownerUserId,
    ]);
    $staleOwnerAuthority = videochat_realtime_authorize_lobby_moderation_command(
        [
            ...$staleOwner,
            'call_role' => 'owner',
            'effective_call_role' => 'owner',
            'can_moderate_call' => true,
            'can_manage_call_owner' => true,
        ],
        $allowCommand,
        $staleRoomId,
        $openDatabase
    );
    videochat_iam720_assert(!(bool) ($staleOwnerAuthority['ok'] ?? true), 'stale absent owner must not authorize lobby command');
    videochat_iam720_assert((string) ($staleOwnerAuthority['error'] ?? '') === 'stale_lobby_authority', 'stale absent owner denial reason mismatch');

    $lobbyState = videochat_lobby_state_init();
    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        $frames[$key][] = $payload;
        return true;
    };
    videochat_realtime_send_synced_lobby_snapshot_to_connection(
        $lobbyState,
        [
            ...$staleOwner,
            'call_role' => 'owner',
            'effective_call_role' => 'owner',
            'can_moderate_call' => true,
            'can_manage_call_owner' => true,
        ],
        $openDatabase,
        'owner_absent_lobby_sync',
        $sender,
        $staleDetectedMs
    );
    $staleOwnerLobbyFrame = $frames[(string) ($staleOwner['socket'] ?? '')][0] ?? [];
    videochat_iam720_assert((string) ($staleOwnerLobbyFrame['type'] ?? '') === 'lobby/snapshot', 'stale owner should receive lobby snapshot frame');
    videochat_iam720_assert((int) ($staleOwnerLobbyFrame['queue_count'] ?? -1) === 0, 'stale owner lobby snapshot must be redacted to non-moderator view');

    $ownerReturnMs = $staleDetectedMs + 30_000;
    $returnedOwner = videochat_iam720_connection(
        $pdo,
        $stalePresence,
        $staleRoomId,
        $staleCallId,
        $ownerUserId,
        'IAM720 Owner',
        'owner',
        $tenantId,
        $ownerReturnMs,
        'owner-return'
    );
    $returnSnapshot = videochat_iam720_snapshot($pdo, $stalePresence, $returnedOwner, $ownerReturnMs, 'owner_return');
    $returnAbsence = (array) (($returnSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam720_assert((string) ($returnAbsence['status'] ?? '') === 'owner_present', 'owner return should cancel owner absence');
    videochat_iam720_assert(videochat_iam720_left_at($pdo, $staleCallId, $ownerUserId) === '', 'owner return should clear materialized left_at');
    $returnedAuthority = videochat_realtime_authorize_lobby_moderation_command($returnedOwner, $allowCommand, $staleRoomId, $openDatabase);
    videochat_iam720_assert((bool) ($returnedAuthority['ok'] ?? false), 'returned owner should regain lobby authority from current server presence');
    $returnAuditByType = videochat_iam720_audit_events_by_type($pdo, $staleCallId);
    videochat_iam720_assert(count($returnAuditByType['call_owner_absence_timer_started'] ?? []) >= 1, 'owner absence timer start should be audit-logged');
    videochat_iam720_assert(count($returnAuditByType['call_owner_absence_timer_cancelled'] ?? []) >= 1, 'owner return should audit-log timer cancellation');
    $returnCancelPayload = (array) (($returnAuditByType['call_owner_absence_timer_cancelled'][0] ?? [])['payload'] ?? []);
    videochat_iam720_assert((string) ($returnCancelPayload['cancel_reason'] ?? '') === 'owner_returned', 'owner return audit cancel reason mismatch');
    $returnAuditDump = videochat_iam720_audit_payload_dump($returnAuditByType, [
        'call_owner_absence_timer_started',
        'call_owner_absence_timer_cancelled',
    ]);
    videochat_iam720_assert(!str_contains($returnAuditDump, $staleRoomId), 'owner return audit must not log raw room id');
    videochat_iam720_assert(str_contains($returnAuditDump, videochat_audit_fingerprint($staleRoomId)), 'owner return audit should keep room fingerprint');

    $syncCall = videochat_iam720_prepare_call(
        $pdo,
        $tenantId,
        $ownerUserId,
        [$participantOneId, $participantTwoId],
        'IAM720 Countdown Sync'
    );
    $syncCallId = (string) ($syncCall['id'] ?? '');
    $syncRoomId = (string) ($syncCall['room_id'] ?? '');
    $syncPresence = videochat_presence_state_init();
    $syncStartMs = 1_779_100_000_000;
    $syncOwner = videochat_iam720_connection($pdo, $syncPresence, $syncRoomId, $syncCallId, $ownerUserId, 'IAM720 Owner', 'owner', $tenantId, $syncStartMs, 'sync-owner');
    $syncParticipantA = videochat_iam720_connection($pdo, $syncPresence, $syncRoomId, $syncCallId, $participantOneId, 'IAM720 Participant One', 'participant', $tenantId, $syncStartMs + 1000, 'sync-a');
    $syncParticipantB = videochat_iam720_connection($pdo, $syncPresence, $syncRoomId, $syncCallId, $participantTwoId, 'IAM720 Participant Two', 'participant', $tenantId, $syncStartMs + 2000, 'sync-b');
    $syncOwnerLeftMs = $syncStartMs + 60_000;
    videochat_iam720_leave($pdo, $syncPresence, $syncOwner, $syncOwnerLeftMs);
    $syncCountdownMs = $syncOwnerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS;
    videochat_realtime_presence_db_upsert($pdo, $syncParticipantA, $syncCountdownMs);
    videochat_realtime_presence_db_upsert($pdo, $syncParticipantB, $syncCountdownMs);
    $syncSnapshotA = videochat_iam720_snapshot($pdo, $syncPresence, $syncParticipantA, $syncCountdownMs, 'countdown_a');
    $syncSnapshotB = videochat_iam720_snapshot($pdo, $syncPresence, $syncParticipantB, $syncCountdownMs, 'countdown_b');
    $syncAbsenceA = (array) (($syncSnapshotA['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    $syncAbsenceB = (array) (($syncSnapshotB['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam720_assert((string) ($syncAbsenceA['status'] ?? '') === 'countdown', 'participant A should enter countdown');
    videochat_iam720_assert((string) ($syncAbsenceB['status'] ?? '') === 'countdown', 'participant B should enter countdown');
    videochat_iam720_assert((int) ($syncAbsenceA['ends_at_ms'] ?? 0) === (int) ($syncAbsenceB['ends_at_ms'] ?? -1), 'countdown ends_at must synchronize across participants');
    videochat_iam720_assert((int) ($syncAbsenceA['countdown_remaining_ms'] ?? 0) === VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS, 'countdown should start with five minutes remaining');
    $signatureA = videochat_realtime_room_snapshot_signature($syncSnapshotA);
    $mutatedLifecycle = $syncSnapshotA;
    $mutatedLifecycle['call_lifecycle']['owner_absence']['countdown_remaining_ms'] = VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS - 1000;
    videochat_iam720_assert($signatureA !== videochat_realtime_room_snapshot_signature($mutatedLifecycle), 'room snapshot signature must include owner absence lifecycle');

    $refreshMs = $syncCountdownMs + 30_000;
    videochat_iam720_leave($pdo, $syncPresence, $syncParticipantA, $refreshMs - 1000);
    $refreshedA = videochat_iam720_connection($pdo, $syncPresence, $syncRoomId, $syncCallId, $participantOneId, 'IAM720 Participant One', 'participant', $tenantId, $refreshMs, 'sync-a-refresh');
    videochat_realtime_presence_db_upsert($pdo, $syncParticipantB, $refreshMs);
    $refreshSnapshot = videochat_iam720_snapshot($pdo, $syncPresence, $refreshedA, $refreshMs, 'participant_refresh');
    $refreshAbsence = (array) (($refreshSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam720_assert((string) ($refreshAbsence['status'] ?? '') === 'countdown', 'participant refresh should keep countdown active');
    videochat_iam720_assert((int) ($refreshAbsence['absent_since_ms'] ?? 0) === $syncOwnerLeftMs, 'participant refresh should preserve owner absent_since');
    videochat_iam720_assert((int) ($refreshAbsence['countdown_remaining_ms'] ?? 0) === VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS - 30_000, 'participant refresh should keep server-time countdown');

    $timeoutCall = videochat_iam720_prepare_call(
        $pdo,
        $tenantId,
        $ownerUserId,
        [$participantOneId],
        'IAM720 Owner Absence Timeout'
    );
    $timeoutCallId = (string) ($timeoutCall['id'] ?? '');
    $timeoutRoomId = (string) ($timeoutCall['room_id'] ?? '');
    $access = videochat_create_call_access_link_for_user(
        $pdo,
        $timeoutCallId,
        $ownerUserId,
        'user',
        ['link_kind' => 'personal', 'participant_user_id' => $participantOneId],
        $tenantId
    );
    videochat_iam720_assert((bool) ($access['ok'] ?? false), 'timeout setup should create personal access link');
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_iam720_assert($accessId !== '', 'timeout access id should be present');
    $sessionId = 'sess_iam720_owner_timeout_access';
    $issued = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam720_assert((bool) ($issued['ok'] ?? false), 'call-access session should issue before timeout');

    $timeoutPresence = videochat_presence_state_init();
    $timeoutStartMs = 1_779_200_000_000;
    $timeoutOwner = videochat_iam720_connection($pdo, $timeoutPresence, $timeoutRoomId, $timeoutCallId, $ownerUserId, 'IAM720 Owner', 'owner', $tenantId, $timeoutStartMs, 'timeout-owner');
    $timeoutParticipant = videochat_iam720_connection($pdo, $timeoutPresence, $timeoutRoomId, $timeoutCallId, $participantOneId, 'IAM720 Participant One', 'participant', $tenantId, $timeoutStartMs + 1000, 'timeout-participant');
    $timeoutLeftMs = $timeoutStartMs + 60_000;
    videochat_iam720_leave($pdo, $timeoutPresence, $timeoutOwner, $timeoutLeftMs);
    $timeoutMs = $timeoutLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS + 1000;
    videochat_realtime_presence_db_upsert($pdo, $timeoutParticipant, $timeoutMs);
    $timeoutSnapshot = videochat_iam720_snapshot($pdo, $timeoutPresence, $timeoutParticipant, $timeoutMs, 'owner_absence_timeout');
    $timeoutAbsence = (array) (($timeoutSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam720_assert((string) ($timeoutAbsence['status'] ?? '') === 'ended', 'owner absence timeout should publish ended state');
    videochat_iam720_assert((string) ($timeoutAbsence['ended_reason'] ?? '') === 'owner_absent_timeout', 'owner absence timeout reason mismatch');
    videochat_iam720_assert((bool) ($timeoutAbsence['transitioned'] ?? false), 'owner absence timeout should transition the call');
    videochat_iam720_assert(videochat_iam720_call_status($pdo, $timeoutCallId) === 'ended', 'owner absence timeout should persist ended call status');
    $timeoutLifecycle = (array) ($timeoutAbsence['lifecycle'] ?? []);
    videochat_iam720_assert((int) ($timeoutLifecycle['invalidated_link_count'] ?? 0) >= 1, 'owner timeout should disable call-access links');
    videochat_iam720_assert((int) ($timeoutLifecycle['revoked_access_session_count'] ?? 0) >= 1, 'owner timeout should revoke call-access sessions');
    $timeoutAuditByType = videochat_iam720_audit_events_by_type($pdo, $timeoutCallId);
    videochat_iam720_assert(count($timeoutAuditByType['call_owner_absence_timer_started'] ?? []) >= 1, 'owner timeout should audit-log timer start');
    videochat_iam720_assert(count($timeoutAuditByType['call_implicitly_ended'] ?? []) >= 1, 'owner timeout should audit-log implicit call end');
    $timeoutStartPayload = (array) (($timeoutAuditByType['call_owner_absence_timer_started'][0] ?? [])['payload'] ?? []);
    videochat_iam720_assert((string) ($timeoutStartPayload['audit_scope'] ?? '') === 'iam_owner_absence', 'owner timeout timer audit scope mismatch');
    videochat_iam720_assert((string) ($timeoutStartPayload['action'] ?? '') === 'timer_started', 'owner timeout timer audit action mismatch');
    $implicitPayload = (array) (($timeoutAuditByType['call_implicitly_ended'][0] ?? [])['payload'] ?? []);
    videochat_iam720_assert((string) ($implicitPayload['audit_scope'] ?? '') === 'iam_owner_absence', 'implicit end audit scope mismatch');
    videochat_iam720_assert((string) ($implicitPayload['action'] ?? '') === 'implicit_end', 'implicit end audit action mismatch');
    videochat_iam720_assert((string) ($implicitPayload['ended_reason'] ?? '') === 'owner_absent_timeout', 'implicit end audit reason mismatch');
    videochat_iam720_assert((bool) ($implicitPayload['transitioned'] ?? false), 'implicit end audit should record transition');
    $timeoutAuditDump = videochat_iam720_audit_payload_dump($timeoutAuditByType, [
        'call_owner_absence_timer_started',
        'call_implicitly_ended',
    ]);
    videochat_iam720_assert(!str_contains($timeoutAuditDump, $timeoutRoomId), 'owner timeout audit must not log raw room id');
    videochat_iam720_assert(str_contains($timeoutAuditDump, videochat_audit_fingerprint($timeoutRoomId)), 'owner timeout audit should keep room fingerprint');

    $authAfterTimeout = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId) . '&call_id=' . rawurlencode($timeoutCallId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam720_assert(!(bool) ($authAfterTimeout['ok'] ?? true), 'revoked timeout call-access session must fail closed');
    $resolveAfterTimeout = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_iam720_assert(!(bool) ($resolveAfterTimeout['ok'] ?? true), 'fresh access link resolve must fail after owner timeout');
    videochat_iam720_assert(($resolveAfterTimeout['access_link'] ?? null) === null, 'timeout denial must redact access link payload');
    videochat_iam720_assert(($resolveAfterTimeout['call'] ?? null) === null, 'timeout denial must redact call payload');
    videochat_iam720_assert(($resolveAfterTimeout['target_user'] ?? null) === null, 'timeout denial must redact target user payload');

    $staleTimeoutOwnerSnapshot = videochat_iam720_snapshot(
        $pdo,
        $timeoutPresence,
        [
            ...$timeoutOwner,
            'call_role' => 'owner',
            'effective_call_role' => 'owner',
            'can_moderate_call' => true,
            'can_manage_call_owner' => true,
        ],
        $timeoutMs + 1000,
        'stale_owner_after_timeout'
    );
    $staleTimeoutViewer = (array) ($staleTimeoutOwnerSnapshot['viewer'] ?? []);
    videochat_iam720_assert((string) ($staleTimeoutViewer['effective_call_role'] ?? '') === 'participant', 'stale owner snapshot after timeout must downgrade role');
    videochat_iam720_assert(!(bool) ($staleTimeoutViewer['can_moderate'] ?? true), 'stale owner snapshot after timeout must remove moderation');
    videochat_iam720_assert(!(bool) ($staleTimeoutViewer['can_manage_owner'] ?? true), 'stale owner snapshot after timeout must remove owner controls');

    $anonymousCall = videochat_iam720_prepare_call(
        $pdo,
        $tenantId,
        $ownerUserId,
        [$participantTwoId],
        'IAM720 Owner Absence Anonymous Link Timeout'
    );
    $anonymousCallId = (string) ($anonymousCall['id'] ?? '');
    $anonymousRoomId = (string) ($anonymousCall['room_id'] ?? '');
    $pdo->prepare("UPDATE calls SET access_mode = 'free_for_all' WHERE id = :call_id")->execute([':call_id' => $anonymousCallId]);
    $anonymousAccessId = videochat_iam720_create_open_link($pdo, $anonymousCallId, $ownerUserId, $tenantId);
    $anonymousSessionId = 'sess_iam720_owner_timeout_anonymous_guest';
    $anonymousSession = videochat_issue_session_for_call_access(
        $pdo,
        $anonymousAccessId,
        static fn (): string => $anonymousSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label],
        ['guest_name' => 'IAM720 Owner Timeout Anonymous Guest']
    );
    videochat_iam720_assert((bool) ($anonymousSession['ok'] ?? false), 'owner-timeout anonymous session should issue before timeout');
    $anonymousGuestId = (int) (($anonymousSession['user'] ?? [])['id'] ?? 0);
    videochat_iam720_assert($anonymousGuestId > 0, 'owner-timeout anonymous guest id should be present');
    videochat_iam720_assert(videochat_iam720_link_count($pdo, $anonymousCallId) >= 1, 'owner-timeout anonymous setup should have an open link');

    $anonymousPresence = videochat_presence_state_init();
    $anonymousStartMs = 1_779_300_000_000;
    $anonymousOwner = videochat_iam720_connection($pdo, $anonymousPresence, $anonymousRoomId, $anonymousCallId, $ownerUserId, 'IAM720 Owner', 'owner', $tenantId, $anonymousStartMs, 'anonymous-owner');
    $anonymousParticipant = videochat_iam720_connection($pdo, $anonymousPresence, $anonymousRoomId, $anonymousCallId, $participantTwoId, 'IAM720 Participant Two', 'participant', $tenantId, $anonymousStartMs + 1000, 'anonymous-participant');
    $anonymousOwnerLeftMs = $anonymousStartMs + 60_000;
    videochat_iam720_leave($pdo, $anonymousPresence, $anonymousOwner, $anonymousOwnerLeftMs);
    $anonymousTimeoutMs = $anonymousOwnerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS + 1000;
    videochat_realtime_presence_db_upsert($pdo, $anonymousParticipant, $anonymousTimeoutMs);
    $anonymousEndedSnapshot = videochat_iam720_snapshot($pdo, $anonymousPresence, $anonymousParticipant, $anonymousTimeoutMs, 'owner_absence_anonymous_timeout');
    $anonymousEnded = (array) (($anonymousEndedSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam720_assert((string) ($anonymousEnded['status'] ?? '') === 'ended', 'owner-timeout anonymous-link call should end automatically');
    $anonymousLifecycle = (array) ($anonymousEnded['lifecycle'] ?? []);
    videochat_iam720_assert((int) ($anonymousLifecycle['invalidated_link_count'] ?? 0) >= 1, 'owner-timeout anonymous end should invalidate anonymous link');
    videochat_iam720_assert((int) ($anonymousLifecycle['revoked_access_session_count'] ?? 0) >= 1, 'owner-timeout anonymous end should revoke anonymous session');
    videochat_iam720_assert(videochat_iam720_link_count($pdo, $anonymousCallId) === 0, 'owner-timeout anonymous end should delete call access links');
    videochat_iam720_assert((string) (videochat_resolve_call_access_public($pdo, $anonymousAccessId)['reason'] ?? '') === 'not_found', 'owner-timeout anonymous ended link should be safe not-found');
    $lateAnonymousSession = videochat_issue_session_for_call_access(
        $pdo,
        $anonymousAccessId,
        static fn (): string => 'sess_iam720_owner_timeout_late_anonymous',
        ['client_ip' => '127.0.0.1', 'user_agent' => $label],
        ['guest_name' => 'IAM720 Late Anonymous Guest']
    );
    videochat_iam720_assert(!(bool) ($lateAnonymousSession['ok'] ?? true), 'owner-timeout ended anonymous link must not issue a new session');
    videochat_iam720_assert(!videochat_iam720_session_exists($pdo, 'sess_iam720_owner_timeout_late_anonymous'), 'owner-timeout denied late anonymous session must not be stored');
    videochat_iam720_assert(videochat_iam720_session_revoked($pdo, $anonymousSessionId), 'owner-timeout anonymous end should revoke anonymous session');
    $anonymousAuthAfterTimeout = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($anonymousSessionId) . '&call_id=' . rawurlencode($anonymousCallId),
            'headers' => ['Authorization' => 'Bearer ' . $anonymousSessionId],
        ],
        'websocket'
    );
    videochat_iam720_assert(!(bool) ($anonymousAuthAfterTimeout['ok'] ?? true), 'revoked owner-timeout anonymous session must fail closed');
    videochat_iam720_assert(videochat_iam720_user_status($pdo, $anonymousGuestId) === 'disabled', 'owner-timeout anonymous end should disable anonymous temporary guest');
    videochat_iam720_assert(videochat_iam720_invite_state($pdo, $anonymousCallId, $anonymousGuestId) === 'cancelled', 'owner-timeout anonymous end should cancel anonymous temporary participant');

    @unlink($databasePath);
    fwrite(STDOUT, "[{$label}] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[{$label}] ERROR: " . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
