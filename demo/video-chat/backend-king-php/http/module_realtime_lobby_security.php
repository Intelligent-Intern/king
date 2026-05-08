<?php

declare(strict_types=1);

function videochat_realtime_lobby_command_requires_moderation(array $lobbyCommand): bool
{
    return in_array((string) ($lobbyCommand['type'] ?? ''), ['lobby/allow', 'lobby/remove', 'lobby/allow_all'], true);
}

function videochat_realtime_lobby_server_role_for_user(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        return 'user';
    }

    try {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT roles.slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.id = :user_id
LIMIT 1
SQL
        );
        $query->execute([':user_id' => $userId]);
        $role = $query->fetchColumn();
    } catch (Throwable) {
        return 'user';
    }

    return videochat_normalize_role_slug(is_string($role) ? $role : 'user');
}

/**
 * @return array{
 *   ok: bool,
 *   error: string,
 *   room_id: string,
 *   call_id: string,
 *   role: string,
 *   call_role: string
 * }
 */
function videochat_realtime_authorize_lobby_moderation_command(
    array $presenceConnection,
    array $lobbyCommand,
    string $roomId,
    callable $openDatabase
): array {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    $userId = (int) ($presenceConnection['user_id'] ?? 0);
    if ($normalizedRoomId === '' || $userId <= 0) {
        return [
            'ok' => false,
            'error' => 'invalid_lobby_authority_context',
            'room_id' => $normalizedRoomId,
            'call_id' => '',
            'role' => 'user',
            'call_role' => 'participant',
        ];
    }

    try {
        $pdo = $openDatabase();
        $serverRole = videochat_realtime_lobby_server_role_for_user($pdo, $userId);
        $requestedCallId = videochat_realtime_connection_call_id($presenceConnection);
        $tenantId = is_numeric($presenceConnection['tenant_id'] ?? null) ? (int) $presenceConnection['tenant_id'] : null;
        $context = videochat_realtime_call_role_context_for_room_user(
            $pdo,
            $normalizedRoomId,
            $userId,
            $requestedCallId,
            $serverRole,
            $tenantId
        );
    } catch (Throwable) {
        return [
            'ok' => false,
            'error' => 'lobby_authority_unavailable',
            'room_id' => $normalizedRoomId,
            'call_id' => '',
            'role' => 'user',
            'call_role' => 'participant',
        ];
    }

    $callId = videochat_realtime_normalize_call_id((string) ($context['call_id'] ?? ''), '');
    $callRole = videochat_normalize_call_participant_role((string) ($context['call_role'] ?? 'participant'));
    if ($callId === '' || !(bool) ($context['can_moderate'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'forbidden',
            'room_id' => $normalizedRoomId,
            'call_id' => $callId,
            'role' => $serverRole,
            'call_role' => $callRole,
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'room_id' => $normalizedRoomId,
        'call_id' => $callId,
        'role' => $serverRole,
        'call_role' => $callRole,
    ];
}

function videochat_realtime_reject_unauthorized_lobby_moderation_command(
    array $presenceConnection,
    array $lobbyCommand,
    string $roomId,
    mixed $websocket,
    callable $openDatabase
): ?array {
    if (!videochat_realtime_lobby_command_requires_moderation($lobbyCommand)) {
        return null;
    }

    $lobbyAuthority = videochat_realtime_authorize_lobby_moderation_command(
        $presenceConnection,
        $lobbyCommand,
        $roomId,
        $openDatabase
    );
    if ((bool) ($lobbyAuthority['ok'] ?? false)) {
        return null;
    }

    videochat_presence_send_frame(
        $websocket,
        [
            'type' => 'system/error',
            'code' => 'lobby_command_failed',
            'message' => 'Could not apply lobby command.',
            'details' => [
                'error' => (string) ($lobbyAuthority['error'] ?? 'forbidden'),
                'type' => (string) ($lobbyCommand['type'] ?? ''),
                'target_user_id' => (int) ($lobbyCommand['target_user_id'] ?? 0),
                'room_id' => $roomId,
            ],
            'time' => gmdate('c'),
        ]
    );

    return videochat_realtime_secondary_handled_result();
}
