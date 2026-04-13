<?php

declare(strict_types=1);

function videochat_signaling_message_id(?int $nowUnixMs = null): string
{
    $effectiveNowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);

    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable) {
        $suffix = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 16);
    }

    return 'signal_' . $effectiveNowMs . '_' . $suffix;
}

/**
 * @return array{
 *   ok: bool,
 *   type: string,
 *   target_user_id: int,
 *   payload: mixed,
 *   error: string
 * }
 */
function videochat_signaling_decode_client_frame(string $frame): array
{
    $decoded = json_decode($frame, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'type' => '',
            'target_user_id' => 0,
            'payload' => null,
            'error' => 'invalid_json',
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return [
            'ok' => false,
            'type' => '',
            'target_user_id' => 0,
            'payload' => null,
            'error' => 'missing_type',
        ];
    }

    if (!in_array($type, ['call/offer', 'call/answer', 'call/ice', 'call/hangup'], true)) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'payload' => null,
            'error' => 'unsupported_type',
        ];
    }

    $rawTargetUserId = $decoded['target_user_id'] ?? ($decoded['targetUserId'] ?? null);
    if ($rawTargetUserId === null) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'payload' => null,
            'error' => 'missing_target_user_id',
        ];
    }

    $targetUserId = 0;
    if (is_int($rawTargetUserId)) {
        $targetUserId = $rawTargetUserId;
    } elseif (is_string($rawTargetUserId)) {
        $candidate = trim($rawTargetUserId);
        if ($candidate !== '' && preg_match('/^[0-9]+$/', $candidate) === 1) {
            $targetUserId = (int) $candidate;
        }
    }

    if ($targetUserId <= 0) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'payload' => null,
            'error' => 'invalid_target_user_id',
        ];
    }

    $payload = $decoded['payload'] ?? null;
    if ($payload !== null && !is_scalar($payload) && !is_array($payload)) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'payload' => null,
            'error' => 'invalid_payload',
        ];
    }

    return [
        'ok' => true,
        'type' => $type,
        'target_user_id' => $targetUserId,
        'payload' => $payload,
        'error' => '',
    ];
}

function videochat_signaling_sender_payload(array $connection): array
{
    return [
        'user_id' => (int) ($connection['user_id'] ?? 0),
        'display_name' => (string) ($connection['display_name'] ?? ''),
        'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'user')),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_signaling_target_connections(array $presenceState, string $roomId, int $targetUserId): array
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $roomConnections = $presenceState['rooms'][$normalizedRoomId] ?? null;
    if (!is_array($roomConnections) || $roomConnections === []) {
        return [];
    }

    $targets = [];
    foreach ($roomConnections as $connectionId => $_socket) {
        if (!is_string($connectionId) || $connectionId === '') {
            continue;
        }

        $connection = $presenceState['connections'][$connectionId] ?? null;
        if (!is_array($connection)) {
            continue;
        }

        if ((int) ($connection['user_id'] ?? 0) !== $targetUserId) {
            continue;
        }

        $targets[] = $connection;
    }

    return $targets;
}

/**
 * @return array{
 *   ok: bool,
 *   error: string,
 *   event: array<string, mixed>|null,
 *   sent_count: int,
 *   target_user_id: int
 * }
 */
function videochat_signaling_publish(
    array $presenceState,
    array $connection,
    array $command,
    ?callable $sender = null,
    ?int $nowUnixMs = null
): array {
    if (!(bool) ($command['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'invalid_command',
            'event' => null,
            'sent_count' => 0,
            'target_user_id' => 0,
        ];
    }

    $senderUserId = (int) ($connection['user_id'] ?? 0);
    if ($senderUserId <= 0) {
        return [
            'ok' => false,
            'error' => 'invalid_sender',
            'event' => null,
            'sent_count' => 0,
            'target_user_id' => 0,
        ];
    }

    $targetUserId = (int) ($command['target_user_id'] ?? 0);
    if ($targetUserId <= 0 || $targetUserId === $senderUserId) {
        return [
            'ok' => false,
            'error' => 'invalid_target_user_id',
            'event' => null,
            'sent_count' => 0,
            'target_user_id' => $targetUserId,
        ];
    }

    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? 'lobby'));
    $connectionId = trim((string) ($connection['connection_id'] ?? ''));
    $roomConnections = $presenceState['rooms'][$roomId] ?? null;
    if (
        $connectionId === ''
        || !is_array($roomConnections)
        || !array_key_exists($connectionId, $roomConnections)
    ) {
        return [
            'ok' => false,
            'error' => 'sender_not_in_room',
            'event' => null,
            'sent_count' => 0,
            'target_user_id' => $targetUserId,
        ];
    }

    $targetConnections = videochat_signaling_target_connections($presenceState, $roomId, $targetUserId);
    if ($targetConnections === []) {
        return [
            'ok' => false,
            'error' => 'target_not_in_room',
            'event' => null,
            'sent_count' => 0,
            'target_user_id' => $targetUserId,
        ];
    }

    $effectiveNowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);
    $effectiveNowIso = gmdate('c', (int) floor($effectiveNowMs / 1000));

    $event = [
        'type' => (string) ($command['type'] ?? ''),
        'room_id' => $roomId,
        'target_user_id' => $targetUserId,
        'sender' => videochat_signaling_sender_payload($connection),
        'payload' => $command['payload'] ?? null,
        'signal' => [
            'id' => videochat_signaling_message_id($effectiveNowMs),
            'server_unix_ms' => $effectiveNowMs,
            'server_time' => $effectiveNowIso,
        ],
        'time' => $effectiveNowIso,
    ];

    $sentCount = 0;
    foreach ($targetConnections as $targetConnection) {
        if (videochat_presence_send_frame($targetConnection['socket'] ?? null, $event, $sender)) {
            $sentCount++;
        }
    }

    if ($sentCount <= 0) {
        return [
            'ok' => false,
            'error' => 'target_delivery_failed',
            'event' => $event,
            'sent_count' => 0,
            'target_user_id' => $targetUserId,
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'event' => $event,
        'sent_count' => $sentCount,
        'target_user_id' => $targetUserId,
    ];
}
