<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/error_envelope.php';
require_once __DIR__ . '/../../support/tenant_context.php';

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

function videochat_presence_room_key(string $roomId, ?int $tenantId = null): string
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    return is_int($tenantId) && $tenantId > 0 ? ('tenant:' . $tenantId . ':room:' . $normalizedRoomId) : $normalizedRoomId;
}

/**
 * @return array{tenant_id: int, room_id: string, room_key: string}|null
 */
function videochat_presence_parse_room_key(string $roomKey): ?array
{
    $candidate = strtolower(trim($roomKey));
    if ($candidate === '') {
        return null;
    }

    if (preg_match('/^tenant:([1-9][0-9]*):room:(.+)$/', $candidate, $matches) !== 1) {
        return null;
    }

    $tenantId = (int) $matches[1];
    $roomId = videochat_presence_normalize_room_id((string) $matches[2], '');
    if ($tenantId <= 0 || $roomId === '') {
        return null;
    }

    return [
        'tenant_id' => $tenantId,
        'room_id' => $roomId,
        'room_key' => 'tenant:' . $tenantId . ':room:' . $roomId,
    ];
}

function videochat_presence_normalize_room_storage_key(?string $roomIdOrKey, string $fallback = ''): string
{
    $parsedRoomKey = videochat_presence_parse_room_key((string) $roomIdOrKey);
    if (is_array($parsedRoomKey)) {
        return (string) $parsedRoomKey['room_key'];
    }

    return videochat_presence_normalize_room_id($roomIdOrKey, $fallback);
}

function videochat_presence_external_room_id_from_key(string $roomIdOrKey, string $fallback = ''): string
{
    $parsedRoomKey = videochat_presence_parse_room_key($roomIdOrKey);
    if (is_array($parsedRoomKey)) {
        return (string) $parsedRoomKey['room_id'];
    }

    return videochat_presence_normalize_room_id($roomIdOrKey, $fallback);
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
        'room_key' => videochat_presence_room_key($roomId, (int) (($authUser['tenant']['id'] ?? 0))),
        'tenant_id' => (int) (($authUser['tenant']['id'] ?? 0)),
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
        'tenant_id' => is_numeric($connection['tenant_id'] ?? null) ? (int) $connection['tenant_id'] : null,
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
function videochat_presence_room_participants(array $state, string $roomId, ?int $tenantId = null): array
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $roomKey = videochat_presence_room_key($normalizedRoomId, $tenantId);
    $roomConnections = $state['rooms'][$roomKey] ?? null;
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
    $tenantId = is_numeric($connection['tenant_id'] ?? null) ? (int) $connection['tenant_id'] : null;
    $participants = videochat_presence_room_participants($state, $roomId, $tenantId);

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
    ?callable $sender = null,
    ?int $tenantId = null
): int {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $roomConnections = $state['rooms'][videochat_presence_room_key($normalizedRoomId, $tenantId)] ?? null;
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
    $tenantId = is_numeric($effectiveConnection['tenant_id'] ?? null) ? (int) $effectiveConnection['tenant_id'] : null;
    $previousRoomKey = is_array($existingConnection) && is_string($existingConnection['room_key'] ?? null)
        ? (string) $existingConnection['room_key']
        : ($previousRoomId === '' ? '' : videochat_presence_room_key($previousRoomId, $tenantId));
    $nextRoomId = videochat_presence_normalize_room_id($roomId);
    $nextRoomKey = videochat_presence_room_key($nextRoomId, $tenantId);
    $effectiveConnection['room_id'] = $nextRoomId;
    $effectiveConnection['room_key'] = $nextRoomKey;

    $state['connections'][$connectionId] = $effectiveConnection;
    if ($previousRoomId !== '' && $previousRoomId !== $nextRoomId) {
        unset($state['rooms'][$previousRoomKey][$connectionId]);
        if (($state['rooms'][$previousRoomKey] ?? []) === []) {
            unset($state['rooms'][$previousRoomKey]);
        }

        $leavingConnection = $effectiveConnection;
        $leavingConnection['room_id'] = $previousRoomId;
        $leavePayload = [
            'type' => 'room/left',
            'room_id' => $previousRoomId,
            'participant' => videochat_presence_public_connection($leavingConnection),
            'participant_count' => count(videochat_presence_room_participants($state, $previousRoomId, $tenantId)),
            'time' => gmdate('c'),
        ];
        videochat_presence_broadcast_room_event($state, $previousRoomId, $leavePayload, $connectionId, $sender, $tenantId);
    }

    if (!isset($state['rooms'][$nextRoomKey]) || !is_array($state['rooms'][$nextRoomKey])) {
        $state['rooms'][$nextRoomKey] = [];
    }
    $state['rooms'][$nextRoomKey][$connectionId] = $effectiveConnection['socket'] ?? null;

    $joinPayload = [
        'type' => 'room/joined',
        'room_id' => $nextRoomId,
        'participant' => videochat_presence_public_connection($effectiveConnection),
        'participant_count' => count(videochat_presence_room_participants($state, $nextRoomId, $tenantId)),
        'time' => gmdate('c'),
    ];
    videochat_presence_broadcast_room_event($state, $nextRoomId, $joinPayload, $connectionId, $sender, $tenantId);
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
    $tenantId = is_numeric($existingConnection['tenant_id'] ?? null) ? (int) $existingConnection['tenant_id'] : null;
    $roomKey = is_string($existingConnection['room_key'] ?? null) ? (string) $existingConnection['room_key'] : videochat_presence_room_key($roomId, $tenantId);
    unset($state['connections'][$trimmedConnectionId]);

    if ($roomId !== '' && isset($state['rooms'][$roomKey]) && is_array($state['rooms'][$roomKey])) {
        unset($state['rooms'][$roomKey][$trimmedConnectionId]);
        if ($state['rooms'][$roomKey] === []) {
            unset($state['rooms'][$roomKey]);
        }
    }

    if ($roomId !== '') {
        $leavePayload = [
            'type' => 'room/left',
            'room_id' => $roomId,
            'participant' => videochat_presence_public_connection($existingConnection),
            'participant_count' => count(videochat_presence_room_participants($state, $roomId, $tenantId)),
            'time' => gmdate('c'),
        ];
        videochat_presence_broadcast_room_event($state, $roomId, $leavePayload, $trimmedConnectionId, $sender, $tenantId);
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
