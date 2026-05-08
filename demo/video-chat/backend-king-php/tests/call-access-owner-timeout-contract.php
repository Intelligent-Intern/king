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

function videochat_iam_owner_timeout_seed_active_call(
    PDO $pdo,
    int $tenantId,
    int $ownerUserId,
    int $participantUserId,
    string $title
): array {
    $call = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $ownerUserId,
        [$participantUserId],
        $tenantId,
        $title
    );
    videochat_iam_rejoin_contract_set_invite_state($pdo, (string) $call['call_id'], $participantUserId, 'allowed');
    return $call;
}

function videochat_iam_owner_timeout_drop_owner_context(
    PDO $pdo,
    array &$presenceState,
    array $ownerConnection,
    int $leftAtMs
): array {
    $connectionId = (string) ($ownerConnection['connection_id'] ?? '');
    $droppedConnection = videochat_presence_remove_connection($presenceState, $connectionId);
    $effectiveConnection = is_array($droppedConnection) ? $droppedConnection : $ownerConnection;
    videochat_realtime_remove_call_presence(static fn (): PDO => $pdo, $effectiveConnection);
    videochat_iam_king_participant_set_times(
        $pdo,
        videochat_realtime_connection_call_id($effectiveConnection),
        (int) ($effectiveConnection['user_id'] ?? 0),
        null,
        $leftAtMs
    );
    return $effectiveConnection;
}

function videochat_iam_owner_timeout_assert_abrupt_absence_mode(
    PDO $pdo,
    int $tenantId,
    int $ownerUserId,
    int $participantUserId,
    string $mode,
    int $offsetMs,
    string $label
): void {
    $call = videochat_iam_owner_timeout_seed_active_call(
        $pdo,
        $tenantId,
        $ownerUserId,
        $participantUserId,
        'IAM Owner Absence ' . str_replace('_', ' ', $mode)
    );
    $callId = (string) $call['call_id'];
    $roomId = (string) $call['room_id'];
    $presenceState = videochat_presence_state_init();
    $startMs = 1_779_000_000_000 + $offsetMs;
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
        'owner-' . $mode
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
        'participant-' . $mode
    );

    videochat_iam_king_participant_touch($pdo, $ownerConnection, $startMs + 2000);
    videochat_iam_king_participant_touch($pdo, $participantConnection, $startMs + 2000);

    if ($mode === 'owner_network_disconnected') {
        $networkAbsentSinceMs = $startMs + 2000 + videochat_realtime_presence_db_ttl_ms();
        $monitorMs = $networkAbsentSinceMs + 1000;
        videochat_iam_king_participant_touch($pdo, $participantConnection, $monitorMs);
        $monitorSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $monitorMs, $mode);
        $ownerAbsence = (array) (($monitorSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
        videochat_iam_rejoin_contract_assert((string) ($ownerAbsence['status'] ?? '') === 'monitoring', 'owner network loss should start owner-absence monitoring', $label);
        videochat_iam_rejoin_contract_assert((int) ($ownerAbsence['absent_since_ms'] ?? 0) === $networkAbsentSinceMs, 'owner network loss should persist absence from stale heartbeat cutoff', $label);
        videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $ownerUserId) === videochat_iam_king_participant_iso($networkAbsentSinceMs), 'owner network loss should persist owner left_at from stale heartbeat cutoff', $label);
    } else {
        $leftAtMs = $startMs + 60_000;
        videochat_iam_owner_timeout_drop_owner_context($pdo, $presenceState, $ownerConnection, $leftAtMs);
        videochat_iam_king_participant_touch($pdo, $participantConnection, $leftAtMs + 1000);
        $monitorSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $leftAtMs + 1000, $mode);
        $ownerAbsence = (array) (($monitorSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
        videochat_iam_rejoin_contract_assert((string) ($ownerAbsence['status'] ?? '') === 'monitoring', "{$mode} should start owner-absence monitoring without explicit call end", $label);
        videochat_iam_rejoin_contract_assert((int) ($ownerAbsence['absent_since_ms'] ?? 0) === $leftAtMs, "{$mode} should use the server-side owner departure time", $label);
    }

    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', "{$mode} should not explicitly end the call", $label);
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

    $call = videochat_iam_owner_timeout_seed_active_call(
        $pdo,
        $tenantId,
        $ownerUserId,
        $participantUserId,
        'IAM Owner Absence Timeout Contract'
    );
    $callId = $call['call_id'];
    $roomId = $call['room_id'];

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

    $ownerReturnBeforeCountdownMs = $ownerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS - 1000;
    $ownerReturnBeforeCountdownConnection = videochat_iam_king_participant_client(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $ownerUserId,
        'IAM Owner',
        'admin',
        'owner',
        $tenantId,
        $ownerReturnBeforeCountdownMs,
        'owner-return-before-countdown'
    );
    videochat_iam_king_participant_touch($pdo, $participantConnection, $ownerReturnBeforeCountdownMs);
    $ownerReturnBeforeCountdownSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $ownerReturnBeforeCountdownMs, 'owner_returned_before_countdown');
    $ownerReturnBeforeCountdown = (array) (($ownerReturnBeforeCountdownSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($ownerReturnBeforeCountdown['status'] ?? '') === 'owner_present', 'owner return before final countdown must cancel owner-absence monitoring', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', 'owner return before final countdown must keep the call active', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $ownerUserId) === '', 'owner return before final countdown should clear owner left_at', $label);

    $secondOwnerLeftMs = $ownerReturnBeforeCountdownMs + 60_000;
    videochat_iam_king_participant_leave($pdo, $presenceState, $ownerReturnBeforeCountdownConnection, $secondOwnerLeftMs);

    $countdownStartMs = $secondOwnerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS;
    videochat_iam_king_participant_touch($pdo, $participantConnection, $countdownStartMs);
    $countdownSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $countdownStartMs, 'owner_absence_countdown');
    $countdown = (array) (($countdownSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($countdown['status'] ?? '') === 'countdown', 'owner absence should enter countdown at 15 minutes', $label);
    videochat_iam_rejoin_contract_assert((bool) ($countdown['countdown_started'] ?? false) === true, 'owner absence countdown should be marked started', $label);
    videochat_iam_rejoin_contract_assert((int) ($countdown['countdown_remaining_ms'] ?? 0) === VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS, 'countdown should start with five minutes remaining', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', 'call must stay active at countdown start', $label);

    $ownerReturnMs = $countdownStartMs + 60_000;
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
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', 'owner return during countdown must keep the call active', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $ownerUserId) === '', 'owner return should clear the owner left_at marker', $label);

    $thirdOwnerLeftMs = $ownerReturnMs + 60_000;
    videochat_iam_king_participant_leave($pdo, $presenceState, $ownerReturnConnection, $thirdOwnerLeftMs);
    $deadlineMs = $thirdOwnerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS;
    videochat_iam_king_participant_touch($pdo, $participantConnection, $deadlineMs);
    $endedSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $deadlineMs, 'owner_absence_deadline');
    $ended = (array) (($endedSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($ended['status'] ?? '') === 'ended', 'owner absence should end after timer plus countdown: ' . json_encode($ended), $label);
    videochat_iam_rejoin_contract_assert((string) ($ended['ended_reason'] ?? '') === 'owner_absent_timeout', 'owner absence end reason mismatch', $label);
    videochat_iam_rejoin_contract_assert((bool) ($ended['transitioned'] ?? false) === true, 'owner absence helper should report the ended transition', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'ended', 'owner absence deadline should persist the call as ended', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $participantUserId) !== '', 'implicit ending should mark remaining participant left_at', $label);

    videochat_iam_owner_timeout_assert_abrupt_absence_mode($pdo, $tenantId, $ownerUserId, $participantUserId, 'owner_browser_crash', 10_000_000, $label);
    videochat_iam_owner_timeout_assert_abrupt_absence_mode($pdo, $tenantId, $ownerUserId, $participantUserId, 'owner_context_killed', 20_000_000, $label);
    videochat_iam_owner_timeout_assert_abrupt_absence_mode($pdo, $tenantId, $ownerUserId, $participantUserId, 'owner_network_disconnected', 30_000_000, $label);

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
