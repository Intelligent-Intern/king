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

    $beforeCountdownMs = $ownerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - 1000;
    videochat_iam_king_participant_touch($pdo, $participantConnection, $beforeCountdownMs);
    $beforeCountdownSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $beforeCountdownMs, 'owner_absence_monitoring');
    $beforeCountdown = (array) (($beforeCountdownSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($beforeCountdown['status'] ?? '') === 'monitoring', 'owner absence should monitor before the 15-minute threshold', $label);
    videochat_iam_rejoin_contract_assert((bool) ($beforeCountdown['countdown_started'] ?? true) === false, 'countdown must not start before the 15-minute timer', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', 'call must stay active before owner absence countdown', $label);

    $countdownStartMs = $ownerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS;
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
    $deadlineMs = $secondOwnerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS + VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS;
    videochat_iam_king_participant_touch($pdo, $participantConnection, $deadlineMs);
    $endedSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $deadlineMs, 'owner_absence_deadline');
    $ended = (array) (($endedSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($ended['status'] ?? '') === 'ended', 'owner absence should end after timer plus countdown', $label);
    videochat_iam_rejoin_contract_assert((string) ($ended['ended_reason'] ?? '') === 'owner_absent_timeout', 'owner absence end reason mismatch', $label);
    videochat_iam_rejoin_contract_assert((bool) ($ended['transitioned'] ?? false) === true, 'owner absence helper should report the ended transition', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'ended', 'owner absence deadline should persist the call as ended', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $participantUserId) !== '', 'implicit ending should mark remaining participant left_at', $label);

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
