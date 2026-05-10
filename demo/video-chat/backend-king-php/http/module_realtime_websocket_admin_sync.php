<?php

declare(strict_types=1);

function videochat_realtime_handle_admin_sync_websocket_command(
    array $adminSyncCommand,
    mixed $websocket,
    array &$presenceState,
    array $presenceConnection
): ?array {
    if (!(bool) ($adminSyncCommand['ok'] ?? false)) {
        return (string) ($adminSyncCommand['error'] ?? '') === 'unsupported_type'
            ? null
            : videochat_realtime_secondary_invalid_result($adminSyncCommand);
    }

    $adminSyncResult = videochat_admin_sync_publish($presenceState, $presenceConnection, $adminSyncCommand);
    if (!(bool) ($adminSyncResult['ok'] ?? false)) {
        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => 'admin_sync_publish_failed',
                'message' => 'Could not publish admin sync event.',
                'details' => [
                    'error' => (string) ($adminSyncResult['error'] ?? 'unknown'),
                    'topic' => (string) ($adminSyncCommand['topic'] ?? 'all'),
                    'reason' => (string) ($adminSyncCommand['reason'] ?? 'updated'),
                ],
                'time' => gmdate('c'),
            ]
        );
    }

    return videochat_realtime_secondary_handled_result();
}
