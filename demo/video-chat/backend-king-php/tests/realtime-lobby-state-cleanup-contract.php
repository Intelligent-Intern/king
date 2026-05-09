<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';

function videochat_realtime_lobby_state_cleanup_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-lobby-state-cleanup-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_realtime_lobby_state_cleanup_last_frame(array $frames, string $socket): array
{
    $socketFrames = $frames[$socket] ?? [];
    if (!is_array($socketFrames) || $socketFrames === []) {
        return [];
    }

    $last = end($socketFrames);
    return is_array($last) ? $last : [];
}

function videochat_realtime_lobby_state_cleanup_connection(
    int $userId,
    string $displayName,
    string $connectionId,
    string $socket,
    string $roomId,
    string $callId,
    string $callRole = 'participant',
    bool $canModerate = false
): array {
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => $canModerate ? 'moderator' : 'user',
        ],
        'sess-' . $connectionId,
        $connectionId,
        $socket,
        $roomId
    );
    $connection['active_call_id'] = $callId;
    $connection['requested_call_id'] = $callId;
    $connection['requested_room_id'] = $roomId;
    $connection['call_role'] = $callRole;
    $connection['can_moderate_call'] = $canModerate;

    return $connection;
}

function videochat_realtime_lobby_state_cleanup_waiting_connection(
    int $userId,
    string $displayName,
    string $connectionId,
    string $socket,
    string $targetRoomId,
    string $callId
): array {
    $connection = videochat_realtime_lobby_state_cleanup_connection(
        $userId,
        $displayName,
        $connectionId,
        $socket,
        'waiting-room',
        $callId
    );
    $connection['pending_room_id'] = $targetRoomId;
    $connection['requested_room_id'] = $targetRoomId;

    return $connection;
}

try {
    $roomId = 'room-lobby-state-cleanup';
    $callId = 'call-lobby-state-cleanup';
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

    $moderator = videochat_realtime_lobby_state_cleanup_connection(
        10,
        'Lobby Moderator',
        'conn-moderator',
        'socket-moderator',
        $roomId,
        $callId,
        'owner',
        true
    );
    $moderatorJoin = videochat_presence_join_room($presenceState, $moderator, $roomId, $sender);
    $moderator = (array) ($moderatorJoin['connection'] ?? $moderator);

    $admittedUser = videochat_realtime_lobby_state_cleanup_waiting_connection(
        20,
        'Admitted User',
        'conn-admitted',
        'socket-admitted',
        $roomId,
        $callId
    );
    $admittedJoin = videochat_presence_join_room($presenceState, $admittedUser, 'waiting-room', $sender);
    $admittedUser = (array) ($admittedJoin['connection'] ?? $admittedUser);
    $admittedUser['pending_room_id'] = $roomId;
    $presenceState['connections']['conn-admitted']['pending_room_id'] = $roomId;
    $presenceState['connections']['conn-admitted']['requested_room_id'] = $roomId;

    $queueJoinCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/queue/join',
        'room_id' => $roomId,
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_state_cleanup_assert((bool) ($queueJoinCommand['ok'] ?? false), 'queue/join command should decode');

    $queueJoinResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $admittedUser,
        $queueJoinCommand,
        $sender,
        1_780_700_000_000
    );
    videochat_realtime_lobby_state_cleanup_assert((bool) ($queueJoinResult['ok'] ?? false), 'waiting user should enter lobby queue');
    videochat_realtime_lobby_state_cleanup_assert((bool) ($queueJoinResult['changed'] ?? false), 'first queue join should mutate lobby state');
    $queuedModeratorFrame = videochat_realtime_lobby_state_cleanup_last_frame($frames, 'socket-moderator');
    $queuedWaitingFrame = videochat_realtime_lobby_state_cleanup_last_frame($frames, 'socket-admitted');
    videochat_realtime_lobby_state_cleanup_assert((int) ($queuedModeratorFrame['queue_count'] ?? 0) === 1, 'moderator should see one queued participant');
    videochat_realtime_lobby_state_cleanup_assert((int) ($queuedWaitingFrame['queue_count'] ?? 0) === 1, 'waiting participant should see own queued status');

    $allowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'room_id' => $roomId,
        'target_user_id' => 20,
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_state_cleanup_assert((bool) ($allowCommand['ok'] ?? false), 'allow command should decode');

    $frames = [];
    $allowResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $moderator,
        $allowCommand,
        $sender,
        1_780_700_001_000
    );
    videochat_realtime_lobby_state_cleanup_assert((bool) ($allowResult['ok'] ?? false), 'moderator should admit queued participant');
    videochat_realtime_lobby_state_cleanup_assert((string) ($allowResult['state'] ?? '') === 'allowed', 'allow result state mismatch');
    $allowedModeratorFrame = videochat_realtime_lobby_state_cleanup_last_frame($frames, 'socket-moderator');
    videochat_realtime_lobby_state_cleanup_assert((int) ($allowedModeratorFrame['queue_count'] ?? -1) === 0, 'admitted participant should leave lobby queue');
    videochat_realtime_lobby_state_cleanup_assert((int) ($allowedModeratorFrame['admitted_count'] ?? -1) === 1, 'admitted handoff should be tracked');

    videochat_lobby_send_snapshot_to_connection(
        $lobbyState,
        $admittedUser,
        'admission_handoff',
        $sender,
        1_780_700_001_100
    );
    $admittedUserFrame = videochat_realtime_lobby_state_cleanup_last_frame($frames, 'socket-admitted');
    videochat_realtime_lobby_state_cleanup_assert((int) ($admittedUserFrame['queue_count'] ?? -1) === 0, 'admitted participant should not see a queued self-entry');
    videochat_realtime_lobby_state_cleanup_assert((int) ($admittedUserFrame['admitted_count'] ?? -1) === 1, 'admitted participant should receive admitted handoff');
    videochat_realtime_lobby_state_cleanup_assert(videochat_lobby_is_user_admitted_for_room($lobbyState, $roomId, 20), 'admitted participant should be marked admitted');

    $alreadyAdmittedQueue = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $admittedUser,
        $queueJoinCommand,
        $sender,
        1_780_700_001_200
    );
    videochat_realtime_lobby_state_cleanup_assert((bool) ($alreadyAdmittedQueue['ok'] ?? false), 'admitted participant queue retry should succeed idempotently');
    videochat_realtime_lobby_state_cleanup_assert((string) ($alreadyAdmittedQueue['state'] ?? '') === 'already_admitted', 'admitted queue retry state mismatch');
    $alreadyAdmittedSnapshot = videochat_lobby_snapshot_payload($lobbyState, $roomId, 'assert_already_admitted');
    videochat_realtime_lobby_state_cleanup_assert((int) ($alreadyAdmittedSnapshot['queue_count'] ?? -1) === 0, 'already-admitted retry must not recreate queue row');
    videochat_realtime_lobby_state_cleanup_assert((int) ($alreadyAdmittedSnapshot['admitted_count'] ?? -1) === 1, 'already-admitted retry must preserve one handoff');

    $abortUser = videochat_realtime_lobby_state_cleanup_waiting_connection(
        30,
        'Abort User',
        'conn-abort',
        'socket-abort',
        $roomId,
        $callId
    );
    $abortJoin = videochat_presence_join_room($presenceState, $abortUser, 'waiting-room', $sender);
    $abortUser = (array) ($abortJoin['connection'] ?? $abortUser);
    $abortUser['pending_room_id'] = $roomId;
    $presenceState['connections']['conn-abort']['pending_room_id'] = $roomId;
    $presenceState['connections']['conn-abort']['requested_room_id'] = $roomId;

    $abortQueue = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $abortUser,
        $queueJoinCommand,
        $sender,
        1_780_700_002_000
    );
    videochat_realtime_lobby_state_cleanup_assert((bool) ($abortQueue['ok'] ?? false), 'aborting participant should first queue');
    $abortQueuedSnapshot = videochat_lobby_snapshot_payload($lobbyState, $roomId, 'assert_abort_queued');
    videochat_realtime_lobby_state_cleanup_assert((int) ($abortQueuedSnapshot['queue_count'] ?? -1) === 1, 'abort setup should have one queued row');

    $cancelCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/queue/cancel',
        'room_id' => $roomId,
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_state_cleanup_assert((bool) ($cancelCommand['ok'] ?? false), 'queue/cancel command should decode');

    $frames = [];
    $cancelResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $abortUser,
        $cancelCommand,
        $sender,
        1_780_700_002_100
    );
    videochat_realtime_lobby_state_cleanup_assert((bool) ($cancelResult['ok'] ?? false), 'queue cancel should succeed');
    videochat_realtime_lobby_state_cleanup_assert((bool) ($cancelResult['changed'] ?? false), 'queue cancel should remove participant');
    $cancelModeratorFrame = videochat_realtime_lobby_state_cleanup_last_frame($frames, 'socket-moderator');
    videochat_realtime_lobby_state_cleanup_assert((int) ($cancelModeratorFrame['queue_count'] ?? -1) === 0, 'aborted join should leave no queued row');
    videochat_realtime_lobby_state_cleanup_assert((int) ($cancelModeratorFrame['admitted_count'] ?? -1) === 1, 'aborted join should not remove unrelated admitted handoff');

    $rejectedUser = videochat_realtime_lobby_state_cleanup_waiting_connection(
        40,
        'Rejected User',
        'conn-rejected',
        'socket-rejected',
        $roomId,
        $callId
    );
    $rejectedJoin = videochat_presence_join_room($presenceState, $rejectedUser, 'waiting-room', $sender);
    $rejectedUser = (array) ($rejectedJoin['connection'] ?? $rejectedUser);
    $rejectedUser['pending_room_id'] = $roomId;
    $presenceState['connections']['conn-rejected']['pending_room_id'] = $roomId;
    $presenceState['connections']['conn-rejected']['requested_room_id'] = $roomId;

    $rejectedQueue = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $rejectedUser,
        $queueJoinCommand,
        $sender,
        1_780_700_003_000
    );
    videochat_realtime_lobby_state_cleanup_assert((bool) ($rejectedQueue['ok'] ?? false), 'rejected participant should first queue');
    $rejectCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/reject',
        'room_id' => $roomId,
        'target_user_id' => 40,
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_state_cleanup_assert((bool) ($rejectCommand['ok'] ?? false), 'reject command should decode');

    $rejectResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $moderator,
        $rejectCommand,
        $sender,
        1_780_700_003_100
    );
    videochat_realtime_lobby_state_cleanup_assert((bool) ($rejectResult['ok'] ?? false), 'moderator should reject queued participant');
    videochat_realtime_lobby_state_cleanup_assert((string) ($rejectResult['action'] ?? '') === 'lobby/remove', 'reject should normalize to remove action');
    $rejectSnapshot = videochat_lobby_snapshot_payload($lobbyState, $roomId, 'assert_rejected');
    videochat_realtime_lobby_state_cleanup_assert((int) ($rejectSnapshot['queue_count'] ?? -1) === 0, 'rejected participant should leave queue');
    videochat_realtime_lobby_state_cleanup_assert(!videochat_lobby_is_user_admitted_for_room($lobbyState, $roomId, 40), 'rejected participant must not be admitted');

    fwrite(STDOUT, "[realtime-lobby-state-cleanup-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-lobby-state-cleanup-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
