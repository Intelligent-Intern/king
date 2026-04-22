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

function videochat_reaction_flood_window_ms(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_FLOOD_WINDOW_MS'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_THROTTLE_WINDOW_MS'), FILTER_VALIDATE_INT);
    }
    if (!is_int($configured)) {
        $configured = 1000;
    }

    if ($configured < 250) {
        return 250;
    }
    if ($configured > 60000) {
        return 60000;
    }

    return $configured;
}

function videochat_reaction_flood_threshold_per_window(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_FLOOD_THRESHOLD_PER_WINDOW'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_THROTTLE_MAX_PER_WINDOW'), FILTER_VALIDATE_INT);
    }
    if (!is_int($configured)) {
        $configured = 20;
    }

    if ($configured < 1) {
        return 1;
    }
    if ($configured > 1000) {
        return 1000;
    }

    return $configured;
}

function videochat_reaction_flood_batch_size(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_FLOOD_BATCH_SIZE'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 25;
    }

    if ($configured < 1) {
        return 1;
    }
    if ($configured > 200) {
        return 200;
    }

    return $configured;
}

function videochat_reaction_client_batch_max_count(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_CLIENT_BATCH_MAX_COUNT'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 25;
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
 *   emojis: array<int, string>,
 *   client_reaction_id: ?string,
 *   error: string
 * }
 */
function videochat_reaction_decode_client_reaction_id(mixed $value): array
{
    if (!is_string($value)) {
        return [
            'ok' => true,
            'client_reaction_id' => null,
            'error' => '',
        ];
    }

    $candidate = trim($value);
    if ($candidate === '') {
        return [
            'ok' => true,
            'client_reaction_id' => null,
            'error' => '',
        ];
    }

    if (strlen($candidate) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $candidate) !== 1) {
        return [
            'ok' => false,
            'client_reaction_id' => null,
            'error' => 'invalid_client_reaction_id',
        ];
    }

    return [
        'ok' => true,
        'client_reaction_id' => $candidate,
        'error' => '',
    ];
}

function videochat_reaction_validate_emoji(string $rawEmoji): array
{
    $emoji = trim($rawEmoji);
    if ($emoji === '') {
        return [
            'ok' => false,
            'emoji' => '',
            'error' => 'empty_emoji',
        ];
    }

    if (strlen($emoji) > videochat_reaction_max_bytes()) {
        return [
            'ok' => false,
            'emoji' => '',
            'error' => 'emoji_too_large',
        ];
    }

    if (videochat_reaction_payload_length($emoji) > videochat_reaction_max_chars()) {
        return [
            'ok' => false,
            'emoji' => '',
            'error' => 'emoji_too_long',
        ];
    }

    $allowed = videochat_reaction_allowed_emoji_set();
    if (($allowed[$emoji] ?? false) !== true) {
        return [
            'ok' => false,
            'emoji' => '',
            'error' => 'unsupported_emoji',
        ];
    }

    return [
        'ok' => true,
        'emoji' => $emoji,
        'error' => '',
    ];
}

function videochat_reaction_decode_client_frame(mixed $frame): array
{
    $decoded = function_exists('videochat_realtime_decode_client_payload')
        ? videochat_realtime_decode_client_payload($frame)
        : (is_string($frame) ? json_decode($frame, true) : (is_array($frame) ? $frame : null));
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'type' => '',
            'emoji' => '',
            'emojis' => [],
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
            'emojis' => [],
            'client_reaction_id' => null,
            'error' => 'missing_type',
        ];
    }

    if (!in_array($type, ['reaction/send', 'reaction/send_batch'], true)) {
        return [
            'ok' => false,
            'type' => $type,
            'emoji' => '',
            'emojis' => [],
            'client_reaction_id' => null,
            'error' => 'unsupported_type',
        ];
    }

    $clientReactionIdResult = videochat_reaction_decode_client_reaction_id($decoded['client_reaction_id'] ?? null);
    if (!(bool) ($clientReactionIdResult['ok'] ?? false)) {
        return [
            'ok' => false,
            'type' => $type,
            'emoji' => '',
            'emojis' => [],
            'client_reaction_id' => null,
            'error' => (string) ($clientReactionIdResult['error'] ?? 'invalid_client_reaction_id'),
        ];
    }
    $clientReactionId = $clientReactionIdResult['client_reaction_id'] ?? null;

    if ($type === 'reaction/send_batch') {
        $rawBatch = $decoded['emojis'] ?? null;
        if (!is_array($rawBatch) || $rawBatch === []) {
            return [
                'ok' => false,
                'type' => $type,
                'emoji' => '',
                'emojis' => [],
                'client_reaction_id' => null,
                'error' => 'empty_batch',
            ];
        }
        if (count($rawBatch) > videochat_reaction_client_batch_max_count()) {
            return [
                'ok' => false,
                'type' => $type,
                'emoji' => '',
                'emojis' => [],
                'client_reaction_id' => null,
                'error' => 'batch_too_large',
            ];
        }

        $emojis = [];
        foreach ($rawBatch as $rawEmoji) {
            if (!is_string($rawEmoji)) {
                return [
                    'ok' => false,
                    'type' => $type,
                    'emoji' => '',
                    'emojis' => [],
                    'client_reaction_id' => null,
                    'error' => 'invalid_batch_emoji',
                ];
            }
            $validation = videochat_reaction_validate_emoji($rawEmoji);
            if (!(bool) ($validation['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'type' => $type,
                    'emoji' => '',
                    'emojis' => [],
                    'client_reaction_id' => null,
                    'error' => (string) ($validation['error'] ?? 'invalid_emoji'),
                ];
            }
            $emojis[] = (string) ($validation['emoji'] ?? '');
        }

        return [
            'ok' => true,
            'type' => 'reaction/send_batch',
            'emoji' => '',
            'emojis' => $emojis,
            'client_reaction_id' => is_string($clientReactionId) ? $clientReactionId : null,
            'error' => '',
        ];
    }

    $rawEmoji = is_string($decoded['emoji'] ?? null) ? (string) $decoded['emoji'] : '';
    $emojiValidation = videochat_reaction_validate_emoji($rawEmoji);
    if (!(bool) ($emojiValidation['ok'] ?? false)) {
        return [
            'ok' => false,
            'type' => $type,
            'emoji' => '',
            'emojis' => [],
            'client_reaction_id' => null,
            'error' => (string) ($emojiValidation['error'] ?? 'invalid_emoji'),
        ];
    }
    $emoji = (string) ($emojiValidation['emoji'] ?? '');

    return [
        'ok' => true,
        'type' => 'reaction/send',
        'emoji' => $emoji,
        'emojis' => [$emoji],
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

function videochat_reaction_normalize_command_items(
    array $connection,
    array $command,
    string $roomId,
    int $baseNowUnixMs
): array {
    $type = strtolower(trim((string) ($command['type'] ?? 'reaction/send')));
    $clientReactionId = is_string($command['client_reaction_id'] ?? null)
        ? trim((string) $command['client_reaction_id'])
        : '';
    $baseClientReactionId = $clientReactionId !== '' ? $clientReactionId : null;
    $rawEmojis = $type === 'reaction/send_batch'
        ? (is_array($command['emojis'] ?? null) ? $command['emojis'] : [])
        : [(string) ($command['emoji'] ?? '')];

    $items = [];
    foreach ($rawEmojis as $index => $rawEmoji) {
        $emoji = is_string($rawEmoji) ? trim($rawEmoji) : '';
        if ($emoji === '') {
            continue;
        }

        $itemNowUnixMs = $baseNowUnixMs + max(0, (int) $index);
        $itemClientReactionId = $baseClientReactionId;
        if ($itemClientReactionId !== null && $type === 'reaction/send_batch') {
            $itemClientReactionId = $itemClientReactionId . ':' . ((int) $index + 1);
        }

        $idSeedCommand = [
            'client_reaction_id' => $itemClientReactionId,
        ];
        $items[] = [
            'id' => videochat_reaction_resolve_id($connection, $idSeedCommand, $roomId, $itemNowUnixMs),
            'emoji' => $emoji,
            'client_reaction_id' => $itemClientReactionId,
            'server_unix_ms' => $itemNowUnixMs,
            'server_time' => gmdate('c', (int) floor($itemNowUnixMs / 1000)),
        ];
    }

    return $items;
}

function videochat_reaction_single_event_payload(
    string $roomId,
    array $senderPayload,
    array $reaction
): array {
    return [
        'type' => 'reaction/event',
        'room_id' => $roomId,
        'sender' => $senderPayload,
        'reaction' => $reaction,
        'time' => (string) ($reaction['server_time'] ?? gmdate('c')),
    ];
}

function videochat_reaction_batch_event_payload(
    string $roomId,
    array $senderPayload,
    array $reactions,
    string $mode,
    int $serverUnixMs
): array {
    $serverTime = gmdate('c', (int) floor($serverUnixMs / 1000));
    $batchId = 'reaction_batch_' . $serverUnixMs;
    try {
        $batchId .= '_' . bin2hex(random_bytes(4));
    } catch (Throwable) {
        $batchId .= '_' . substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 8);
    }

    return [
        'type' => 'reaction/batch',
        'room_id' => $roomId,
        'sender' => $senderPayload,
        'batch' => [
            'id' => $batchId,
            'mode' => $mode,
            'size' => count($reactions),
            'server_unix_ms' => $serverUnixMs,
            'server_time' => $serverTime,
        ],
        'reactions' => array_values($reactions),
        'time' => $serverTime,
    ];
}

function videochat_reaction_broadcast_payload(
    array $presenceState,
    string $roomId,
    array $event,
    ?callable $sender = null
): int {
    return videochat_presence_broadcast_room_event(
        $presenceState,
        $roomId,
        $event,
        null,
        $sender
    );
}

function videochat_reaction_flush_flood_buffer(
    array &$buffer,
    array $presenceState,
    string $roomId,
    array $senderPayload,
    int $batchSize,
    bool $flushPartial,
    ?callable $sender = null,
    ?int $nowUnixMs = null
): array {
    $sentCount = 0;
    $lastEvent = null;
    $effectiveNowUnixMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);

    while (count($buffer) >= $batchSize || ($flushPartial && count($buffer) > 0)) {
        $chunkSize = count($buffer) >= $batchSize ? $batchSize : count($buffer);
        if ($chunkSize <= 0) {
            break;
        }

        $chunk = array_splice($buffer, 0, $chunkSize);
        $chunkTail = $chunk[count($chunk) - 1] ?? [];
        $chunkUnixMs = (int) ($chunkTail['server_unix_ms'] ?? $effectiveNowUnixMs);
        if ($chunkUnixMs <= 0) {
            $chunkUnixMs = $effectiveNowUnixMs;
        }
        $event = videochat_reaction_batch_event_payload($roomId, $senderPayload, $chunk, 'flood', $chunkUnixMs);
        $delivered = videochat_reaction_broadcast_payload($presenceState, $roomId, $event, $sender);
        if ($delivered <= 0) {
            return [
                'ok' => false,
                'error' => 'delivery_failed',
                'sent_count' => $sentCount,
                'event' => $event,
            ];
        }

        $sentCount += $delivered;
        $lastEvent = $event;
    }

    return [
        'ok' => true,
        'error' => '',
        'sent_count' => $sentCount,
        'event' => $lastEvent,
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
    $windowMs = videochat_reaction_flood_window_ms();
    $floodThreshold = videochat_reaction_flood_threshold_per_window();
    $floodBatchSize = videochat_reaction_flood_batch_size();

    if (!isset($reactionState['rooms'][$roomId]) || !is_array($reactionState['rooms'][$roomId])) {
        $reactionState['rooms'][$roomId] = [];
    }

    $userKey = (string) $senderUserId;
    $entry = $reactionState['rooms'][$roomId][$userKey] ?? null;
    if (!is_array($entry)) {
        $entry = [
            'window_started_ms' => $effectiveNowMs,
            'count' => 0,
            'flood_buffer' => [],
        ];
    }

    $windowStartedMs = (int) ($entry['window_started_ms'] ?? 0);
    if ($windowStartedMs <= 0) {
        $windowStartedMs = $effectiveNowMs;
    }
    $countInWindow = max(0, (int) ($entry['count'] ?? 0));
    $floodBuffer = is_array($entry['flood_buffer'] ?? null) ? array_values($entry['flood_buffer']) : [];
    $senderPayload = videochat_reaction_sender_payload($connection);
    $sentCount = 0;
    $lastEvent = null;

    $elapsedMs = max(0, $effectiveNowMs - $windowStartedMs);
    if ($elapsedMs >= $windowMs) {
        $flushRollover = videochat_reaction_flush_flood_buffer(
            $floodBuffer,
            $presenceState,
            $roomId,
            $senderPayload,
            $floodBatchSize,
            true,
            $sender,
            $effectiveNowMs
        );
        if (!(bool) ($flushRollover['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($flushRollover['error'] ?? 'delivery_failed'),
                'event' => is_array($flushRollover['event'] ?? null) ? $flushRollover['event'] : null,
                'sent_count' => (int) ($flushRollover['sent_count'] ?? 0),
                'retry_after_ms' => 0,
                'remaining_in_window' => 0,
            ];
        }
        $sentCount += (int) ($flushRollover['sent_count'] ?? 0);
        if (is_array($flushRollover['event'] ?? null)) {
            $lastEvent = $flushRollover['event'];
        }
        $windowStartedMs = $effectiveNowMs;
        $countInWindow = 0;
    }

    $reactionItems = videochat_reaction_normalize_command_items($connection, $command, $roomId, $effectiveNowMs);
    if ($reactionItems === []) {
        $reactionState['rooms'][$roomId][$userKey] = [
            'window_started_ms' => $windowStartedMs,
            'count' => $countInWindow,
            'flood_buffer' => $floodBuffer,
        ];
        return [
            'ok' => true,
            'error' => '',
            'event' => $lastEvent,
            'sent_count' => $sentCount,
            'retry_after_ms' => 0,
            'remaining_in_window' => max(0, $floodThreshold - $countInWindow),
        ];
    }

    foreach ($reactionItems as $reactionItem) {
        $countInWindow++;
        $reactionUnixMs = (int) ($reactionItem['server_unix_ms'] ?? $effectiveNowMs);
        if ($countInWindow > $floodThreshold) {
            $floodBuffer[] = $reactionItem;
            $flushFullBatches = videochat_reaction_flush_flood_buffer(
                $floodBuffer,
                $presenceState,
                $roomId,
                $senderPayload,
                $floodBatchSize,
                false,
                $sender,
                $reactionUnixMs
            );
            if (!(bool) ($flushFullBatches['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => (string) ($flushFullBatches['error'] ?? 'delivery_failed'),
                    'event' => is_array($flushFullBatches['event'] ?? null) ? $flushFullBatches['event'] : null,
                    'sent_count' => $sentCount + (int) ($flushFullBatches['sent_count'] ?? 0),
                    'retry_after_ms' => 0,
                    'remaining_in_window' => 0,
                ];
            }
            $sentCount += (int) ($flushFullBatches['sent_count'] ?? 0);
            if (is_array($flushFullBatches['event'] ?? null)) {
                $lastEvent = $flushFullBatches['event'];
            }
            continue;
        }

        $event = videochat_reaction_single_event_payload($roomId, $senderPayload, $reactionItem);
        $delivered = videochat_reaction_broadcast_payload($presenceState, $roomId, $event, $sender);
        if ($delivered <= 0) {
            return [
                'ok' => false,
                'error' => 'delivery_failed',
                'event' => $event,
                'sent_count' => $sentCount,
                'retry_after_ms' => 0,
                'remaining_in_window' => 0,
            ];
        }

        $sentCount += $delivered;
        $lastEvent = $event;
    }

    $reactionState['rooms'][$roomId][$userKey] = [
        'window_started_ms' => $windowStartedMs,
        'count' => $countInWindow,
        'flood_buffer' => $floodBuffer,
    ];

    $remainingInWindow = max(0, $floodThreshold - $countInWindow);

    return [
        'ok' => true,
        'error' => '',
        'event' => $lastEvent,
        'sent_count' => $sentCount,
        'retry_after_ms' => 0,
        'remaining_in_window' => $remainingInWindow,
    ];
}
