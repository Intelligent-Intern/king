<?php

declare(strict_types=1);

require_once __DIR__ . '/module_realtime_lobby_security.php';
require_once __DIR__ . '/module_realtime_active_call_kick.php';
require_once __DIR__ . '/module_realtime_lobby_persistence.php';

function videochat_realtime_handle_lobby_websocket_command(
    array $lobbyCommand,
    mixed $websocket,
    array &$lobbyState,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase
): ?array {
    if (!(bool) ($lobbyCommand['ok'] ?? false)) {
        return (string) ($lobbyCommand['error'] ?? '') === 'unsupported_type'
            ? null
            : videochat_realtime_secondary_invalid_result($lobbyCommand);
    }

    $lobbyCommandRoomId = videochat_presence_normalize_room_id((string) ($lobbyCommand['room_id'] ?? ''), '');
    if ($lobbyCommandRoomId === '') {
        $lobbyCommandRoomId = videochat_realtime_lobby_room_id_for_connection($presenceConnection);
    }
    if (($lobbySecurityResult = videochat_realtime_reject_unauthorized_lobby_moderation_command(
        $presenceConnection, $lobbyCommand, $lobbyCommandRoomId, $websocket, $openDatabase
    )) !== null) {
        return $lobbySecurityResult;
    }
    if (videochat_realtime_lobby_command_targets_protected_superadmin($lobbyCommand, $openDatabase)) {
        videochat_realtime_send_lobby_command_error(
            $websocket,
            $lobbyCommand,
            ['ok' => false, 'error' => 'protected_superadmin'],
            $lobbyCommandRoomId
        );
        return videochat_realtime_secondary_handled_result();
    }
    videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $lobbyCommandRoomId,
        videochat_realtime_connection_call_id($presenceConnection),
        null,
        videochat_realtime_connection_tenant_id($presenceConnection)
    );

    $deferredLobbyFrames = [];
    $deferredLobbySender = static function (mixed $socket, array $payload) use (&$deferredLobbyFrames): bool {
        $deferredLobbyFrames[] = ['socket' => $socket, 'payload' => $payload];
        return true;
    };

    $lobbyResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $presenceConnection,
        $lobbyCommand,
        $deferredLobbySender
    );
    $lobbyResult = videochat_realtime_lobby_remove_result_for_active_call_target(
        $lobbyResult,
        $lobbyCommand,
        $presenceConnection,
        $lobbyCommandRoomId,
        $openDatabase
    );
    if (!(bool) ($lobbyResult['ok'] ?? false)) {
        videochat_realtime_send_lobby_command_error($websocket, $lobbyCommand, $lobbyResult, (string) ($presenceConnection['room_id'] ?? 'lobby'));
        return videochat_realtime_secondary_handled_result();
    }

    $applyResult = videochat_realtime_apply_successful_lobby_command(
        $lobbyResult,
        $lobbyState,
        $presenceState,
        $presenceConnection,
        $openDatabase
    );
    if (!(bool) ($applyResult['ok'] ?? false)) {
        videochat_realtime_send_lobby_command_error($websocket, $lobbyCommand, $applyResult, $lobbyCommandRoomId);
        return videochat_realtime_secondary_handled_result();
    }

    foreach ($deferredLobbyFrames as $frame) {
        if (is_array($frame)) {
            videochat_presence_send_frame($frame['socket'] ?? null, is_array($frame['payload'] ?? null) ? $frame['payload'] : []);
        }
    }
    return videochat_realtime_secondary_handled_result();
}

function videochat_realtime_send_lobby_command_error(mixed $websocket, array $command, array $result, string $roomId): void
{
    videochat_presence_send_frame(
        $websocket,
        [
            'type' => 'system/error',
            'code' => 'lobby_command_failed',
            'message' => 'Could not apply lobby command.',
            'details' => [
                'error' => (string) ($result['error'] ?? 'unknown'),
                'type' => (string) ($command['type'] ?? ''),
                'target_user_id' => (int) ($command['target_user_id'] ?? 0),
                'room_id' => $roomId,
            ],
            'time' => gmdate('c'),
        ]
    );
}
