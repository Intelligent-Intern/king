<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth_rbac.php';
require_once __DIR__ . '/../domain/audit/audit_lobby_events.php';
require_once __DIR__ . '/../domain/calls/call_management.php';

function videochat_realtime_lobby_command_requires_moderation(array $lobbyCommand): bool
{
    return in_array((string) ($lobbyCommand['type'] ?? ''), ['lobby/allow', 'lobby/remove', 'lobby/reject', 'lobby/kick', 'lobby/allow_all'], true);
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
 *   call_role: string,
 *   effective_call_role: string
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
            'effective_call_role' => 'participant',
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
        if ($requestedCallId !== '' && videochat_realtime_normalize_call_id((string) ($context['call_id'] ?? ''), '') === '') {
            $roomContext = videochat_realtime_call_role_context_for_room_user(
                $pdo,
                $normalizedRoomId,
                $userId,
                '',
                $serverRole,
                $tenantId
            );
            if (videochat_realtime_normalize_call_id((string) ($roomContext['call_id'] ?? ''), '') !== '') {
                $context = $roomContext;
            }
        }
    } catch (Throwable) {
        return [
            'ok' => false,
            'error' => 'lobby_authority_unavailable',
            'room_id' => $normalizedRoomId,
            'call_id' => '',
            'role' => 'user',
            'call_role' => 'participant',
            'effective_call_role' => 'participant',
        ];
    }

    $callId = videochat_realtime_normalize_call_id((string) ($context['call_id'] ?? ''), '');
    $callRole = videochat_normalize_call_participant_role((string) ($context['call_role'] ?? 'participant'));
    $effectiveCallRole = videochat_normalize_call_participant_role(
        (string) ($context['effective_call_role'] ?? $callRole)
    );
    $contextRoleActive = $callRole === 'owner'
        || $serverRole === 'admin'
        || videochat_call_invite_state_allows_scoped_role($context['invite_state'] ?? 'invited');
    if ($callId === '' || !(bool) ($context['can_moderate'] ?? false) || !$contextRoleActive) {
        $requestedCallId = videochat_realtime_connection_call_id($presenceConnection);
        $call = videochat_fetch_call_for_update($pdo, $requestedCallId, $tenantId);
        if (
            is_array($call)
            && videochat_presence_normalize_room_id((string) ($call['room_id'] ?? ''), '') === $normalizedRoomId
            && videochat_can_administer_call(
                $pdo,
                (string) ($call['id'] ?? ''),
                $serverRole,
                $userId,
                (int) ($call['owner_user_id'] ?? 0),
                $tenantId
            )
        ) {
            $isOwner = $userId === (int) ($call['owner_user_id'] ?? 0);

            return [
                'ok' => true,
                'error' => '',
                'room_id' => $normalizedRoomId,
                'call_id' => (string) ($call['id'] ?? ''),
                'role' => $serverRole,
                'call_role' => $isOwner ? 'owner' : 'moderator',
                'effective_call_role' => $isOwner ? 'owner' : 'moderator',
            ];
        }

        return [
            'ok' => false,
            'error' => 'forbidden',
            'room_id' => $normalizedRoomId,
            'call_id' => $callId,
            'role' => $serverRole,
            'call_role' => $callRole,
            'effective_call_role' => $effectiveCallRole,
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'room_id' => $normalizedRoomId,
        'call_id' => $callId,
        'role' => $serverRole,
        'call_role' => $callRole,
        'effective_call_role' => $effectiveCallRole,
    ];
}

function videochat_realtime_reject_unauthorized_lobby_moderation_command(
    array &$presenceConnection,
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
        $presenceConnection['role'] = (string) ($lobbyAuthority['role'] ?? ($presenceConnection['role'] ?? 'user'));
        $presenceConnection['raw_role'] = strtolower((string) ($presenceConnection['role'] ?? 'user'));
        $presenceConnection['active_call_id'] = (string) ($lobbyAuthority['call_id'] ?? ($presenceConnection['active_call_id'] ?? ''));
        $presenceConnection['call_role'] = videochat_normalize_call_participant_role((string) ($lobbyAuthority['call_role'] ?? 'participant'));
        $presenceConnection['effective_call_role'] = videochat_normalize_call_participant_role(
            (string) ($lobbyAuthority['effective_call_role'] ?? $presenceConnection['call_role'])
        );
        $presenceConnection['can_moderate_call'] = true;
        return null;
    }

    try {
        $callId = videochat_realtime_normalize_call_id((string) ($lobbyAuthority['call_id'] ?? ''), '');
        if ($callId === '') {
            $callId = videochat_realtime_connection_call_id($presenceConnection);
        }
        videochat_audit_record_call_lobby_moderation_denied(
            $openDatabase(),
            videochat_realtime_connection_tenant_id($presenceConnection),
            $callId,
            (int) ($presenceConnection['user_id'] ?? 0),
            (int) ($lobbyCommand['target_user_id'] ?? 0),
            [
                'room_id' => $roomId,
                'session_id' => (string) ($presenceConnection['session_id'] ?? ''),
                'actor_role' => (string) ($lobbyAuthority['role'] ?? ($presenceConnection['role'] ?? 'user')),
                'actor_call_role' => (string) ($lobbyAuthority['effective_call_role'] ?? ($lobbyAuthority['call_role'] ?? 'participant')),
                'attempted_action' => (string) ($lobbyCommand['type'] ?? ''),
                'denial_reason' => (string) ($lobbyAuthority['error'] ?? 'forbidden'),
            ]
        );
    } catch (Throwable) {
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
