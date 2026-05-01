<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/error_envelope.php';

function videochat_presence_state_init(): array
{
    return [
        'rooms' => [],
        'connections' => [],
    ];
}

function videochat_presence_role_rank(string $role): int
{
    return match (videochat_normalize_role_slug($role)) {
        'admin' => 0,
        default => 1,
    };
}

function videochat_presence_is_valid_room_id(string $roomId): bool
{
    $trimmedRoomId = trim($roomId);
    if ($trimmedRoomId === '') {
        return false;
    }

    return strlen($trimmedRoomId) <= 120
        && preg_match('/^[A-Za-z0-9._-]+$/', $trimmedRoomId) === 1;
}

function videochat_presence_normalize_room_id(?string $roomId, string $fallback = 'lobby'): string
{
    $candidate = strtolower(trim((string) $roomId));
    if (!videochat_presence_is_valid_room_id($candidate)) {
        $fallbackCandidate = strtolower(trim($fallback));
        if ($fallbackCandidate === '') {
            return '';
        }
        if (videochat_presence_is_valid_room_id($fallbackCandidate)) {
            return $fallbackCandidate;
        }

        return 'lobby';
    }

    return $candidate;
}

/**
 * @return array{
 *   connection_id: string,
 *   session_id: string,
 *   socket: mixed,
 *   room_id: string,
 *   user_id: int,
 *   display_name: string,
 *   role: string,
 *   raw_role: string,
 *   active_call_id: string,
 *   call_role: string,
 *   can_moderate_call: bool,
 *   connected_at: string
 * }
 */
function videochat_presence_connection_descriptor(
    array $authUser,
    string $sessionId,
    string $connectionId,
    mixed $socket,
    string $roomId,
    ?int $connectedAtUnix = null
): array {
    $effectiveConnectionId = trim($connectionId);
    if ($effectiveConnectionId === '') {
        $effectiveConnectionId = 'ws_' . hash('sha1', uniqid((string) mt_rand(), true) . microtime(true));
    }

    $effectiveSessionId = trim($sessionId);
    $effectiveConnectedAt = is_int($connectedAtUnix) && $connectedAtUnix > 0
        ? gmdate('c', $connectedAtUnix)
        : gmdate('c');

    return [
        'connection_id' => $effectiveConnectionId,
        'session_id' => $effectiveSessionId,
        'socket' => $socket,
        'room_id' => videochat_presence_normalize_room_id($roomId),
        'user_id' => (int) ($authUser['id'] ?? 0),
        'display_name' => trim((string) ($authUser['display_name'] ?? '')),
        'role' => videochat_normalize_role_slug((string) ($authUser['role'] ?? '')),
        'raw_role' => strtolower(trim((string) ($authUser['role'] ?? ''))),
        'active_call_id' => '',
        'call_role' => 'participant',
        'can_moderate_call' => false,
        'connected_at' => $effectiveConnectedAt,
    ];
}

/**
 * @return array{
 *   connection_id: string,
 *   room_id: string,
 *   user: array{
 *     id: int,
 *     display_name: string,
 *     role: string,
 *     call_role: string
 *   },
 *   connected_at: string
 * }
 */
function videochat_presence_public_connection(array $connection): array
{
    return [
        'connection_id' => (string) ($connection['connection_id'] ?? ''),
        'room_id' => (string) ($connection['room_id'] ?? ''),
        'user' => [
            'id' => (int) ($connection['user_id'] ?? 0),
            'display_name' => (string) ($connection['display_name'] ?? ''),
            'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? '')),
            'call_role' => (static function (string $role): string {
                $normalized = strtolower(trim($role));
                return in_array($normalized, ['owner', 'moderator', 'participant'], true) ? $normalized : 'participant';
            })((string) ($connection['call_role'] ?? 'participant')),
        ],
        'connected_at' => (string) ($connection['connected_at'] ?? ''),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_presence_room_participants(array $state, string $roomId): array
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $roomConnections = $state['rooms'][$normalizedRoomId] ?? null;
    if (!is_array($roomConnections) || $roomConnections === []) {
        return [];
    }

    $participants = [];
    foreach ($roomConnections as $connectionId => $_socket) {
        if (!is_string($connectionId) || $connectionId === '') {
            continue;
        }

        $connection = $state['connections'][$connectionId] ?? null;
        if (!is_array($connection)) {
            continue;
        }

        $participants[] = videochat_presence_public_connection($connection);
    }

    usort(
        $participants,
        static function (array $left, array $right): int {
            $leftRoleRank = videochat_presence_role_rank((string) (($left['user'] ?? [])['role'] ?? ''));
            $rightRoleRank = videochat_presence_role_rank((string) (($right['user'] ?? [])['role'] ?? ''));
            if ($leftRoleRank !== $rightRoleRank) {
                return $leftRoleRank <=> $rightRoleRank;
            }

            $leftName = strtolower(trim((string) (($left['user'] ?? [])['display_name'] ?? '')));
            $rightName = strtolower(trim((string) (($right['user'] ?? [])['display_name'] ?? '')));
            $nameCompare = $leftName <=> $rightName;
            if ($nameCompare !== 0) {
                return $nameCompare;
            }

            return ((string) ($left['connection_id'] ?? '')) <=> ((string) ($right['connection_id'] ?? ''));
        }
    );

    return $participants;
}

function videochat_presence_send_frame(mixed $socket, array $payload, ?callable $sender = null): bool
{
    $payload = videochat_realtime_normalize_error_frame($payload);

    if ($sender !== null) {
        try {
            return $sender($socket, $payload) === true;
        } catch (Throwable) {
            return false;
        }
    }

    if (!function_exists('king_websocket_send')) {
        return false;
    }

    $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encodedPayload) || $encodedPayload === '') {
        return false;
    }

    try {
        return king_websocket_send($socket, $encodedPayload) === true;
    } catch (Throwable) {
        return false;
    }
}

function videochat_presence_send_room_snapshot(
    array $state,
    array $connection,
    string $reason = 'snapshot',
    ?callable $sender = null
): bool {
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''));
    $participants = videochat_presence_room_participants($state, $roomId);

    return videochat_presence_send_frame(
        $connection['socket'] ?? null,
        [
            'type' => 'room/snapshot',
            'room_id' => $roomId,
            'participants' => $participants,
            'participant_count' => count($participants),
            'viewer' => [
                'user_id' => (int) ($connection['user_id'] ?? 0),
                'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? '')),
                'call_id' => (string) ($connection['active_call_id'] ?? ''),
                'call_role' => (static function (string $role): string {
                    $normalized = strtolower(trim($role));
                    return in_array($normalized, ['owner', 'moderator', 'participant'], true) ? $normalized : 'participant';
                })((string) ($connection['call_role'] ?? 'participant')),
                'effective_call_role' => (static function (string $role): string {
                    $normalized = strtolower(trim($role));
                    return in_array($normalized, ['owner', 'moderator', 'participant'], true) ? $normalized : 'participant';
                })((string) ($connection['effective_call_role'] ?? ($connection['call_role'] ?? 'participant'))),
                'can_moderate' => (bool) ($connection['can_moderate_call'] ?? false),
                'can_manage_owner' => (bool) ($connection['can_manage_call_owner'] ?? false),
            ],
            'reason' => trim($reason) === '' ? 'snapshot' : trim($reason),
            'time' => gmdate('c'),
        ],
        $sender
    );
}

function videochat_presence_broadcast_room_event(
    array $state,
    string $roomId,
    array $payload,
    ?string $excludeConnectionId = null,
    ?callable $sender = null
): int {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $roomConnections = $state['rooms'][$normalizedRoomId] ?? null;
    if (!is_array($roomConnections) || $roomConnections === []) {
        return 0;
    }

    $sentCount = 0;
    $excludedId = trim((string) ($excludeConnectionId ?? ''));
    foreach ($roomConnections as $connectionId => $_socket) {
        if (!is_string($connectionId) || $connectionId === '') {
            continue;
        }
        if ($excludedId !== '' && $connectionId === $excludedId) {
            continue;
        }

        $connection = $state['connections'][$connectionId] ?? null;
        if (!is_array($connection)) {
            continue;
        }

        if (videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender)) {
            $sentCount++;
        }
    }

    return $sentCount;
}

/**
 * @return array{
 *   connection: array<string, mixed>,
 *   previous_room_id: string,
 *   changed: bool
 * }
 */
function videochat_presence_join_room(
    array &$state,
    array $connection,
    string $roomId,
    ?callable $sender = null
): array {
    $connectionId = trim((string) ($connection['connection_id'] ?? ''));
    if ($connectionId === '') {
        throw new InvalidArgumentException('presence connection requires a non-empty connection_id.');
    }

    $existingConnection = $state['connections'][$connectionId] ?? null;
    $effectiveConnection = is_array($existingConnection) ? $existingConnection : $connection;
    $previousRoomCandidate = is_array($existingConnection)
        ? strtolower(trim((string) ($existingConnection['room_id'] ?? '')))
        : '';
    $previousRoomId = videochat_presence_is_valid_room_id($previousRoomCandidate)
        ? $previousRoomCandidate
        : '';
    $nextRoomId = videochat_presence_normalize_room_id($roomId);
    $effectiveConnection['room_id'] = $nextRoomId;

    $state['connections'][$connectionId] = $effectiveConnection;
    if ($previousRoomId !== '' && $previousRoomId !== $nextRoomId) {
        unset($state['rooms'][$previousRoomId][$connectionId]);
        if (($state['rooms'][$previousRoomId] ?? []) === []) {
            unset($state['rooms'][$previousRoomId]);
        }

        $leavingConnection = $effectiveConnection;
        $leavingConnection['room_id'] = $previousRoomId;
        $leavePayload = [
            'type' => 'room/left',
            'room_id' => $previousRoomId,
            'participant' => videochat_presence_public_connection($leavingConnection),
            'participant_count' => count(videochat_presence_room_participants($state, $previousRoomId)),
            'time' => gmdate('c'),
        ];
        videochat_presence_broadcast_room_event($state, $previousRoomId, $leavePayload, $connectionId, $sender);
    }

    if (!isset($state['rooms'][$nextRoomId]) || !is_array($state['rooms'][$nextRoomId])) {
        $state['rooms'][$nextRoomId] = [];
    }
    $state['rooms'][$nextRoomId][$connectionId] = $effectiveConnection['socket'] ?? null;

    $joinPayload = [
        'type' => 'room/joined',
        'room_id' => $nextRoomId,
        'participant' => videochat_presence_public_connection($effectiveConnection),
        'participant_count' => count(videochat_presence_room_participants($state, $nextRoomId)),
        'time' => gmdate('c'),
    ];
    videochat_presence_broadcast_room_event($state, $nextRoomId, $joinPayload, $connectionId, $sender);
    videochat_presence_send_room_snapshot(
        $state,
        $effectiveConnection,
        $previousRoomId === $nextRoomId ? 'resync' : 'joined',
        $sender
    );

    return [
        'connection' => $effectiveConnection,
        'previous_room_id' => $previousRoomId,
        'changed' => $previousRoomId !== $nextRoomId,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function videochat_presence_remove_connection(
    array &$state,
    string $connectionId,
    ?callable $sender = null
): ?array {
    $trimmedConnectionId = trim($connectionId);
    if ($trimmedConnectionId === '') {
        return null;
    }

    $existingConnection = $state['connections'][$trimmedConnectionId] ?? null;
    if (!is_array($existingConnection)) {
        return null;
    }

    $roomId = videochat_presence_normalize_room_id((string) ($existingConnection['room_id'] ?? ''), '');
    unset($state['connections'][$trimmedConnectionId]);

    if ($roomId !== '' && isset($state['rooms'][$roomId]) && is_array($state['rooms'][$roomId])) {
        unset($state['rooms'][$roomId][$trimmedConnectionId]);
        if ($state['rooms'][$roomId] === []) {
            unset($state['rooms'][$roomId]);
        }
    }

    if ($roomId !== '') {
        $leavePayload = [
            'type' => 'room/left',
            'room_id' => $roomId,
            'participant' => videochat_presence_public_connection($existingConnection),
            'participant_count' => count(videochat_presence_room_participants($state, $roomId)),
            'time' => gmdate('c'),
        ];
        videochat_presence_broadcast_room_event($state, $roomId, $leavePayload, $trimmedConnectionId, $sender);
    }

    return $existingConnection;
}

/**
 * @return array{
 *   ok: bool,
 *   type: string,
 *   room_id: string,
 *   error: string
 * }
 */
function videochat_presence_decode_client_frame(string $frame): array
{
    $decoded = json_decode($frame, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'type' => '',
            'room_id' => '',
            'error' => 'invalid_json',
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return [
            'ok' => false,
            'type' => '',
            'room_id' => '',
            'error' => 'missing_type',
        ];
    }

    if ($type === 'ping') {
        return [
            'ok' => true,
            'type' => 'ping',
            'room_id' => '',
            'error' => '',
        ];
    }

    if ($type === 'room/snapshot' || $type === 'room/snapshot/request') {
        return [
            'ok' => true,
            'type' => 'room/snapshot/request',
            'room_id' => '',
            'error' => '',
        ];
    }

    if ($type === 'room/leave') {
        return [
            'ok' => true,
            'type' => 'room/leave',
            'room_id' => '',
            'error' => '',
        ];
    }

    if ($type === 'room/join') {
        $requestedRoomId = strtolower(trim((string) ($decoded['room_id'] ?? '')));
        if (!videochat_presence_is_valid_room_id($requestedRoomId)) {
            return [
                'ok' => false,
                'type' => 'room/join',
                'room_id' => '',
                'error' => 'invalid_room_id',
            ];
        }

        return [
            'ok' => true,
            'type' => 'room/join',
            'room_id' => $requestedRoomId,
            'error' => '',
        ];
    }

    return [
        'ok' => false,
        'type' => $type,
        'room_id' => '',
        'error' => 'unsupported_type',
    ];
}
