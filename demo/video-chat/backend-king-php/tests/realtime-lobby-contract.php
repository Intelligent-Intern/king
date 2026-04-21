<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';

function videochat_realtime_lobby_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-lobby-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_realtime_lobby_last_frame(array $frames, string $socket): array
{
    $socketFrames = $frames[$socket] ?? [];
    if (!is_array($socketFrames) || $socketFrames === []) {
        return [];
    }

    $last = end($socketFrames);
    return is_array($last) ? $last : [];
}

try {
    $presenceState = videochat_presence_state_init();
    $lobbyState = videochat_lobby_state_init();
    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        if (!isset($frames[$key]) || !is_array($frames[$key])) {
            $frames[$key] = [];
        }
        $frames[$key][] = $payload;
        return true;
    };

    $moderator = videochat_presence_connection_descriptor(
        [
            'id' => 10,
            'display_name' => 'Moderator',
            'role' => 'moderator',
        ],
        'sess-mod',
        'conn-mod',
        'socket-mod',
        'lobby'
    );
    $moderatorJoin = videochat_presence_join_room($presenceState, $moderator, 'lobby', $sender);
    $moderator = (array) ($moderatorJoin['connection'] ?? $moderator);

    $userA = videochat_presence_connection_descriptor(
        [
            'id' => 20,
            'display_name' => 'User A',
            'role' => 'user',
        ],
        'sess-a',
        'conn-a',
        'socket-a',
        'lobby'
    );
    $userAJoin = videochat_presence_join_room($presenceState, $userA, 'lobby', $sender);
    $userA = (array) ($userAJoin['connection'] ?? $userA);

    $userASecondConnection = videochat_presence_connection_descriptor(
        [
            'id' => 20,
            'display_name' => 'User A',
            'role' => 'user',
        ],
        'sess-a-second',
        'conn-a-2',
        'socket-a-2',
        'lobby'
    );
    $userASecondJoin = videochat_presence_join_room($presenceState, $userASecondConnection, 'lobby', $sender);
    $userASecondConnection = (array) ($userASecondJoin['connection'] ?? $userASecondConnection);

    $userB = videochat_presence_connection_descriptor(
        [
            'id' => 30,
            'display_name' => 'User B',
            'role' => 'user',
        ],
        'sess-b',
        'conn-b',
        'socket-b',
        'lobby'
    );
    $userBJoin = videochat_presence_join_room($presenceState, $userB, 'lobby', $sender);
    $userB = (array) ($userBJoin['connection'] ?? $userB);

    $otherRoomUser = videochat_presence_connection_descriptor(
        [
            'id' => 40,
            'display_name' => 'Other Room User',
            'role' => 'user',
        ],
        'sess-other',
        'conn-other',
        'socket-other',
        'other-room'
    );
    $otherRoomJoin = videochat_presence_join_room($presenceState, $otherRoomUser, 'other-room', $sender);
    $otherRoomUser = (array) ($otherRoomJoin['connection'] ?? $otherRoomUser);

    $frames = [];

    $queueJoinCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/queue/join',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_assert((bool) ($queueJoinCommand['ok'] ?? false), 'lobby/queue/join should decode');

    $queueJoinResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $userA,
        $queueJoinCommand,
        $sender,
        1_780_400_100_000
    );
    videochat_realtime_lobby_assert((bool) ($queueJoinResult['ok'] ?? false), 'queue join should succeed');
    videochat_realtime_lobby_assert((bool) ($queueJoinResult['changed'] ?? false), 'first queue join should change queue state');
    videochat_realtime_lobby_assert((int) ($queueJoinResult['sent_count'] ?? 0) === 4, 'queue join should broadcast to lobby room sockets only');

    $moderatorSnapshot = videochat_realtime_lobby_last_frame($frames, 'socket-mod');
    $userASnapshot = videochat_realtime_lobby_last_frame($frames, 'socket-a');
    $userASecondSnapshot = videochat_realtime_lobby_last_frame($frames, 'socket-a-2');
    $userBSnapshot = videochat_realtime_lobby_last_frame($frames, 'socket-b');
    $otherRoomSnapshot = videochat_realtime_lobby_last_frame($frames, 'socket-other');
    videochat_realtime_lobby_assert((string) ($moderatorSnapshot['type'] ?? '') === 'lobby/snapshot', 'moderator should receive lobby snapshot');
    videochat_realtime_lobby_assert((string) ($userASnapshot['type'] ?? '') === 'lobby/snapshot', 'queue sender should receive lobby snapshot');
    videochat_realtime_lobby_assert((string) ($userASecondSnapshot['type'] ?? '') === 'lobby/snapshot', 'queue sender second connection should receive lobby snapshot');
    videochat_realtime_lobby_assert((string) ($userBSnapshot['type'] ?? '') === 'lobby/snapshot', 'non-moderator room peer should receive redacted lobby snapshot');
    videochat_realtime_lobby_assert($otherRoomSnapshot === [], 'other room must not receive lobby snapshot');
    videochat_realtime_lobby_assert((int) ($moderatorSnapshot['queue_count'] ?? 0) === 1, 'queue_count should be one after first join');
    videochat_realtime_lobby_assert((int) (($moderatorSnapshot['queue'][0]['user_id'] ?? 0)) === 20, 'queued user id mismatch');
    videochat_realtime_lobby_assert((int) ($userASecondSnapshot['queue_count'] ?? 0) === 1, 'same queued user connection should see own queued state');
    videochat_realtime_lobby_assert((int) ($userBSnapshot['queue_count'] ?? -1) === 0, 'non-moderator room peer must not see other lobby queue entries');
    videochat_realtime_lobby_assert(($userBSnapshot['queue'] ?? []) === [], 'non-moderator room peer queue payload must be redacted');

    $frames = [];
    $queueJoinAgain = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $userA,
        $queueJoinCommand,
        $sender,
        1_780_400_101_000
    );
    videochat_realtime_lobby_assert((bool) ($queueJoinAgain['ok'] ?? false), 'repeated queue join should stay successful');
    videochat_realtime_lobby_assert(!(bool) ($queueJoinAgain['changed'] ?? true), 'repeated queue join should not mutate queue');
    videochat_realtime_lobby_assert((int) ($queueJoinAgain['sent_count'] ?? 0) === 4, 'repeated queue join should rebroadcast lobby snapshot');
    $repeatedModeratorSnapshot = videochat_realtime_lobby_last_frame($frames, 'socket-mod');
    videochat_realtime_lobby_assert((string) ($repeatedModeratorSnapshot['type'] ?? '') === 'lobby/snapshot', 'moderator should receive repeated queued snapshot');
    videochat_realtime_lobby_assert((string) ($repeatedModeratorSnapshot['reason'] ?? '') === 'already_queued', 'repeated queued snapshot reason mismatch');

    $invalidSenderConnection = $userA;
    $invalidSenderConnection['user_id'] = 0;
    $invalidSenderResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $invalidSenderConnection,
        $queueJoinCommand,
        $sender,
        1_780_400_101_500
    );
    videochat_realtime_lobby_assert(!(bool) ($invalidSenderResult['ok'] ?? true), 'invalid sender lobby command should fail');
    videochat_realtime_lobby_assert((string) ($invalidSenderResult['error'] ?? '') === 'invalid_sender', 'invalid sender lobby error mismatch');

    $senderNotInRoomConnection = $userA;
    $senderNotInRoomConnection['room_id'] = 'other-room';
    $senderNotInRoomResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $senderNotInRoomConnection,
        $queueJoinCommand,
        $sender,
        1_780_400_101_800
    );
    videochat_realtime_lobby_assert(!(bool) ($senderNotInRoomResult['ok'] ?? true), 'sender outside room lobby command should fail');
    videochat_realtime_lobby_assert((string) ($senderNotInRoomResult['error'] ?? '') === 'sender_not_in_room', 'sender outside room lobby error mismatch');

    $waitingPresenceState = videochat_presence_state_init();
    $waitingLobbyState = videochat_lobby_state_init();
    $waitingConnection = videochat_presence_connection_descriptor(
        [
            'id' => 50,
            'display_name' => 'Waiting User',
            'role' => 'user',
        ],
        'sess-waiting',
        'conn-waiting',
        'socket-waiting',
        'waiting-room'
    );
    $waitingConnection['pending_room_id'] = 'lobby';
    $waitingJoin = videochat_presence_join_room($waitingPresenceState, $waitingConnection, 'waiting-room');
    $waitingConnection = (array) ($waitingJoin['connection'] ?? $waitingConnection);
    $waitingConnection['pending_room_id'] = 'lobby';
    $waitingPresenceState['connections']['conn-waiting']['pending_room_id'] = 'lobby';
    videochat_lobby_send_snapshot_to_connection(
        $waitingLobbyState,
        $waitingConnection,
        'joined_room',
        $sender,
        1_780_400_101_890
    );
    $waitingAttachSnapshot = videochat_realtime_lobby_last_frame($frames, 'socket-waiting');
    videochat_realtime_lobby_assert((string) ($waitingAttachSnapshot['type'] ?? '') === 'lobby/snapshot', 'waiting-room attach should receive lobby snapshot');
    videochat_realtime_lobby_assert((string) ($waitingAttachSnapshot['room_id'] ?? '') === 'lobby', 'waiting-room attach snapshot should target pending room');
    videochat_realtime_lobby_assert((int) ($waitingAttachSnapshot['queue_count'] ?? -1) === 0, 'waiting-room attach must not queue user');
    $waitingTargetSnapshotBeforeJoin = videochat_lobby_snapshot_payload($waitingLobbyState, 'lobby', 'assert_attach_no_queue');
    videochat_realtime_lobby_assert((int) ($waitingTargetSnapshotBeforeJoin['queue_count'] ?? -1) === 0, 'target room queue must stay empty until explicit join');
    $waitingQueueJoinCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/queue/join',
        'room_id' => 'lobby',
    ], JSON_UNESCAPED_SLASHES));
    $frames = [];
    $waitingQueueJoinResult = videochat_lobby_apply_command(
        $waitingLobbyState,
        $waitingPresenceState,
        $waitingConnection,
        $waitingQueueJoinCommand,
        $sender,
        1_780_400_101_900
    );
    videochat_realtime_lobby_assert((bool) ($waitingQueueJoinResult['ok'] ?? false), 'waiting-room user should queue for pending room');
    videochat_realtime_lobby_assert((bool) ($waitingQueueJoinResult['changed'] ?? false), 'waiting-room queue join should mutate queue');
    videochat_realtime_lobby_assert((string) ($waitingQueueJoinResult['room_id'] ?? '') === 'lobby', 'waiting-room queue target room mismatch');
    videochat_realtime_lobby_assert((int) ($waitingQueueJoinResult['sent_count'] ?? 0) === 1, 'waiting-room queue join should confirm snapshot to requester');
    $waitingQueueSnapshot = videochat_realtime_lobby_last_frame($frames, 'socket-waiting');
    videochat_realtime_lobby_assert((string) ($waitingQueueSnapshot['type'] ?? '') === 'lobby/snapshot', 'waiting-room queue requester should receive snapshot');
    videochat_realtime_lobby_assert((string) ($waitingQueueSnapshot['reason'] ?? '') === 'queued', 'waiting-room queue requester reason mismatch');
    videochat_realtime_lobby_assert((int) ($waitingQueueSnapshot['queue_count'] ?? 0) === 1, 'waiting-room queue requester should see queued state');

    $waitingQueueCancelCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/queue/cancel',
        'room_id' => 'lobby',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_assert((bool) ($waitingQueueCancelCommand['ok'] ?? false), 'lobby/queue/cancel should decode');
    $waitingQueueCancelResult = videochat_lobby_apply_command(
        $waitingLobbyState,
        $waitingPresenceState,
        $waitingConnection,
        $waitingQueueCancelCommand,
        null,
        1_780_400_101_930
    );
    videochat_realtime_lobby_assert((bool) ($waitingQueueCancelResult['ok'] ?? false), 'waiting-room queue cancel should succeed');
    videochat_realtime_lobby_assert((bool) ($waitingQueueCancelResult['changed'] ?? false), 'waiting-room queue cancel should remove queued user');
    $waitingCancelSnapshot = videochat_lobby_snapshot_payload($waitingLobbyState, 'lobby', 'assert_cancel');
    videochat_realtime_lobby_assert((int) ($waitingCancelSnapshot['queue_count'] ?? -1) === 0, 'waiting-room queue cancel should leave zero queued users');

    $waitingQueueJoinAgainResult = videochat_lobby_apply_command(
        $waitingLobbyState,
        $waitingPresenceState,
        $waitingConnection,
        $waitingQueueJoinCommand,
        null,
        1_780_400_101_940
    );
    videochat_realtime_lobby_assert((bool) ($waitingQueueJoinAgainResult['ok'] ?? false), 'waiting-room user should queue again for disconnect cleanup');
    $waitingDisconnectCleanup = videochat_lobby_clear_for_connection(
        $waitingLobbyState,
        $waitingPresenceState,
        $waitingConnection,
        'disconnect',
        null,
        1_780_400_101_950
    );
    videochat_realtime_lobby_assert((bool) ($waitingDisconnectCleanup['cleared'] ?? false), 'waiting-room disconnect should clear queued target-room entry');
    $waitingDisconnectSnapshot = videochat_lobby_snapshot_payload($waitingLobbyState, 'lobby', 'assert_disconnect_cleanup');
    videochat_realtime_lobby_assert((int) ($waitingDisconnectSnapshot['queue_count'] ?? -1) === 0, 'waiting-room disconnect cleanup should leave zero queued users');

    videochat_lobby_ensure_room_state($waitingLobbyState, 'lobby');
    $waitingLobbyState['rooms']['lobby']['admitted_by_user'][50] = [
        'user_id' => 50,
        'display_name' => 'Waiting User',
        'role' => 'user',
        'admitted_unix_ms' => 1_780_400_101_960,
        'admitted_at' => gmdate('c', 1_780_400_101),
        'admitted_by' => [
            'user_id' => 10,
            'display_name' => 'Moderator',
            'role' => 'moderator',
        ],
    ];
    $waitingAdmittedDisconnect = videochat_lobby_clear_for_connection(
        $waitingLobbyState,
        $waitingPresenceState,
        $waitingConnection,
        'disconnect',
        null,
        1_780_400_101_970
    );
    videochat_realtime_lobby_assert(!(bool) ($waitingAdmittedDisconnect['cleared'] ?? true), 'waiting-room disconnect must preserve admitted handoff state');
    $waitingAdmittedSnapshot = videochat_lobby_snapshot_payload($waitingLobbyState, 'lobby', 'assert_admitted_handoff');
    videochat_realtime_lobby_assert((int) ($waitingAdmittedSnapshot['admitted_count'] ?? -1) === 1, 'waiting-room disconnect should preserve admitted handoff');
    $waitingAdmittedCancelResult = videochat_lobby_apply_command(
        $waitingLobbyState,
        $waitingPresenceState,
        $waitingConnection,
        $waitingQueueCancelCommand,
        null,
        1_780_400_101_980
    );
    videochat_realtime_lobby_assert((bool) ($waitingAdmittedCancelResult['ok'] ?? false), 'explicit waiting-room cancel should remove admitted handoff');
    $waitingAdmittedCancelSnapshot = videochat_lobby_snapshot_payload($waitingLobbyState, 'lobby', 'assert_admitted_cancel');
    videochat_realtime_lobby_assert((int) ($waitingAdmittedCancelSnapshot['admitted_count'] ?? -1) === 0, 'explicit waiting-room cancel should leave zero admitted users');

    $nonModeratorAllow = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'target_user_id' => 20,
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_assert((bool) ($nonModeratorAllow['ok'] ?? false), 'lobby/allow command should decode');
    $nonModeratorAllowResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $userB,
        $nonModeratorAllow,
        $sender,
        1_780_400_102_000
    );
    videochat_realtime_lobby_assert(!(bool) ($nonModeratorAllowResult['ok'] ?? true), 'non-moderator lobby/allow must fail');
    videochat_realtime_lobby_assert((string) ($nonModeratorAllowResult['error'] ?? '') === 'forbidden', 'non-moderator lobby/allow error mismatch');

    $moderatorAllowResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $moderator,
        $nonModeratorAllow,
        $sender,
        1_780_400_103_000
    );
    videochat_realtime_lobby_assert((bool) ($moderatorAllowResult['ok'] ?? false), 'moderator lobby/allow should succeed');
    $allowSnapshot = videochat_realtime_lobby_last_frame($frames, 'socket-mod');
    videochat_realtime_lobby_assert((int) ($allowSnapshot['queue_count'] ?? -1) === 0, 'queue should be empty after allow');
    videochat_realtime_lobby_assert((int) ($allowSnapshot['admitted_count'] ?? 0) === 1, 'admitted count should be one after allow');
    videochat_realtime_lobby_assert((int) (($allowSnapshot['admitted'][0]['user_id'] ?? 0)) === 20, 'admitted user mismatch after allow');

    $queueJoinUserB = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $userB,
        $queueJoinCommand,
        $sender,
        1_780_400_104_000
    );
    videochat_realtime_lobby_assert((bool) ($queueJoinUserB['ok'] ?? false), 'user B queue join should succeed');

    $queueJoinUserAAgain = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $userA,
        $queueJoinCommand,
        $sender,
        1_780_400_105_000
    );
    videochat_realtime_lobby_assert((bool) ($queueJoinUserAAgain['ok'] ?? false), 'user A queue rejoin should succeed');

    $allowAllCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow_all',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_assert((bool) ($allowAllCommand['ok'] ?? false), 'lobby/allow_all should decode');

    $allowAllResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $moderator,
        $allowAllCommand,
        $sender,
        1_780_400_106_000
    );
    videochat_realtime_lobby_assert((bool) ($allowAllResult['ok'] ?? false), 'moderator allow_all should succeed');
    videochat_realtime_lobby_assert((bool) ($allowAllResult['changed'] ?? false), 'allow_all should mutate queue when entries exist');
    $allowAllSnapshot = videochat_realtime_lobby_last_frame($frames, 'socket-mod');
    videochat_realtime_lobby_assert((int) ($allowAllSnapshot['queue_count'] ?? -1) === 0, 'queue must be empty after allow_all');
    videochat_realtime_lobby_assert((int) ($allowAllSnapshot['admitted_count'] ?? 0) === 2, 'allow_all should admit two users');

    $removeCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/remove',
        'target_user_id' => 20,
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_assert((bool) ($removeCommand['ok'] ?? false), 'lobby/remove should decode');

    $removeResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $moderator,
        $removeCommand,
        $sender,
        1_780_400_107_000
    );
    videochat_realtime_lobby_assert((bool) ($removeResult['ok'] ?? false), 'moderator remove should succeed');
    $removeSnapshot = videochat_realtime_lobby_last_frame($frames, 'socket-mod');
    videochat_realtime_lobby_assert((int) ($removeSnapshot['admitted_count'] ?? 0) === 1, 'admitted count should drop after remove');
    videochat_realtime_lobby_assert((int) (($removeSnapshot['admitted'][0]['user_id'] ?? 0)) === 30, 'remaining admitted user should be user B');

    $removeMissing = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $moderator,
        $removeCommand,
        $sender,
        1_780_400_108_000
    );
    videochat_realtime_lobby_assert(!(bool) ($removeMissing['ok'] ?? true), 'removing missing user should fail');
    videochat_realtime_lobby_assert((string) ($removeMissing['error'] ?? '') === 'target_not_found', 'missing remove error mismatch');

    $clearWithSecondConnection = videochat_lobby_clear_for_connection(
        $lobbyState,
        $presenceState,
        $userA,
        'disconnect',
        $sender,
        1_780_400_109_000
    );
    videochat_realtime_lobby_assert(!(bool) ($clearWithSecondConnection['cleared'] ?? true), 'disconnect with second active connection should not clear queue/admitted state');

    $disconnectCleanup = videochat_lobby_clear_for_connection(
        $lobbyState,
        $presenceState,
        $userB,
        'disconnect',
        $sender,
        1_780_400_110_000
    );
    videochat_realtime_lobby_assert((bool) ($disconnectCleanup['cleared'] ?? false), 'disconnect cleanup should clear admitted user B');
    $disconnectSnapshot = videochat_realtime_lobby_last_frame($frames, 'socket-mod');
    videochat_realtime_lobby_assert((int) ($disconnectSnapshot['admitted_count'] ?? -1) === 0, 'disconnect cleanup should leave zero admitted users');

    $unsupportedCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'call/offer',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_assert(!(bool) ($unsupportedCommand['ok'] ?? true), 'unsupported lobby type should fail');
    videochat_realtime_lobby_assert((string) ($unsupportedCommand['error'] ?? '') === 'unsupported_type', 'unsupported lobby type error mismatch');

    $invalidTarget = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'target_user_id' => 'abc',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_assert(!(bool) ($invalidTarget['ok'] ?? true), 'invalid target_user_id should fail');
    videochat_realtime_lobby_assert((string) ($invalidTarget['error'] ?? '') === 'invalid_target_user_id', 'invalid target_user_id error mismatch');

    videochat_presence_remove_connection($presenceState, (string) ($moderator['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($userA['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($userASecondConnection['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($userB['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($otherRoomUser['connection_id'] ?? ''), $sender);

    fwrite(STDOUT, "[realtime-lobby-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-lobby-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
