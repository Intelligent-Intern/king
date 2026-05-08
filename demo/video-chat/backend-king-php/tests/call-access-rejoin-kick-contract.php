<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-rejoin-kick-membership-helper.php';

$label = 'call-access-rejoin-kick-contract';

try {
    videochat_iam_rejoin_contract_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_rejoin_contract_bootstrap_database('videochat-call-access-rejoin-kick');
    $ids = videochat_iam_rejoin_contract_fixture_ids($pdo, $label);
    $tenantId = $ids['tenant_id'];
    $ownerUserId = $ids['admin_user_id'];
    $participantUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-rejoin-participant@example.test',
        'IAM Rejoin Participant',
        $tenantId,
        $ids['organization_id']
    );
    $waitingUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-kick-waiting@example.test',
        'IAM Kick Waiting',
        $tenantId,
        $ids['organization_id']
    );

    $call = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $ownerUserId,
        [$participantUserId],
        $tenantId,
        'IAM Leave Rejoin Kick Contract'
    );
    $callId = $call['call_id'];
    $roomId = $call['room_id'];
    videochat_iam_rejoin_contract_set_invite_state($pdo, $callId, $participantUserId, 'allowed');

    $participantAuth = videochat_iam_rejoin_contract_issue_user_session(
        $pdo,
        $participantUserId,
        $tenantId,
        'sess_iam_rejoin_participant',
        $label
    );
    $openDatabase = static fn (): PDO => $pdo;
    $presenceState = videochat_presence_state_init();
    $lobbyState = videochat_lobby_state_init();
    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        $frames[$key] ??= [];
        $frames[$key][] = $payload;
        return true;
    };

    $ownerConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $ownerUserId,
        'Call Owner',
        'admin',
        'owner',
        $tenantId
    );
    $participantConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $participantUserId,
        'IAM Rejoin Participant',
        'user',
        'participant-before-leave',
        $tenantId,
        true,
        'sess_iam_rejoin_participant'
    );

    $initialSnapshot = videochat_realtime_room_snapshot_payload($presenceState, $ownerConnection, $openDatabase, 'initial');
    videochat_iam_rejoin_contract_assert((int) ($initialSnapshot['participant_count'] ?? 0) === 2, 'initial room snapshot should include owner and participant', $label);

    $frames = [];
    videochat_presence_remove_connection($presenceState, (string) ($participantConnection['connection_id'] ?? ''), $sender);
    videochat_realtime_remove_call_presence($openDatabase, $participantConnection);
    videochat_realtime_mark_call_participant_left($openDatabase, $participantConnection, $presenceState);
    $sentCount = videochat_realtime_broadcast_room_snapshot($presenceState, $roomId, $openDatabase, 'participant_left', (string) ($participantConnection['connection_id'] ?? ''), $sender);
    videochat_iam_rejoin_contract_assert($sentCount === 1, 'leave should broadcast one room snapshot to remaining owner', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_left_at($pdo, $callId, $participantUserId) !== '', 'leave should mark participant left_at in DB', $label);

    $postLeaveSnapshot = videochat_realtime_room_snapshot_payload($presenceState, $ownerConnection, $openDatabase, 'after_leave');
    videochat_iam_rejoin_contract_assert((int) ($postLeaveSnapshot['participant_count'] ?? 0) === 1, 'post-leave snapshot should have one participant', $label);

    $rejoinResolution = videochat_realtime_resolve_connection_rooms($participantAuth, $roomId, $openDatabase, $callId);
    videochat_iam_rejoin_contract_assert((bool) ($rejoinResolution['ok'] ?? false), 'rejoin room resolution should succeed', $label);
    videochat_iam_rejoin_contract_assert((string) ($rejoinResolution['initial_room_id'] ?? '') === $roomId, 'allowed participant should rejoin the original call room', $label);
    videochat_iam_rejoin_contract_assert((string) ($rejoinResolution['pending_room_id'] ?? '') === '', 'allowed participant rejoin must not return to lobby', $label);

    $participantRejoinConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $participantUserId,
        'IAM Rejoin Participant',
        'user',
        'participant-after-rejoin',
        $tenantId,
        true,
        'sess_iam_rejoin_participant'
    );
    videochat_iam_rejoin_contract_assert((string) ($participantRejoinConnection['active_call_id'] ?? '') === $callId, 'rejoined participant should keep active call context', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_left_at($pdo, $callId, $participantUserId) === '', 'rejoin should clear stale left_at', $label);

    $postRejoinSnapshot = videochat_realtime_room_snapshot_payload($presenceState, $ownerConnection, $openDatabase, 'after_rejoin');
    videochat_iam_rejoin_contract_assert((int) ($postRejoinSnapshot['participant_count'] ?? 0) === 2, 'post-rejoin snapshot should include owner and participant again', $label);

    $networkReconnectResolution = videochat_realtime_resolve_connection_rooms($participantAuth, $roomId, $openDatabase, $callId);
    videochat_iam_rejoin_contract_assert((string) ($networkReconnectResolution['initial_room_id'] ?? '') === $roomId, 'network reconnect should resolve back to the active room', $label);

    videochat_iam_rejoin_contract_admit_user($lobbyState, $roomId, $waitingUserId, 'IAM Kick Waiting');
    $participantKick = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $participantRejoinConnection,
        videochat_iam_rejoin_contract_lobby_command('lobby/kick', $roomId, $waitingUserId, $label),
        $sender
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($participantKick['ok'] ?? true), 'plain participant must not kick admitted users', $label);
    videochat_iam_rejoin_contract_assert((string) ($participantKick['error'] ?? '') === 'forbidden', 'plain participant kick denial should be forbidden', $label);
    videochat_iam_rejoin_contract_assert(isset($lobbyState['rooms'][$roomId]['admitted_by_user'][$waitingUserId]), 'failed kick must leave admitted user intact', $label);

    $ownerKick = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $ownerConnection,
        videochat_iam_rejoin_contract_lobby_command('lobby/kick', $roomId, $waitingUserId, $label),
        $sender
    );
    videochat_iam_rejoin_contract_assert((bool) ($ownerKick['ok'] ?? false), 'owner should be able to kick admitted users', $label);
    videochat_iam_rejoin_contract_assert((string) ($ownerKick['action'] ?? '') === 'lobby/remove', 'kick should normalize to lobby/remove', $label);
    videochat_iam_rejoin_contract_assert(!isset($lobbyState['rooms'][$roomId]['admitted_by_user'][$waitingUserId]), 'owner kick should remove admitted user from lobby state', $label);

    videochat_iam_rejoin_contract_queue_user($lobbyState, $roomId, $waitingUserId, 'IAM Kick Waiting');
    $participantReject = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $participantRejoinConnection,
        videochat_iam_rejoin_contract_lobby_command('lobby/reject', $roomId, $waitingUserId, $label),
        $sender
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($participantReject['ok'] ?? true), 'plain participant must not reject queued users', $label);
    videochat_iam_rejoin_contract_assert(isset($lobbyState['rooms'][$roomId]['queued_by_user'][$waitingUserId]), 'failed reject must leave queued user intact', $label);

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
