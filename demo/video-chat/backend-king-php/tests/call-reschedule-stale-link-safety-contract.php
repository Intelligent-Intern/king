<?php

declare(strict_types=1);

$contract = 'call-reschedule-stale-link-safety-contract';

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';

function videochat_reschedule_stale_link_assert(bool $condition, string $message): void
{
    global $contract;
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[{$contract}] FAIL: {$message}\n");
    exit(1);
}

function videochat_reschedule_stale_link_count(PDO $pdo, string $sql, array $params = []): int
{
    $query = $pdo->prepare($sql);
    $query->execute($params);
    return max(0, (int) ($query->fetchColumn() ?: 0));
}

function videochat_reschedule_stale_link_user(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT id, email, display_name, status FROM users WHERE id = :id LIMIT 1');
    $query->execute([':id' => $userId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function videochat_reschedule_stale_link_participant(PDO $pdo, string $callId, int $userId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT invite_state, joined_at, left_at
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
LIMIT 1
SQL
    );
    $query->execute([':call_id' => $callId, ':user_id' => $userId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function videochat_reschedule_stale_link_lobby_waiting_count(PDO $pdo, string $callId): int
{
    return videochat_reschedule_stale_link_count(
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

function videochat_reschedule_stale_link_create_guest(PDO $pdo, string $name, int $tenantId): array
{
    $created = videochat_create_guest_user_for_call_access($pdo, $name, $tenantId);
    videochat_reschedule_stale_link_assert((bool) ($created['ok'] ?? false), "{$name} should be created");
    $user = is_array($created['user'] ?? null) ? $created['user'] : [];
    videochat_reschedule_stale_link_assert((int) ($user['id'] ?? 0) > 0, "{$name} id should be present");
    return $user;
}

function videochat_reschedule_stale_link_access_id(array $result, string $label): string
{
    videochat_reschedule_stale_link_assert((bool) ($result['ok'] ?? false), "{$label} link should be created");
    $accessId = (string) (($result['access_link'] ?? [])['id'] ?? '');
    videochat_reschedule_stale_link_assert($accessId !== '', "{$label} access id should be present");
    return $accessId;
}

function videochat_reschedule_stale_link_create_personal(
    PDO $pdo,
    string $callId,
    int $ownerUserId,
    int $participantUserId,
    int $tenantId,
    string $label
): string {
    return videochat_reschedule_stale_link_access_id(
        videochat_create_call_access_link_for_user($pdo, $callId, $ownerUserId, 'admin', [
            'link_kind' => 'personal',
            'participant_user_id' => $participantUserId,
        ], $tenantId),
        $label
    );
}

function videochat_reschedule_stale_link_create_open(PDO $pdo, string $callId, int $ownerUserId, int $tenantId, string $label): string
{
    return videochat_reschedule_stale_link_access_id(
        videochat_create_call_access_link_for_user($pdo, $callId, $ownerUserId, 'admin', ['link_kind' => 'open'], $tenantId),
        $label
    );
}

function videochat_reschedule_stale_link_issue(PDO $pdo, string $accessId, string $sessionId, array $options = []): array
{
    return videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-reschedule-stale-link-safety-contract'],
        $options
    );
}

function videochat_reschedule_stale_link_assert_stale_access_denied(PDO $pdo, string $accessId, string $label): void
{
    $resolve = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_reschedule_stale_link_assert(!(bool) ($resolve['ok'] ?? true), "{$label} stale link should not resolve");
    videochat_reschedule_stale_link_assert((string) ($resolve['reason'] ?? '') === 'not_found', "{$label} stale link denial reason should be not_found");

    $sessionId = 'sess_reschedule_stale_' . preg_replace('/[^a-z0-9_]+/i', '_', $label);
    $lateSession = videochat_reschedule_stale_link_issue($pdo, $accessId, $sessionId, ['guest_name' => 'Stale Reschedule Guest']);
    videochat_reschedule_stale_link_assert(!(bool) ($lateSession['ok'] ?? true), "{$label} stale link should not issue a late session");
    videochat_reschedule_stale_link_assert((string) ($lateSession['reason'] ?? '') === 'not_found', "{$label} late session denial reason should be not_found");
    videochat_reschedule_stale_link_assert(
        videochat_reschedule_stale_link_count($pdo, 'SELECT COUNT(*) FROM sessions WHERE id = :id', [':id' => $sessionId]) === 0,
        "{$label} late session must not be stored"
    );
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[{$contract}] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-' . $contract . '-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $ownerUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $registeredUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_reschedule_stale_link_assert($tenantId > 0 && $ownerUserId > 0 && $registeredUserId > 0, 'seed tenant, owner, and registered guest should exist');

    $callCreate = videochat_create_call($pdo, $ownerUserId, [
        'title' => 'Reschedule Stale Link Safety',
        'starts_at' => '2026-10-20T09:00:00Z',
        'ends_at' => '2026-10-20T10:00:00Z',
        'internal_participant_user_ids' => [$registeredUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_reschedule_stale_link_assert((bool) ($callCreate['ok'] ?? false), 'reschedule call should be created');
    $callId = (string) (($callCreate['call'] ?? [])['id'] ?? '');
    videochat_reschedule_stale_link_assert($callId !== '', 'reschedule call id should be present');

    $unopenedGuest = videochat_reschedule_stale_link_create_guest($pdo, 'Reschedule Unopened Invite Guest', $tenantId);
    $unopenedGuestId = (int) ($unopenedGuest['id'] ?? 0);
    videochat_ensure_internal_call_participant($pdo, $callId, $unopenedGuestId, (string) ($unopenedGuest['email'] ?? ''), (string) ($unopenedGuest['display_name'] ?? ''), 'invited');

    $lobbyGuest = videochat_reschedule_stale_link_create_guest($pdo, 'Reschedule Pending Lobby Guest', $tenantId);
    $lobbyGuestId = (int) ($lobbyGuest['id'] ?? 0);
    videochat_ensure_internal_call_participant($pdo, $callId, $lobbyGuestId, (string) ($lobbyGuest['email'] ?? ''), (string) ($lobbyGuest['display_name'] ?? ''), 'invited');
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'pending',
    joined_at = NULL,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    )->execute([':call_id' => $callId, ':user_id' => $lobbyGuestId]);

    $oldRegisteredAccessId = videochat_reschedule_stale_link_create_personal($pdo, $callId, $ownerUserId, $registeredUserId, $tenantId, 'old registered personal');
    $oldUnopenedAccessId = videochat_reschedule_stale_link_create_personal($pdo, $callId, $ownerUserId, $unopenedGuestId, $tenantId, 'old unopened personal');
    $oldLobbyAccessId = videochat_reschedule_stale_link_create_personal($pdo, $callId, $ownerUserId, $lobbyGuestId, $tenantId, 'old lobby personal');
    videochat_reschedule_stale_link_assert(videochat_reschedule_stale_link_lobby_waiting_count($pdo, $callId) === 1, 'setup should have one pending lobby entry');

    $update = videochat_update_call($pdo, $callId, $ownerUserId, 'admin', [
        'starts_at' => '2026-10-20T11:00:00Z',
        'ends_at' => '2026-10-20T12:00:00Z',
    ], $tenantId);
    videochat_reschedule_stale_link_assert((bool) ($update['ok'] ?? false), 'owner reschedule should succeed');
    $lifecycle = is_array($update['lifecycle'] ?? null) ? $update['lifecycle'] : [];
    videochat_reschedule_stale_link_assert(($lifecycle['applied'] ?? null) === true, 'reschedule lifecycle should be applied');
    videochat_reschedule_stale_link_assert((int) ($lifecycle['invalidated_link_count'] ?? 0) >= 3, 'reschedule should invalidate old personal links');
    videochat_reschedule_stale_link_assert((int) ($lifecycle['lobby_cleared_count'] ?? 0) >= 1, 'reschedule should clear or migrate pending lobby entries');
    videochat_reschedule_stale_link_assert(videochat_reschedule_stale_link_count($pdo, 'SELECT COUNT(*) FROM call_access_links WHERE call_id = :call_id', [':call_id' => $callId]) === 0, 'old call access links should be removed');
    videochat_reschedule_stale_link_assert(videochat_reschedule_stale_link_lobby_waiting_count($pdo, $callId) === 0, 'old lobby entries should no longer be waiting after reschedule');

    $lobbyParticipant = videochat_reschedule_stale_link_participant($pdo, $callId, $lobbyGuestId);
    videochat_reschedule_stale_link_assert((string) ($lobbyParticipant['invite_state'] ?? '') === 'invited', 'pending lobby guest should migrate back to invited');
    videochat_reschedule_stale_link_assert((string) (videochat_reschedule_stale_link_user($pdo, $unopenedGuestId)['status'] ?? '') === 'disabled', 'unopened temporary invite guest should be invalidated');
    videochat_reschedule_stale_link_assert((string) (videochat_reschedule_stale_link_user($pdo, $lobbyGuestId)['status'] ?? '') === 'disabled', 'pending lobby temporary guest should be invalidated');
    videochat_reschedule_stale_link_assert((string) (videochat_reschedule_stale_link_user($pdo, $registeredUserId)['status'] ?? '') === 'active', 'registered guest account should remain active');

    foreach ([
        'registered_personal' => $oldRegisteredAccessId,
        'unopened_personal' => $oldUnopenedAccessId,
        'lobby_personal' => $oldLobbyAccessId,
    ] as $label => $accessId) {
        videochat_reschedule_stale_link_assert_stale_access_denied($pdo, $accessId, $label);
    }

    $newRegisteredAccessId = videochat_reschedule_stale_link_create_personal($pdo, $callId, $ownerUserId, $registeredUserId, $tenantId, 'new registered personal');
    $newRegisteredSession = videochat_reschedule_stale_link_issue($pdo, $newRegisteredAccessId, 'sess_reschedule_new_registered');
    videochat_reschedule_stale_link_assert((bool) ($newRegisteredSession['ok'] ?? false), 'new registered personal link should issue a session after reschedule');
    videochat_reschedule_stale_link_assert((int) (($newRegisteredSession['user'] ?? [])['id'] ?? 0) === $registeredUserId, 'new registered link should join as the current registered guest');
    videochat_reschedule_stale_link_assert((string) (($newRegisteredSession['call'] ?? [])['id'] ?? '') === $callId, 'new registered link should bind to the current call');

    $openCallCreate = videochat_create_call($pdo, $ownerUserId, [
        'title' => 'Reschedule Anonymous Open Link Safety',
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-10-21T09:00:00Z',
        'ends_at' => '2026-10-21T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_reschedule_stale_link_assert((bool) ($openCallCreate['ok'] ?? false), 'anonymous reschedule call should be created');
    $openCallId = (string) (($openCallCreate['call'] ?? [])['id'] ?? '');
    videochat_reschedule_stale_link_assert($openCallId !== '', 'anonymous reschedule call id should be present');

    $oldOpenAccessId = videochat_reschedule_stale_link_create_open($pdo, $openCallId, $ownerUserId, $tenantId, 'old anonymous open');
    $oldOpenSessionId = 'sess_reschedule_old_anonymous_open';
    $oldOpenSession = videochat_reschedule_stale_link_issue($pdo, $oldOpenAccessId, $oldOpenSessionId, ['guest_name' => 'Old Anonymous Reschedule Guest']);
    videochat_reschedule_stale_link_assert((bool) ($oldOpenSession['ok'] ?? false), 'old anonymous open session should issue before reschedule');
    $oldOpenGuestId = (int) (($oldOpenSession['user'] ?? [])['id'] ?? 0);
    videochat_reschedule_stale_link_assert($oldOpenGuestId > 0, 'old anonymous open link should allocate a temporary guest');

    $openUpdate = videochat_update_call($pdo, $openCallId, $ownerUserId, 'admin', [
        'starts_at' => '2026-10-21T11:00:00Z',
        'ends_at' => '2026-10-21T12:00:00Z',
    ], $tenantId);
    videochat_reschedule_stale_link_assert((bool) ($openUpdate['ok'] ?? false), 'anonymous owner reschedule should succeed');
    $openLifecycle = is_array($openUpdate['lifecycle'] ?? null) ? $openUpdate['lifecycle'] : [];
    videochat_reschedule_stale_link_assert(($openLifecycle['applied'] ?? null) === true, 'anonymous reschedule lifecycle should be applied');
    videochat_reschedule_stale_link_assert((int) ($openLifecycle['invalidated_link_count'] ?? 0) >= 1, 'anonymous reschedule should invalidate old open link');
    videochat_reschedule_stale_link_assert((int) ($openLifecycle['revoked_access_session_count'] ?? 0) >= 1, 'anonymous reschedule should revoke old open session');
    videochat_reschedule_stale_link_assert(videochat_reschedule_stale_link_count($pdo, 'SELECT COUNT(*) FROM call_access_links WHERE call_id = :call_id', [':call_id' => $openCallId]) === 0, 'old anonymous access links should be removed');
    videochat_reschedule_stale_link_assert((string) (videochat_reschedule_stale_link_user($pdo, $oldOpenGuestId)['status'] ?? '') === 'disabled', 'old anonymous temporary guest should be invalidated');
    videochat_reschedule_stale_link_assert(
        videochat_reschedule_stale_link_count($pdo, 'SELECT COUNT(*) FROM sessions WHERE id = :id AND revoked_at IS NOT NULL AND revoked_at <> \'\'', [':id' => $oldOpenSessionId]) === 1,
        'old anonymous session should be revoked'
    );
    videochat_reschedule_stale_link_assert_stale_access_denied($pdo, $oldOpenAccessId, 'anonymous_open');

    $newOpenAccessId = videochat_reschedule_stale_link_create_open($pdo, $openCallId, $ownerUserId, $tenantId, 'new anonymous open');
    $newOpenSession = videochat_reschedule_stale_link_issue($pdo, $newOpenAccessId, 'sess_reschedule_new_anonymous_open', ['guest_name' => 'New Anonymous Reschedule Guest']);
    videochat_reschedule_stale_link_assert((bool) ($newOpenSession['ok'] ?? false), 'new anonymous open link should issue after reschedule');
    $newOpenGuestId = (int) (($newOpenSession['user'] ?? [])['id'] ?? 0);
    videochat_reschedule_stale_link_assert($newOpenGuestId > 0 && $newOpenGuestId !== $oldOpenGuestId, 'new anonymous link should allocate a fresh temporary guest');
    videochat_reschedule_stale_link_assert((bool) (($newOpenSession['user'] ?? [])['is_guest'] ?? false), 'new anonymous link should use a temporary guest identity');
    videochat_reschedule_stale_link_assert((string) (($newOpenSession['call'] ?? [])['id'] ?? '') === $openCallId, 'new anonymous link should bind to the current call');
    $newOpenParticipant = videochat_reschedule_stale_link_participant($pdo, $openCallId, $newOpenGuestId);
    videochat_reschedule_stale_link_assert($newOpenParticipant === [], 'new anonymous guest should not create a lobby row before queueing');

    @unlink($databasePath);
    fwrite(STDOUT, "[{$contract}] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[{$contract}] ERROR: " . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
