<?php

declare(strict_types=1);

function videochat_typing_state_init(): array
{
    return [
        'rooms' => [],
    ];
}

function videochat_typing_debounce_ms(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_TYPING_DEBOUNCE_MS'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 500;
    }

    if ($configured < 100) {
        return 100;
    }
    if ($configured > 5000) {
        return 5000;
    }

    return $configured;
}

function videochat_typing_expiry_ms(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_TYPING_EXPIRY_MS'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 3000;
    }

    if ($configured < 500) {
        return 500;
    }
    if ($configured > 15000) {
        return 15000;
    }

    return $configured;
}

/**
 * @return array{
 *   ok: bool,
 *   type: string,
 *   error: string
 * }
 */
function videochat_typing_decode_client_frame(mixed $frame): array
{
    $decoded = function_exists('videochat_realtime_decode_client_payload')
        ? videochat_realtime_decode_client_payload($frame)
        : (is_string($frame) ? json_decode($frame, true) : (is_array($frame) ? $frame : null));
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'type' => '',
            'error' => 'invalid_json',
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return [
            'ok' => false,
            'type' => '',
            'error' => 'missing_type',
        ];
    }

    if (!in_array($type, ['typing/start', 'typing/stop'], true)) {
        return [
            'ok' => false,
            'type' => $type,
            'error' => 'unsupported_type',
        ];
    }

    return [
        'ok' => true,
        'type' => $type,
        'error' => '',
    ];
}

function videochat_typing_sender_payload(array $connection): array
{
    return [
        'user_id' => (int) ($connection['user_id'] ?? 0),
        'display_name' => (string) ($connection['display_name'] ?? ''),
        'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'user')),
    ];
}

function videochat_typing_broadcast(
    array $presenceState,
    string $roomId,
    array $payload,
    int $excludeUserId,
    ?callable $sender = null
): int {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $roomConnections = $presenceState['rooms'][$normalizedRoomId] ?? null;
    if (!is_array($roomConnections) || $roomConnections === []) {
        return 0;
    }

    $sentCount = 0;
    foreach ($roomConnections as $connectionId => $_socket) {
        if (!is_string($connectionId) || $connectionId === '') {
            continue;
        }

        $connection = $presenceState['connections'][$connectionId] ?? null;
        if (!is_array($connection)) {
            continue;
        }

        if ((int) ($connection['user_id'] ?? 0) === $excludeUserId) {
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
 *   ok: bool,
 *   emitted: bool,
 *   event_type: string,
 *   sent_count: int,
 *   error: string
 * }
 */
function videochat_typing_apply_command(
    array &$typingState,
    array $presenceState,
    array $connection,
    array $command,
    ?callable $sender = null,
    ?int $nowUnixMs = null
): array {
    if (!(bool) ($command['ok'] ?? false)) {
        return [
            'ok' => false,
            'emitted' => false,
            'event_type' => '',
            'sent_count' => 0,
            'error' => 'invalid_command',
        ];
    }

    $userId = (int) ($connection['user_id'] ?? 0);
    if ($userId <= 0) {
        return [
            'ok' => false,
            'emitted' => false,
            'event_type' => '',
            'sent_count' => 0,
            'error' => 'invalid_sender',
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
            'emitted' => false,
            'event_type' => '',
            'sent_count' => 0,
            'error' => 'sender_not_in_room',
        ];
    }

    if (!isset($typingState['rooms'][$roomId]) || !is_array($typingState['rooms'][$roomId])) {
        $typingState['rooms'][$roomId] = [];
    }

    $nowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);
    $nowIso = gmdate('c', (int) floor($nowMs / 1000));

    $entry = $typingState['rooms'][$roomId][$userId] ?? null;
    $commandType = (string) ($command['type'] ?? '');

    if ($commandType === 'typing/stop') {
        if (!is_array($entry)) {
            return [
                'ok' => true,
                'emitted' => false,
                'event_type' => '',
                'sent_count' => 0,
                'error' => '',
            ];
        }

        unset($typingState['rooms'][$roomId][$userId]);
        if ($typingState['rooms'][$roomId] === []) {
            unset($typingState['rooms'][$roomId]);
        }

        $sentCount = videochat_typing_broadcast(
            $presenceState,
            $roomId,
            [
                'type' => 'typing/stop',
                'room_id' => $roomId,
                'participant' => videochat_typing_sender_payload($connection),
                'reason' => 'explicit_stop',
                'server_unix_ms' => $nowMs,
                'server_time' => $nowIso,
                'time' => $nowIso,
            ],
            $userId,
            $sender
        );

        return [
            'ok' => true,
            'emitted' => true,
            'event_type' => 'typing/stop',
            'sent_count' => $sentCount,
            'error' => '',
        ];
    }

    $expiryMs = videochat_typing_expiry_ms();
    $debounceMs = videochat_typing_debounce_ms();
    $expiresAtMs = $nowMs + $expiryMs;

    $lastStartEmittedMs = is_array($entry) ? (int) ($entry['last_start_emitted_ms'] ?? 0) : 0;
    $shouldEmitStart = !is_array($entry) || ($nowMs - $lastStartEmittedMs) >= $debounceMs;

    $typingState['rooms'][$roomId][$userId] = [
        'user_id' => $userId,
        'display_name' => (string) ($connection['display_name'] ?? ''),
        'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'user')),
        'last_start_emitted_ms' => $shouldEmitStart ? $nowMs : $lastStartEmittedMs,
        'expires_at_ms' => $expiresAtMs,
    ];

    if (!$shouldEmitStart) {
        return [
            'ok' => true,
            'emitted' => false,
            'event_type' => '',
            'sent_count' => 0,
            'error' => '',
        ];
    }

    $sentCount = videochat_typing_broadcast(
        $presenceState,
        $roomId,
        [
            'type' => 'typing/start',
            'room_id' => $roomId,
            'participant' => videochat_typing_sender_payload($connection),
            'expires_in_ms' => $expiryMs,
            'server_unix_ms' => $nowMs,
            'server_time' => $nowIso,
            'time' => $nowIso,
        ],
        $userId,
        $sender
    );

    return [
        'ok' => true,
        'emitted' => true,
        'event_type' => 'typing/start',
        'sent_count' => $sentCount,
        'error' => '',
    ];
}

/**
 * @return array{
 *   cleared: bool,
 *   sent_count: int
 * }
 */
function videochat_typing_clear_for_connection(
    array &$typingState,
    array $presenceState,
    array $connection,
    string $reason = 'room_left',
    ?callable $sender = null,
    ?int $nowUnixMs = null
): array {
    $userId = (int) ($connection['user_id'] ?? 0);
    if ($userId <= 0) {
        return ['cleared' => false, 'sent_count' => 0];
    }

    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    if ($roomId === '') {
        return ['cleared' => false, 'sent_count' => 0];
    }

    if (!isset($typingState['rooms'][$roomId][$userId]) || !is_array($typingState['rooms'][$roomId][$userId])) {
        return ['cleared' => false, 'sent_count' => 0];
    }

    unset($typingState['rooms'][$roomId][$userId]);
    if (($typingState['rooms'][$roomId] ?? []) === []) {
        unset($typingState['rooms'][$roomId]);
    }

    $nowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);
    $nowIso = gmdate('c', (int) floor($nowMs / 1000));

    $sentCount = videochat_typing_broadcast(
        $presenceState,
        $roomId,
        [
            'type' => 'typing/stop',
            'room_id' => $roomId,
            'participant' => videochat_typing_sender_payload($connection),
            'reason' => trim($reason) === '' ? 'room_left' : trim($reason),
            'server_unix_ms' => $nowMs,
            'server_time' => $nowIso,
            'time' => $nowIso,
        ],
        $userId,
        $sender
    );

    return [
        'cleared' => true,
        'sent_count' => $sentCount,
    ];
}

function videochat_typing_sweep_expired(
    array &$typingState,
    array $presenceState,
    ?callable $sender = null,
    ?int $nowUnixMs = null
): int {
    $nowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);
    $nowIso = gmdate('c', (int) floor($nowMs / 1000));

    $sentCount = 0;
    foreach (array_keys($typingState['rooms'] ?? []) as $roomId) {
        $roomEntries = $typingState['rooms'][$roomId] ?? null;
        if (!is_array($roomEntries) || $roomEntries === []) {
            unset($typingState['rooms'][$roomId]);
            continue;
        }

        $presentUserIds = [];
        $roomConnections = $presenceState['rooms'][$roomId] ?? null;
        if (is_array($roomConnections)) {
            foreach ($roomConnections as $connectionId => $_socket) {
                if (!is_string($connectionId) || $connectionId === '') {
                    continue;
                }
                $connection = $presenceState['connections'][$connectionId] ?? null;
                if (!is_array($connection)) {
                    continue;
                }
                $userId = (int) ($connection['user_id'] ?? 0);
                if ($userId > 0) {
                    $presentUserIds[$userId] = true;
                }
            }
        }

        foreach (array_keys($roomEntries) as $userIdKey) {
            $entry = $typingState['rooms'][$roomId][$userIdKey] ?? null;
            if (!is_array($entry)) {
                unset($typingState['rooms'][$roomId][$userIdKey]);
                continue;
            }

            $userId = (int) ($entry['user_id'] ?? 0);
            $expired = (int) ($entry['expires_at_ms'] ?? 0) <= $nowMs;
            $present = $userId > 0 && (($presentUserIds[$userId] ?? false) === true);
            if (!$expired && $present) {
                continue;
            }

            unset($typingState['rooms'][$roomId][$userIdKey]);
            $payload = [
                'type' => 'typing/stop',
                'room_id' => videochat_presence_normalize_room_id((string) $roomId),
                'participant' => [
                    'user_id' => $userId,
                    'display_name' => (string) ($entry['display_name'] ?? ''),
                    'role' => videochat_normalize_role_slug((string) ($entry['role'] ?? 'user')),
                ],
                'reason' => $expired ? 'expired' : 'room_absent',
                'server_unix_ms' => $nowMs,
                'server_time' => $nowIso,
                'time' => $nowIso,
            ];
            $sentCount += videochat_typing_broadcast(
                $presenceState,
                (string) $roomId,
                $payload,
                $userId,
                $sender
            );
        }

        if (($typingState['rooms'][$roomId] ?? []) === []) {
            unset($typingState['rooms'][$roomId]);
        }
    }

    return $sentCount;
}
