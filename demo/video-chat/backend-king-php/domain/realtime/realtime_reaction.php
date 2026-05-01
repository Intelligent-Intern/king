<?php

declare(strict_types=1);

require_once __DIR__ . '/realtime_reaction_contract.php';
require_once __DIR__ . '/realtime_reaction_broker.php';

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

function videochat_reaction_deliver_payload(
    array $presenceState,
    string $roomId,
    array $event,
    ?callable $sender = null,
    ?callable $broker = null
): int {
    if ($broker !== null) {
        try {
            return $broker($roomId, $event) === true ? 1 : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    return videochat_reaction_broadcast_payload($presenceState, $roomId, $event, $sender);
}

function videochat_reaction_flush_flood_buffer(
    array &$buffer,
    array $presenceState,
    string $roomId,
    array $senderPayload,
    int $batchSize,
    bool $flushPartial,
    ?callable $sender = null,
    ?int $nowUnixMs = null,
    ?callable $broker = null
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
        $delivered = videochat_reaction_deliver_payload($presenceState, $roomId, $event, $sender, $broker);
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
    ?int $nowUnixMs = null,
    ?callable $broker = null
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
            $effectiveNowMs,
            $broker
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
                $reactionUnixMs,
                $broker
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
        $delivered = videochat_reaction_deliver_payload($presenceState, $roomId, $event, $sender, $broker);
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
