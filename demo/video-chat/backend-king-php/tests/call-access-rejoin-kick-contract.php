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
    $activeKickUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-active-kick-target@example.test',
        'IAM Active Kick Target',
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
        [$participantUserId, $activeKickUserId],
        $tenantId,
        'IAM Leave Rejoin Kick Contract'
    );
    $callId = $call['call_id'];
    $roomId = $call['room_id'];
    videochat_iam_rejoin_contract_set_invite_state($pdo, $callId, $participantUserId, 'allowed');
    videochat_iam_rejoin_contract_set_invite_state($pdo, $callId, $activeKickUserId, 'allowed');

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
    $sentCount = videochat_realtime_broadcast_room_snapshot($presenceState, $roomId, $openDatabase, 'participant_left', (string) ($participantConnection['connection_id'] ?? ''), $sender, $tenantId);
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

    $activeKickCase = 'e2e_security_009_kick_during_active_call_removes_user';
    $loggedInKickRejoinCase = 'e2e_rejoin_010_kicked_logged_in_user_cannot_direct_rejoin';
    $activeKickAuth = videochat_iam_rejoin_contract_issue_user_session(
        $pdo,
        $activeKickUserId,
        $tenantId,
        'sess_iam_active_kick_target',
        $label
    );
    $activeKickConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $activeKickUserId,
        'IAM Active Kick Target',
        'user',
        'active-kick-target',
        $tenantId,
        true,
        'sess_iam_active_kick_target'
    );
    videochat_iam_rejoin_contract_assert((string) ($activeKickConnection['active_call_id'] ?? '') === $callId, "{$activeKickCase}: target should start inside active call", $label);
    $preKickSnapshot = videochat_realtime_room_snapshot_payload($presenceState, $ownerConnection, $openDatabase, 'before_active_kick');
    videochat_iam_rejoin_contract_assert((int) ($preKickSnapshot['participant_count'] ?? 0) === 3, "{$activeKickCase}: owner snapshot should include active target before kick", $label);

    $activeKick = videochat_iam_rejoin_contract_apply_lobby_command(
        $lobbyState,
        $presenceState,
        $ownerConnection,
        $openDatabase,
        'lobby/remove',
        $roomId,
        $activeKickUserId,
        $label
    );
    videochat_iam_rejoin_contract_assert((bool) ($activeKick['ok'] ?? false), "{$activeKickCase}: owner should remove active participant", $label);
    videochat_iam_rejoin_contract_assert(
        in_array($activeKickUserId, array_map('intval', (array) ($activeKick['active_target_user_ids'] ?? [])), true),
        "{$activeKickCase}: active target should be named for active-call removal",
        $label
    );
    videochat_iam_rejoin_contract_assert(
        !videochat_lobby_user_present_in_room($presenceState, $roomId, $activeKickUserId, null, $tenantId),
        "{$activeKickCase}: active target should be removed from live presence",
        $label
    );
    videochat_iam_rejoin_contract_assert(
        !videochat_realtime_presence_db_has_room_membership($pdo, $roomId, $callId, $activeKickUserId),
        "{$activeKickCase}: active target should be removed from persistent presence",
        $label
    );
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_left_at($pdo, $callId, $activeKickUserId) !== '', "{$activeKickCase}: active target should persist left_at", $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_invite_state($pdo, $callId, $activeKickUserId) === 'invited', "{$activeKickCase}: kick should reset direct admission", $label);
    $postKickSnapshot = videochat_realtime_room_snapshot_payload($presenceState, $ownerConnection, $openDatabase, 'after_active_kick');
    videochat_iam_rejoin_contract_assert((int) ($postKickSnapshot['participant_count'] ?? 0) === 2, "{$activeKickCase}: target should disappear from room snapshot", $label);
    $activeKickRejoinResolution = videochat_realtime_resolve_connection_rooms($activeKickAuth, $roomId, $openDatabase, $callId);
    videochat_iam_rejoin_contract_assert((string) ($activeKickRejoinResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), "{$loggedInKickRejoinCase}: kicked user must not directly rejoin", $label);
    videochat_iam_rejoin_contract_assert((string) ($activeKickRejoinResolution['pending_room_id'] ?? '') === $roomId, "{$loggedInKickRejoinCase}: kicked user should require renewed approval", $label);

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

    $tempCase = 'e2e_rejoin_004_kicked_temp_user_cannot_direct_rejoin';
    $overrideCase = 'e2e_rejoin_005_kick_overrides_previous_admission';
    $tempCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $ownerUserId,
        [],
        $tenantId,
        'IAM Temporary Guest Kick Rejoin Contract',
        'free_for_all'
    );
    $tempCallId = $tempCall['call_id'];
    $tempRoomId = $tempCall['room_id'];
    $tempSessionId = 'sess_iam_temp_guest_kick_rejoin';
    $tempGuestSession = videochat_iam_rejoin_contract_issue_open_guest_session(
        $pdo,
        $tempCallId,
        $ownerUserId,
        $tenantId,
        $tempSessionId,
        'Temporary Kick Rejoin Guest',
        $label
    );
    $tempGuestUser = (array) ($tempGuestSession['user'] ?? []);
    $tempGuestUserId = (int) ($tempGuestUser['id'] ?? 0);
    videochat_iam_rejoin_contract_assert($tempGuestUserId > 0, "{$tempCase}: temporary guest user id should exist", $label);

    $tempInitialResolution = videochat_realtime_resolve_connection_rooms(
        (array) ($tempGuestSession['auth'] ?? []),
        $tempRoomId,
        $openDatabase,
        $tempCallId
    );
    videochat_iam_rejoin_contract_assert((bool) ($tempInitialResolution['ok'] ?? false), "{$tempCase}: initial temp guest room resolution should succeed", $label);
    videochat_iam_rejoin_contract_assert((string) ($tempInitialResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), "{$tempCase}: temporary guest should wait before approval", $label);
    videochat_iam_rejoin_contract_assert((string) ($tempInitialResolution['pending_room_id'] ?? '') === $tempRoomId, "{$tempCase}: temporary guest pending room should stay bound to call room", $label);

    $tempPresenceState = videochat_presence_state_init();
    $tempLobbyState = videochat_lobby_state_init();
    $tempOwnerConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $tempPresenceState,
        $tempRoomId,
        $tempCallId,
        $ownerUserId,
        'Call Owner',
        'admin',
        'temp-owner',
        $tenantId
    );
    $tempGuestWaitingConnection = videochat_iam_rejoin_contract_waiting_connection(
        $pdo,
        $tempPresenceState,
        $tempRoomId,
        $tempCallId,
        $tempGuestUserId,
        'Temporary Kick Rejoin Guest',
        'temp-guest-waiting',
        $tenantId,
        $tempSessionId
    );

    $tempQueue = videochat_iam_rejoin_contract_apply_lobby_command(
        $tempLobbyState,
        $tempPresenceState,
        $tempGuestWaitingConnection,
        $openDatabase,
        'lobby/queue/join',
        $tempRoomId,
        0,
        $label
    );
    videochat_iam_rejoin_contract_assert((bool) ($tempQueue['ok'] ?? false), "{$tempCase}: temporary guest should queue for renewed approval", $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_invite_state($pdo, $tempCallId, $tempGuestUserId) === 'pending', "{$tempCase}: queued temporary guest should persist pending state", $label);

    $tempAllow = videochat_iam_rejoin_contract_apply_lobby_command(
        $tempLobbyState,
        $tempPresenceState,
        $tempOwnerConnection,
        $openDatabase,
        'lobby/allow',
        $tempRoomId,
        $tempGuestUserId,
        $label
    );
    videochat_iam_rejoin_contract_assert((bool) ($tempAllow['ok'] ?? false), "{$tempCase}: owner should approve temporary guest", $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_invite_state($pdo, $tempCallId, $tempGuestUserId) === 'allowed', "{$tempCase}: approval should persist allowed state", $label);

    $tempApprovedResolution = videochat_realtime_resolve_connection_rooms(
        (array) ($tempGuestSession['auth'] ?? []),
        $tempRoomId,
        $openDatabase,
        $tempCallId
    );
    videochat_iam_rejoin_contract_assert((string) ($tempApprovedResolution['initial_room_id'] ?? '') === $tempRoomId, "{$tempCase}: approval should allow direct call-room entry", $label);

    $tempKick = videochat_iam_rejoin_contract_apply_lobby_command(
        $tempLobbyState,
        $tempPresenceState,
        $tempOwnerConnection,
        $openDatabase,
        'lobby/kick',
        $tempRoomId,
        $tempGuestUserId,
        $label
    );
    videochat_iam_rejoin_contract_assert((bool) ($tempKick['ok'] ?? false), "{$tempCase}: owner should kick the admitted temporary guest", $label);
    videochat_iam_rejoin_contract_assert((string) ($tempKick['action'] ?? '') === 'lobby/remove', "{$tempCase}: kick should apply the removal state transition", $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_invite_state($pdo, $tempCallId, $tempGuestUserId) === 'invited', "{$overrideCase}: kick should override the previous allowed admission", $label);

    $tempKickedResolution = videochat_realtime_resolve_connection_rooms(
        (array) ($tempGuestSession['auth'] ?? []),
        $tempRoomId,
        $openDatabase,
        $tempCallId
    );
    videochat_iam_rejoin_contract_assert((bool) ($tempKickedResolution['ok'] ?? false), "{$tempCase}: kicked temporary guest room resolution should remain explicit", $label);
    videochat_iam_rejoin_contract_assert((string) ($tempKickedResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), "{$tempCase}: kicked temporary guest must not directly rejoin the call room", $label);
    videochat_iam_rejoin_contract_assert((string) ($tempKickedResolution['pending_room_id'] ?? '') === $tempRoomId, "{$tempCase}: kicked temporary guest should require renewed approval for the same room", $label);

    $tempRenewedQueue = videochat_iam_rejoin_contract_apply_lobby_command(
        $tempLobbyState,
        $tempPresenceState,
        $tempGuestWaitingConnection,
        $openDatabase,
        'lobby/queue/join',
        $tempRoomId,
        0,
        $label
    );
    videochat_iam_rejoin_contract_assert((bool) ($tempRenewedQueue['ok'] ?? false), "{$overrideCase}: kicked temporary guest should be able to request renewed approval", $label);
    $tempRenewedAllow = videochat_iam_rejoin_contract_apply_lobby_command(
        $tempLobbyState,
        $tempPresenceState,
        $tempOwnerConnection,
        $openDatabase,
        'lobby/allow',
        $tempRoomId,
        $tempGuestUserId,
        $label
    );
    videochat_iam_rejoin_contract_assert((bool) ($tempRenewedAllow['ok'] ?? false), "{$overrideCase}: renewed approval should succeed after kick", $label);
    $tempRenewedResolution = videochat_realtime_resolve_connection_rooms(
        (array) ($tempGuestSession['auth'] ?? []),
        $tempRoomId,
        $openDatabase,
        $tempCallId
    );
    videochat_iam_rejoin_contract_assert((string) ($tempRenewedResolution['initial_room_id'] ?? '') === $tempRoomId, "{$overrideCase}: only renewed approval should restore direct call-room entry", $label);

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
