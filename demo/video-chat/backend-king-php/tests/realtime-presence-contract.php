<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';

function videochat_realtime_presence_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-presence-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_realtime_presence_last_frame(array $frames, string $socket): array
{
    $socketFrames = $frames[$socket] ?? [];
    if (!is_array($socketFrames) || $socketFrames === []) {
        return [];
    }

    $last = end($socketFrames);
    return is_array($last) ? $last : [];
}

try {
    $state = videochat_presence_state_init();
    videochat_realtime_presence_assert($state['rooms'] === [], 'initial rooms map must be empty');
    videochat_realtime_presence_assert($state['connections'] === [], 'initial connections map must be empty');

    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        if (!isset($frames[$key]) || !is_array($frames[$key])) {
            $frames[$key] = [];
        }
        $frames[$key][] = $payload;
        return true;
    };

    $adminConnection = videochat_presence_connection_descriptor(
        [
            'id' => 10,
            'display_name' => 'Admin User',
            'role' => 'admin',
        ],
        'sess-admin',
        'conn-admin',
        'socket-admin',
        'lobby',
        1_780_000_000
    );

    $joinAdmin = videochat_presence_join_room($state, $adminConnection, 'lobby', $sender);
    $adminConnection = (array) ($joinAdmin['connection'] ?? []);
    videochat_realtime_presence_assert((bool) ($joinAdmin['changed'] ?? false), 'first join should be marked as changed');
    videochat_realtime_presence_assert((string) ($joinAdmin['previous_room_id'] ?? '') === '', 'first join previous_room_id must be empty');
    videochat_realtime_presence_assert(count(videochat_presence_room_participants($state, 'lobby')) === 1, 'lobby should contain exactly one participant after first join');

    $adminSnapshot = videochat_realtime_presence_last_frame($frames, 'socket-admin');
    videochat_realtime_presence_assert((string) ($adminSnapshot['type'] ?? '') === 'room/snapshot', 'admin should receive initial room snapshot');
    videochat_realtime_presence_assert((int) ($adminSnapshot['participant_count'] ?? 0) === 1, 'initial snapshot participant_count mismatch');
    videochat_realtime_presence_assert((string) ($adminSnapshot['reason'] ?? '') === 'joined', 'initial snapshot reason mismatch');

    $userConnection = videochat_presence_connection_descriptor(
        [
            'id' => 20,
            'display_name' => 'Standard User',
            'role' => 'user',
        ],
        'sess-user',
        'conn-user',
        'socket-user',
        'lobby',
        1_780_000_120
    );

    $joinUser = videochat_presence_join_room($state, $userConnection, 'lobby', $sender);
    $userConnection = (array) ($joinUser['connection'] ?? []);
    videochat_realtime_presence_assert((bool) ($joinUser['changed'] ?? false), 'second user first join should be marked as changed');

    $participantsAfterUserJoin = videochat_presence_room_participants($state, 'lobby');
    videochat_realtime_presence_assert(count($participantsAfterUserJoin) === 2, 'lobby should contain two participants after second join');
    videochat_realtime_presence_assert((string) (($participantsAfterUserJoin[0]['user'] ?? [])['role'] ?? '') === 'admin', 'admin should be sorted before user in participants snapshot');

    $userSnapshot = videochat_realtime_presence_last_frame($frames, 'socket-user');
    videochat_realtime_presence_assert((string) ($userSnapshot['type'] ?? '') === 'room/snapshot', 'user should receive room snapshot after join');
    videochat_realtime_presence_assert((int) ($userSnapshot['participant_count'] ?? 0) === 2, 'user snapshot participant_count mismatch');

    $adminJoinEvent = videochat_realtime_presence_last_frame($frames, 'socket-admin');
    videochat_realtime_presence_assert((string) ($adminJoinEvent['type'] ?? '') === 'room/joined', 'admin should receive joined event for second user');
    videochat_realtime_presence_assert((string) (($adminJoinEvent['participant'] ?? [])['connection_id'] ?? '') === 'conn-user', 'joined event participant connection mismatch');

    videochat_presence_send_room_snapshot($state, $adminConnection, 'requested', $sender);
    $requestedSnapshot = videochat_realtime_presence_last_frame($frames, 'socket-admin');
    videochat_realtime_presence_assert((string) ($requestedSnapshot['type'] ?? '') === 'room/snapshot', 'requested snapshot must have room/snapshot type');
    videochat_realtime_presence_assert((string) ($requestedSnapshot['reason'] ?? '') === 'requested', 'requested snapshot reason mismatch');

    $removeUser = videochat_presence_remove_connection($state, 'conn-user', $sender);
    videochat_realtime_presence_assert(is_array($removeUser), 'remove connection should return removed connection state');
    videochat_realtime_presence_assert(count(videochat_presence_room_participants($state, 'lobby')) === 1, 'lobby should contain one participant after user leave');

    $adminLeaveEvent = videochat_realtime_presence_last_frame($frames, 'socket-admin');
    videochat_realtime_presence_assert((string) ($adminLeaveEvent['type'] ?? '') === 'room/left', 'admin should receive room left event after user removal');
    videochat_realtime_presence_assert((string) (($adminLeaveEvent['participant'] ?? [])['connection_id'] ?? '') === 'conn-user', 'left event participant connection mismatch');
    videochat_realtime_presence_assert((int) ($adminLeaveEvent['participant_count'] ?? -1) === 1, 'left event participant_count mismatch');

    $reconnectUser = videochat_presence_connection_descriptor(
        [
            'id' => 20,
            'display_name' => 'Standard User',
            'role' => 'user',
        ],
        'sess-user',
        'conn-user-2',
        'socket-user-2',
        'lobby',
        1_780_000_300
    );

    $joinReconnectUser = videochat_presence_join_room($state, $reconnectUser, 'lobby', $sender);
    videochat_realtime_presence_assert((bool) ($joinReconnectUser['changed'] ?? false), 'reconnected user should be attached as changed join');

    $reconnectSnapshot = videochat_realtime_presence_last_frame($frames, 'socket-user-2');
    videochat_realtime_presence_assert((string) ($reconnectSnapshot['type'] ?? '') === 'room/snapshot', 'reconnected user should receive room snapshot');
    videochat_realtime_presence_assert((int) ($reconnectSnapshot['participant_count'] ?? 0) === 2, 'reconnected user snapshot participant_count mismatch');

    $adminJoinAfterReconnect = videochat_realtime_presence_last_frame($frames, 'socket-admin');
    videochat_realtime_presence_assert((string) ($adminJoinAfterReconnect['type'] ?? '') === 'room/joined', 'admin should receive joined event on reconnect');
    videochat_realtime_presence_assert((string) (($adminJoinAfterReconnect['participant'] ?? [])['connection_id'] ?? '') === 'conn-user-2', 'reconnect joined event participant mismatch');

    $moveReconnectUser = videochat_presence_join_room($state, $joinReconnectUser['connection'] ?? [], 'ops-room', $sender);
    $movedUserConnection = (array) ($moveReconnectUser['connection'] ?? []);
    videochat_realtime_presence_assert((bool) ($moveReconnectUser['changed'] ?? false), 'moving a participant to a new room should be marked as changed');
    videochat_realtime_presence_assert((string) ($moveReconnectUser['previous_room_id'] ?? '') === 'lobby', 'room move previous_room_id mismatch');

    $participantsLobbyAfterMove = videochat_presence_room_participants($state, 'lobby');
    videochat_realtime_presence_assert(count($participantsLobbyAfterMove) === 1, 'lobby should not keep a phantom participant after room move');
    videochat_realtime_presence_assert((string) (($participantsLobbyAfterMove[0]['connection_id'] ?? '')) === 'conn-admin', 'lobby should keep only admin after room move');

    $participantsOpsAfterMove = videochat_presence_room_participants($state, 'ops-room');
    videochat_realtime_presence_assert(count($participantsOpsAfterMove) === 1, 'target room should contain moved participant');
    videochat_realtime_presence_assert((string) (($participantsOpsAfterMove[0]['connection_id'] ?? '')) === 'conn-user-2', 'target room participant mismatch after move');

    $movedUserSnapshot = videochat_realtime_presence_last_frame($frames, 'socket-user-2');
    videochat_realtime_presence_assert((string) ($movedUserSnapshot['type'] ?? '') === 'room/snapshot', 'moved participant should receive fresh room snapshot');
    videochat_realtime_presence_assert((string) ($movedUserSnapshot['room_id'] ?? '') === 'ops-room', 'moved participant snapshot room mismatch');
    videochat_realtime_presence_assert((string) ($movedUserSnapshot['reason'] ?? '') === 'joined', 'moved participant snapshot reason mismatch');

    $adminLeftAfterMove = videochat_realtime_presence_last_frame($frames, 'socket-admin');
    videochat_realtime_presence_assert((string) ($adminLeftAfterMove['type'] ?? '') === 'room/left', 'remaining lobby participant should receive room/left event on room move');
    videochat_realtime_presence_assert((string) (($adminLeftAfterMove['participant'] ?? [])['connection_id'] ?? '') === 'conn-user-2', 'room/left event participant mismatch on room move');

    $reconnectAgainUser = videochat_presence_connection_descriptor(
        [
            'id' => 20,
            'display_name' => 'Standard User',
            'role' => 'user',
        ],
        'sess-user',
        'conn-user-3',
        'socket-user-3',
        'ops-room',
        1_780_000_360
    );

    $joinReconnectAgainUser = videochat_presence_join_room($state, $reconnectAgainUser, 'ops-room', $sender);
    videochat_realtime_presence_assert((bool) ($joinReconnectAgainUser['changed'] ?? false), 'reconnect in same room should register as changed on fresh connection');

    $reconnectAgainSnapshot = videochat_realtime_presence_last_frame($frames, 'socket-user-3');
    videochat_realtime_presence_assert((string) ($reconnectAgainSnapshot['type'] ?? '') === 'room/snapshot', 'reconnect snapshot should be delivered');
    videochat_realtime_presence_assert((string) ($reconnectAgainSnapshot['room_id'] ?? '') === 'ops-room', 'reconnect snapshot room mismatch');
    videochat_realtime_presence_assert((int) ($reconnectAgainSnapshot['participant_count'] ?? 0) === 2, 'reconnect snapshot should include active room participants only');

    videochat_presence_remove_connection($state, 'conn-user-2', $sender);
    $participantsOpsAfterReconnectCleanup = videochat_presence_room_participants($state, 'ops-room');
    videochat_realtime_presence_assert(count($participantsOpsAfterReconnectCleanup) === 1, 'cleanup should leave only active reconnect participant in ops-room');
    videochat_realtime_presence_assert((string) (($participantsOpsAfterReconnectCleanup[0]['connection_id'] ?? '')) === 'conn-user-3', 'ops-room cleanup participant mismatch');

    $decodedJoin = videochat_presence_decode_client_frame(json_encode([
        'type' => 'room/join',
        'room_id' => 'lobby',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_presence_assert((bool) ($decodedJoin['ok'] ?? false), 'decode join frame should pass');
    videochat_realtime_presence_assert((string) ($decodedJoin['type'] ?? '') === 'room/join', 'decode join frame type mismatch');

    $decodedInvalidRoom = videochat_presence_decode_client_frame(json_encode([
        'type' => 'room/join',
        'room_id' => '../lobby',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_presence_assert(!(bool) ($decodedInvalidRoom['ok'] ?? true), 'decode invalid room frame should fail');
    videochat_realtime_presence_assert((string) ($decodedInvalidRoom['error'] ?? '') === 'invalid_room_id', 'decode invalid room error mismatch');

    $decodedInvalidJson = videochat_presence_decode_client_frame('{invalid json');
    videochat_realtime_presence_assert(!(bool) ($decodedInvalidJson['ok'] ?? true), 'decode invalid JSON frame should fail');
    videochat_realtime_presence_assert((string) ($decodedInvalidJson['error'] ?? '') === 'invalid_json', 'decode invalid JSON error mismatch');

    videochat_presence_remove_connection($state, 'conn-user-3', $sender);
    videochat_presence_remove_connection($state, 'conn-admin', $sender);
    videochat_realtime_presence_assert($state['connections'] === [], 'state connections should be empty after full detach');

    fwrite(STDOUT, "[realtime-presence-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-presence-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
