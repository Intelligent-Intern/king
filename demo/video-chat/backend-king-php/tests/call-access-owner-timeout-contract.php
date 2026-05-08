<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-king-participants-helper.php';

$label = 'call-access-owner-timeout-contract';

function videochat_iam_owner_timeout_call_status(PDO $pdo, string $callId): string
{
    $statement = $pdo->prepare('SELECT status FROM calls WHERE id = :call_id LIMIT 1');
    $statement->execute([':call_id' => $callId]);
    return strtolower(trim((string) ($statement->fetchColumn() ?: '')));
}

function videochat_iam_owner_timeout_left_at(PDO $pdo, string $callId, int $userId): string
{
    $statement = $pdo->prepare('SELECT left_at FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
    $statement->execute([':call_id' => $callId, ':user_id' => $userId]);
    return trim((string) ($statement->fetchColumn() ?: ''));
}

function videochat_iam_owner_timeout_count(PDO $pdo, string $sql, array $params = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return max(0, (int) ($statement->fetchColumn() ?: 0));
}

function videochat_iam_owner_timeout_user_status(PDO $pdo, int $userId): string
{
    $statement = $pdo->prepare('SELECT status FROM users WHERE id = :user_id LIMIT 1');
    $statement->execute([':user_id' => $userId]);
    return strtolower(trim((string) ($statement->fetchColumn() ?: '')));
}

function videochat_iam_owner_timeout_invite_state(PDO $pdo, string $callId, int $userId): string
{
    $statement = $pdo->prepare('SELECT invite_state FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
    $statement->execute([':call_id' => $callId, ':user_id' => $userId]);
    return strtolower(trim((string) ($statement->fetchColumn() ?: '')));
}

function videochat_iam_owner_timeout_lobby_waiting_count(PDO $pdo, string $callId): int
{
    return videochat_iam_owner_timeout_count(
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

function videochat_iam_owner_timeout_link_count(PDO $pdo, string $callId): int
{
    return videochat_iam_owner_timeout_count(
        $pdo,
        'SELECT COUNT(*) FROM call_access_links WHERE call_id = :call_id',
        [':call_id' => $callId]
    );
}

function videochat_iam_owner_timeout_session_revoked(PDO $pdo, string $sessionId): bool
{
    return videochat_iam_owner_timeout_count(
        $pdo,
        'SELECT COUNT(*) FROM sessions WHERE id = :session_id AND revoked_at IS NOT NULL AND revoked_at <> \'\'',
        [':session_id' => $sessionId]
    ) === 1;
}

function videochat_iam_owner_timeout_session_exists(PDO $pdo, string $sessionId): bool
{
    return videochat_iam_owner_timeout_count(
        $pdo,
        'SELECT COUNT(*) FROM sessions WHERE id = :session_id',
        [':session_id' => $sessionId]
    ) === 1;
}

function videochat_iam_owner_timeout_assert_auth_denied(PDO $pdo, string $sessionId, string $callId, string $label): void
{
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId) . '&call_id=' . rawurlencode($callId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($auth['ok'] ?? true), 'stale call-access session must not authenticate after owner-timeout end: ' . $sessionId, $label);
}

function videochat_iam_owner_timeout_create_temp_guest(PDO $pdo, string $name, int $tenantId, string $label): array
{
    $guest = videochat_create_guest_user_for_call_access($pdo, $name, $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($guest['ok'] ?? false), 'temporary guest should be created: ' . $name, $label);
    $user = is_array($guest['user'] ?? null) ? $guest['user'] : [];
    videochat_iam_rejoin_contract_assert((int) ($user['id'] ?? 0) > 0, 'temporary guest id should be present: ' . $name, $label);
    return $user;
}

function videochat_iam_owner_timeout_create_personal_link(PDO $pdo, string $callId, int $ownerUserId, int $userId, int $tenantId, string $label): string
{
    $link = videochat_create_call_access_link_for_user(
        $pdo,
        $callId,
        $ownerUserId,
        'admin',
        ['link_kind' => 'personal', 'participant_user_id' => $userId],
        $tenantId
    );
    videochat_iam_rejoin_contract_assert((bool) ($link['ok'] ?? false), 'personalized owner-timeout link should be created', $label);
    $accessId = (string) (($link['access_link'] ?? [])['id'] ?? '');
    videochat_iam_rejoin_contract_assert($accessId !== '', 'personalized owner-timeout link id should be present', $label);
    return $accessId;
}

function videochat_iam_owner_timeout_issue_access_session(PDO $pdo, string $accessId, string $sessionId, string $label): array
{
    $issued = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert((bool) ($issued['ok'] ?? false), 'call-access session should issue before owner timeout', $label);

    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_rejoin_contract_assert((bool) ($auth['ok'] ?? false), 'call-access session should authenticate before owner timeout', $label);
    return $issued;
}

try {
    videochat_iam_rejoin_contract_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_rejoin_contract_bootstrap_database('videochat-call-access-owner-timeout');
    $ids = videochat_iam_rejoin_contract_fixture_ids($pdo, $label);
    $tenantId = $ids['tenant_id'];
    $ownerUserId = $ids['admin_user_id'];
    $participantUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-owner-timeout-participant@example.test',
        'IAM Owner Timeout Participant',
        $tenantId,
        $ids['organization_id']
    );

    $call = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $ownerUserId,
        [$participantUserId],
        $tenantId,
        'IAM Owner Absence Timeout Contract'
    );
    $callId = $call['call_id'];
    $roomId = $call['room_id'];
    videochat_iam_rejoin_contract_set_invite_state($pdo, $callId, $participantUserId, 'allowed');

    $pendingGuest = videochat_iam_owner_timeout_create_temp_guest($pdo, 'IAM Owner Timeout Pending Guest', $tenantId, $label);
    $pendingGuestId = (int) ($pendingGuest['id'] ?? 0);
    $admittedGuest = videochat_iam_owner_timeout_create_temp_guest($pdo, 'IAM Owner Timeout Admitted Guest', $tenantId, $label);
    $admittedGuestId = (int) ($admittedGuest['id'] ?? 0);
    videochat_ensure_internal_call_participant(
        $pdo,
        $callId,
        $pendingGuestId,
        (string) ($pendingGuest['email'] ?? ''),
        (string) ($pendingGuest['display_name'] ?? ''),
        'pending'
    );
    videochat_ensure_internal_call_participant(
        $pdo,
        $callId,
        $admittedGuestId,
        (string) ($admittedGuest['email'] ?? ''),
        (string) ($admittedGuest['display_name'] ?? ''),
        'allowed'
    );
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET joined_at = :joined_at,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    )->execute([
        ':joined_at' => gmdate('c'),
        ':call_id' => $callId,
        ':user_id' => $admittedGuestId,
    ]);
    $personalAccessId = videochat_iam_owner_timeout_create_personal_link($pdo, $callId, $ownerUserId, $admittedGuestId, $tenantId, $label);
    $personalSessionId = 'sess_owner_timeout_personal_guest';
    videochat_iam_owner_timeout_issue_access_session($pdo, $personalAccessId, $personalSessionId, $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_link_count($pdo, $callId) >= 1, 'owner-timeout setup should have a personalized link', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_lobby_waiting_count($pdo, $callId) >= 1, 'owner-timeout setup should have a pending temporary lobby row', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_invite_state($pdo, $callId, $admittedGuestId) === 'allowed', 'owner-timeout setup should have admitted temporary participant state', $label);

    $presenceState = videochat_presence_state_init();
    $startMs = 1_778_000_000_000;
    $ownerConnection = videochat_iam_king_participant_client(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $ownerUserId,
        'IAM Owner',
        'admin',
        'owner',
        $tenantId,
        $startMs,
        'owner'
    );
    $participantConnection = videochat_iam_king_participant_client(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $participantUserId,
        'IAM Owner Timeout Participant',
        'user',
        'participant',
        $tenantId,
        $startMs + 1000,
        'participant'
    );

    videochat_iam_king_participant_touch($pdo, $ownerConnection, $startMs + 2000);
    videochat_iam_king_participant_touch($pdo, $participantConnection, $startMs + 2000);
    $initialSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $startMs + 2000, 'initial');
    videochat_iam_rejoin_contract_assert((int) ($initialSnapshot['participant_count'] ?? 0) === 2, 'simulated King participant clients should enter the call room', $label);
    $initialOwnerAbsence = (array) (($initialSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($initialOwnerAbsence['status'] ?? '') === 'owner_present', 'initial owner-absence state should detect the owner', $label);
    videochat_iam_rejoin_contract_assert((int) ($initialOwnerAbsence['timer_ms'] ?? 0) === 15 * 60 * 1000, 'owner absence timer must be 15 minutes', $label);
    videochat_iam_rejoin_contract_assert((int) ($initialOwnerAbsence['countdown_ms'] ?? 0) === 5 * 60 * 1000, 'owner absence countdown must be 5 minutes', $label);

    $ownerLeftMs = $startMs + 60_000;
    videochat_iam_king_participant_leave($pdo, $presenceState, $ownerConnection, $ownerLeftMs);

    $beforeCountdownMs = $ownerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS - 1000;
    videochat_iam_king_participant_touch($pdo, $participantConnection, $beforeCountdownMs);
    $beforeCountdownSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $beforeCountdownMs, 'owner_absence_monitoring');
    $beforeCountdown = (array) (($beforeCountdownSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($beforeCountdown['status'] ?? '') === 'monitoring', 'owner absence should monitor before the 15-minute threshold', $label);
    videochat_iam_rejoin_contract_assert((bool) ($beforeCountdown['countdown_started'] ?? true) === false, 'countdown must not start before the 15-minute timer', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', 'call must stay active before owner absence countdown', $label);

    $countdownStartMs = $ownerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS;
    videochat_iam_king_participant_touch($pdo, $participantConnection, $countdownStartMs);
    $countdownSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $countdownStartMs, 'owner_absence_countdown');
    $countdown = (array) (($countdownSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($countdown['status'] ?? '') === 'countdown', 'owner absence should enter countdown at 15 minutes', $label);
    videochat_iam_rejoin_contract_assert((bool) ($countdown['countdown_started'] ?? false) === true, 'owner absence countdown should be marked started', $label);
    videochat_iam_rejoin_contract_assert((int) ($countdown['countdown_remaining_ms'] ?? 0) === VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS, 'countdown should start with five minutes remaining', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', 'call must stay active at countdown start', $label);

    $ownerReturnMs = $ownerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS + 60_000;
    $ownerReturnConnection = videochat_iam_king_participant_client(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $ownerUserId,
        'IAM Owner',
        'admin',
        'owner',
        $tenantId,
        $ownerReturnMs,
        'owner-return'
    );
    videochat_iam_king_participant_touch($pdo, $participantConnection, $ownerReturnMs);
    $ownerReturnSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $ownerReturnMs, 'owner_returned');
    $ownerReturn = (array) (($ownerReturnSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($ownerReturn['status'] ?? '') === 'owner_present', 'owner return must cancel owner-absence countdown', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', 'owner return before deadline must keep the call active', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $ownerUserId) === '', 'owner return should clear the owner left_at marker', $label);

    $secondOwnerLeftMs = $ownerReturnMs + 60_000;
    videochat_iam_king_participant_leave($pdo, $presenceState, $ownerReturnConnection, $secondOwnerLeftMs);
    $deadlineMs = $secondOwnerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS;
    videochat_iam_king_participant_touch($pdo, $participantConnection, $deadlineMs);
    $endedSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $deadlineMs, 'owner_absence_deadline');
    $ended = (array) (($endedSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($ended['status'] ?? '') === 'ended', 'owner absence should end after timer plus countdown', $label);
    videochat_iam_rejoin_contract_assert((string) ($ended['ended_reason'] ?? '') === 'owner_absent_timeout', 'owner absence end reason mismatch', $label);
    videochat_iam_rejoin_contract_assert((bool) ($ended['transitioned'] ?? false) === true, 'owner absence helper should report the ended transition', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'ended', 'owner absence deadline should persist the call as ended', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $participantUserId) !== '', 'implicit ending should mark remaining participant left_at', $label);
    $endedLifecycle = (array) ($ended['lifecycle'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($endedLifecycle['transition'] ?? '') === 'ended', 'owner-timeout end should apply terminal lifecycle', $label);
    videochat_iam_rejoin_contract_assert((int) ($endedLifecycle['invalidated_link_count'] ?? 0) >= 1, 'owner-timeout end should invalidate personalized links', $label);
    videochat_iam_rejoin_contract_assert((int) ($endedLifecycle['revoked_access_session_count'] ?? 0) >= 1, 'owner-timeout end should revoke call-access sessions', $label);
    videochat_iam_rejoin_contract_assert((int) ($endedLifecycle['lobby_cleared_count'] ?? 0) >= 3, 'owner-timeout end should clear lobby/admitted participant state', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_link_count($pdo, $callId) === 0, 'owner-timeout end should delete call access links', $label);
    videochat_iam_rejoin_contract_assert((string) (videochat_resolve_call_access_public($pdo, $personalAccessId)['reason'] ?? '') === 'not_found', 'owner-timeout end should invalidate personalized invite link', $label);
    $latePersonalSession = videochat_issue_session_for_call_access(
        $pdo,
        $personalAccessId,
        static fn (): string => 'sess_owner_timeout_late_personal',
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($latePersonalSession['ok'] ?? true), 'owner-timeout ended personalized link must not issue a new session', $label);
    videochat_iam_rejoin_contract_assert(!videochat_iam_owner_timeout_session_exists($pdo, 'sess_owner_timeout_late_personal'), 'owner-timeout denied late session must not be stored', $label);
    $directJoinAfterEnd = videochat_decide_call_access_for_user($pdo, $callId, $participantUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert(!(bool) ($directJoinAfterEnd['allowed'] ?? true), 'owner-timeout ended state should block fresh direct joins', $label);
    videochat_iam_rejoin_contract_assert((string) ($directJoinAfterEnd['reason'] ?? '') === 'call_not_joinable_from_status', 'owner-timeout fresh join denial reason mismatch', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_session_revoked($pdo, $personalSessionId), 'owner-timeout end should revoke personalized session', $label);
    videochat_iam_owner_timeout_assert_auth_denied($pdo, $personalSessionId, $callId, $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_user_status($pdo, $pendingGuestId) === 'disabled', 'owner-timeout end should disable pending temporary guest', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_user_status($pdo, $admittedGuestId) === 'disabled', 'owner-timeout end should disable admitted temporary guest', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_lobby_waiting_count($pdo, $callId) === 0, 'owner-timeout end should clear lobby rows', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_invite_state($pdo, $callId, $pendingGuestId) === 'cancelled', 'owner-timeout end should cancel pending lobby participant', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_invite_state($pdo, $callId, $admittedGuestId) === 'cancelled', 'owner-timeout end should cancel admitted temporary participant', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $admittedGuestId) !== '', 'owner-timeout end should mark admitted temporary participant left', $label);
    videochat_iam_rejoin_contract_assert(
        videochat_iam_owner_timeout_count($pdo, "SELECT COUNT(*) FROM videochat_audit_events WHERE call_id = :call_id AND event_type = 'call_ended'", [':call_id' => $callId]) >= 1,
        'owner-timeout end should preserve call-ended audit log',
        $label
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[{$label}] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[{$label}] ERROR: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
