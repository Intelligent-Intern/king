<?php

declare(strict_types=1);

function videochat_reaction_state_init(): array
{
    return [
        'rooms' => [],
    ];
}

function videochat_reaction_default_emoji_set(): array
{
    return [
        "\u{1F44D}" => true,
        "\u{2764}\u{FE0F}" => true,
        "\u{1F418}" => true,
        "\u{1F973}" => true,
        "\u{1F602}" => true,
        "\u{1F62E}" => true,
        "\u{1F622}" => true,
        "\u{1F914}" => true,
        "\u{1F44F}" => true,
        "\u{1F44E}" => true,
    ];
}

function videochat_reaction_allowed_emoji_set(): array
{
    $configured = getenv('VIDEOCHAT_WS_REACTION_ALLOWED_EMOJIS');
    if (!is_string($configured) || trim($configured) === '') {
        return videochat_reaction_default_emoji_set();
    }

    $allowed = [];
    foreach (preg_split('/\s*,\s*/', $configured) ?: [] as $entry) {
        if (!is_string($entry)) {
            continue;
        }

        $emoji = trim($entry);
        if ($emoji === '') {
            continue;
        }

        $allowed[$emoji] = true;
    }

    return $allowed !== [] ? $allowed : videochat_reaction_default_emoji_set();
}

function videochat_reaction_max_chars(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_MAX_CHARS'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 8;
    }

    if ($configured < 1) {
        return 1;
    }
    if ($configured > 32) {
        return 32;
    }

    return $configured;
}

function videochat_reaction_max_bytes(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_MAX_BYTES'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 32;
    }

    if ($configured < 4) {
        return 4;
    }
    if ($configured > 256) {
        return 256;
    }

    return $configured;
}

function videochat_reaction_throttle_window_ms(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_THROTTLE_WINDOW_MS'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 3000;
    }

    if ($configured < 250) {
        return 250;
    }
    if ($configured > 60000) {
        return 60000;
    }

    return $configured;
}

function videochat_reaction_throttle_max_per_window(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_THROTTLE_MAX_PER_WINDOW'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 10;
    }

    if ($configured < 1) {
        return 1;
    }
    if ($configured > 200) {
        return 200;
    }

    return $configured;
}

function videochat_reaction_payload_length(string $emoji): int
{
    if (function_exists('mb_strlen')) {
        $length = mb_strlen($emoji, 'UTF-8');
        if (is_int($length)) {
            return $length;
        }
    }

    return strlen($emoji);
}

function videochat_reaction_id(?int $nowUnixMs = null): string
{
    $effectiveNowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);

    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable) {
        $suffix = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 16);
    }

    return 'reaction_' . $effectiveNowMs . '_' . $suffix;
}

function videochat_reaction_resolve_id(
    array $connection,
    array $command,
    string $roomId,
    ?int $nowUnixMs = null
): string {
    $clientReactionId = is_string($command['client_reaction_id'] ?? null)
        ? trim((string) $command['client_reaction_id'])
        : '';
    $senderUserId = (int) ($connection['user_id'] ?? 0);
    if ($senderUserId > 0 && $clientReactionId !== '') {
        $identityHash = hash(
            'sha256',
            videochat_presence_normalize_room_id($roomId) . "\n"
                . $senderUserId . "\n"
                . strtolower($clientReactionId)
        );

        return 'reaction_' . substr($identityHash, 0, 24);
    }

    return videochat_reaction_id($nowUnixMs);
}

/**
 * @return array{
 *   ok: bool,
 *   type: string,
 *   emoji: string,
 *   client_reaction_id: ?string,
 *   error: string
 * }
 */
function videochat_reaction_decode_client_frame(string $frame): array
{
    $decoded = json_decode($frame, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'type' => '',
            'emoji' => '',
            'client_reaction_id' => null,
            'error' => 'invalid_json',
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return [
            'ok' => false,
            'type' => '',
            'emoji' => '',
            'client_reaction_id' => null,
            'error' => 'missing_type',
        ];
    }

    if (!in_array($type, ['reaction/send'], true)) {
        return [
            'ok' => false,
            'type' => $type,
            'emoji' => '',
            'client_reaction_id' => null,
            'error' => 'unsupported_type',
        ];
    }

    $rawEmoji = is_string($decoded['emoji'] ?? null) ? (string) $decoded['emoji'] : '';
    $emoji = trim($rawEmoji);
    if ($emoji === '') {
        return [
            'ok' => false,
            'type' => $type,
            'emoji' => '',
            'client_reaction_id' => null,
            'error' => 'empty_emoji',
        ];
    }

    if (strlen($emoji) > videochat_reaction_max_bytes()) {
        return [
            'ok' => false,
            'type' => $type,
            'emoji' => '',
            'client_reaction_id' => null,
            'error' => 'emoji_too_large',
        ];
    }

    if (videochat_reaction_payload_length($emoji) > videochat_reaction_max_chars()) {
        return [
            'ok' => false,
            'type' => $type,
            'emoji' => '',
            'client_reaction_id' => null,
            'error' => 'emoji_too_long',
        ];
    }

    $allowed = videochat_reaction_allowed_emoji_set();
    if (($allowed[$emoji] ?? false) !== true) {
        return [
            'ok' => false,
            'type' => $type,
            'emoji' => '',
            'client_reaction_id' => null,
            'error' => 'unsupported_emoji',
        ];
    }

    $clientReactionId = null;
    if (is_string($decoded['client_reaction_id'] ?? null)) {
        $candidate = trim((string) $decoded['client_reaction_id']);
        if ($candidate !== '') {
            if (strlen($candidate) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $candidate) !== 1) {
                return [
                    'ok' => false,
                    'type' => $type,
                    'emoji' => '',
                    'client_reaction_id' => null,
                    'error' => 'invalid_client_reaction_id',
                ];
            }
            $clientReactionId = $candidate;
        }
    }

    return [
        'ok' => true,
        'type' => 'reaction/send',
        'emoji' => $emoji,
        'client_reaction_id' => $clientReactionId,
        'error' => '',
    ];
}

function videochat_reaction_sender_payload(array $connection): array
{
    return [
        'user_id' => (int) ($connection['user_id'] ?? 0),
        'display_name' => (string) ($connection['display_name'] ?? ''),
        'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'user')),
    ];
}

function videochat_reaction_clear_for_connection(array &$reactionState, array $connection): bool
{
    $userId = (int) ($connection['user_id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    if ($roomId === '') {
        return false;
    }

    $userKey = (string) $userId;
    if (!isset($reactionState['rooms'][$roomId][$userKey]) || !is_array($reactionState['rooms'][$roomId][$userKey])) {
        return false;
    }

    unset($reactionState['rooms'][$roomId][$userKey]);
    if (($reactionState['rooms'][$roomId] ?? []) === []) {
        unset($reactionState['rooms'][$roomId]);
    }

    return true;
}

/**
 * @return array{
 *   ok: bool,
 *   error: string,
 *   event: array<string, mixed>|null,
 *   sent_count: int,
 *   retry_after_ms: int,
 *   remaining_in_window: int
 * }
 */
function videochat_reaction_publish(
    array &$reactionState,
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
            'retry_after_ms' => 0,
            'remaining_in_window' => 0,
        ];
    }

    $senderUserId = (int) ($connection['user_id'] ?? 0);
    if ($senderUserId <= 0) {
        return [
            'ok' => false,
            'error' => 'invalid_sender',
            'event' => null,
            'sent_count' => 0,
            'retry_after_ms' => 0,
            'remaining_in_window' => 0,
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
            'retry_after_ms' => 0,
            'remaining_in_window' => 0,
        ];
    }

    $effectiveNowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);
    $effectiveNowIso = gmdate('c', (int) floor($effectiveNowMs / 1000));

    $windowMs = videochat_reaction_throttle_window_ms();
    $maxPerWindow = videochat_reaction_throttle_max_per_window();

    if (!isset($reactionState['rooms'][$roomId]) || !is_array($reactionState['rooms'][$roomId])) {
        $reactionState['rooms'][$roomId] = [];
    }

    $userKey = (string) $senderUserId;
    $entry = $reactionState['rooms'][$roomId][$userKey] ?? null;
    $windowStartedMs = is_array($entry) ? (int) ($entry['window_started_ms'] ?? 0) : 0;
    $countInWindow = is_array($entry) ? (int) ($entry['count'] ?? 0) : 0;
    if ($windowStartedMs <= 0) {
        $windowStartedMs = $effectiveNowMs;
    }

    $elapsedMs = max(0, $effectiveNowMs - $windowStartedMs);
    if ($elapsedMs >= $windowMs) {
        $windowStartedMs = $effectiveNowMs;
        $countInWindow = 0;
        $elapsedMs = 0;
    }

    if ($countInWindow >= $maxPerWindow) {
        return [
            'ok' => false,
            'error' => 'throttled',
            'event' => null,
            'sent_count' => 0,
            'retry_after_ms' => max(1, $windowMs - $elapsedMs),
            'remaining_in_window' => 0,
        ];
    }

    $countInWindow++;
    $reactionState['rooms'][$roomId][$userKey] = [
        'window_started_ms' => $windowStartedMs,
        'count' => $countInWindow,
    ];

    $remainingInWindow = max(0, $maxPerWindow - $countInWindow);
    $clientReactionId = is_string($command['client_reaction_id'] ?? null)
        ? trim((string) $command['client_reaction_id'])
        : null;
    if ($clientReactionId === '') {
        $clientReactionId = null;
    }

    $event = [
        'type' => 'reaction/event',
        'room_id' => $roomId,
        'sender' => videochat_reaction_sender_payload($connection),
        'reaction' => [
            'id' => videochat_reaction_resolve_id($connection, $command, $roomId, $effectiveNowMs),
            'emoji' => (string) ($command['emoji'] ?? ''),
            'client_reaction_id' => $clientReactionId,
            'server_unix_ms' => $effectiveNowMs,
            'server_time' => $effectiveNowIso,
        ],
        'time' => $effectiveNowIso,
    ];

    $sentCount = videochat_presence_broadcast_room_event(
        $presenceState,
        $roomId,
        $event,
        null,
        $sender
    );

    if ($sentCount <= 0) {
        return [
            'ok' => false,
            'error' => 'delivery_failed',
            'event' => $event,
            'sent_count' => 0,
            'retry_after_ms' => 0,
            'remaining_in_window' => $remainingInWindow,
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'event' => $event,
        'sent_count' => $sentCount,
        'retry_after_ms' => 0,
        'remaining_in_window' => $remainingInWindow,
    ];
}
