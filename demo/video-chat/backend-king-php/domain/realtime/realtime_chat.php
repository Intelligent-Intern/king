<?php

declare(strict_types=1);

function videochat_chat_max_chars(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_CHAT_MAX_CHARS'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 2000;
    }

    if ($configured < 1) {
        return 1;
    }
    if ($configured > 8000) {
        return 8000;
    }

    return $configured;
}

function videochat_chat_max_bytes(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_CHAT_MAX_BYTES'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 8192;
    }

    if ($configured < 64) {
        return 64;
    }
    if ($configured > 65536) {
        return 65536;
    }

    return $configured;
}

function videochat_chat_message_length(string $message): int
{
    if (function_exists('mb_strlen')) {
        $length = mb_strlen($message, 'UTF-8');
        if (is_int($length)) {
            return $length;
        }
    }

    return strlen($message);
}

function videochat_chat_message_id(?int $nowUnixMs = null): string
{
    $effectiveNowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);

    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable) {
        $suffix = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 16);
    }

    return 'chat_' . $effectiveNowMs . '_' . $suffix;
}

function videochat_chat_resolve_message_id(
    array $connection,
    array $command,
    string $roomId,
    ?int $nowUnixMs = null
): string {
    $clientMessageId = is_string($command['client_message_id'] ?? null)
        ? trim((string) $command['client_message_id'])
        : '';
    $senderUserId = (int) ($connection['user_id'] ?? 0);
    if ($senderUserId > 0 && $clientMessageId !== '') {
        $identityHash = hash(
            'sha256',
            videochat_presence_normalize_room_id($roomId) . "\n"
                . $senderUserId . "\n"
                . strtolower($clientMessageId)
        );

        return 'chat_' . substr($identityHash, 0, 24);
    }

    return videochat_chat_message_id($nowUnixMs);
}

function videochat_chat_ack_id(string $messageId): string
{
    $normalizedMessageId = trim($messageId);
    if ($normalizedMessageId === '') {
        $normalizedMessageId = 'missing';
    }

    return 'chat_ack_' . substr(hash('sha256', $normalizedMessageId), 0, 24);
}

/**
 * @return array{
 *   type: string,
 *   room_id: string,
 *   ack_id: string,
 *   message_id: string,
 *   client_message_id: ?string,
 *   server_time: string,
 *   sent_count: int,
 *   time: string
 * }
 */
function videochat_chat_ack_payload(
    string $roomId,
    array $message,
    int $sentCount,
    ?int $ackUnixMs = null
): array {
    $effectiveAckUnixMs = is_int($ackUnixMs) && $ackUnixMs > 0
        ? $ackUnixMs
        : (int) floor(microtime(true) * 1000);
    $effectiveAckIso = gmdate('c', (int) floor($effectiveAckUnixMs / 1000));
    $messageId = trim((string) ($message['id'] ?? ''));

    return [
        'type' => 'chat/ack',
        'room_id' => videochat_presence_normalize_room_id($roomId),
        'ack_id' => videochat_chat_ack_id($messageId),
        'message_id' => $messageId,
        'client_message_id' => is_string($message['client_message_id'] ?? null)
            ? trim((string) $message['client_message_id'])
            : null,
        'server_time' => is_string($message['server_time'] ?? null) && trim((string) $message['server_time']) !== ''
            ? (string) $message['server_time']
            : $effectiveAckIso,
        'sent_count' => max(0, $sentCount),
        'time' => $effectiveAckIso,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   type: string,
 *   message: string,
 *   client_message_id: ?string,
 *   error: string
 * }
 */
function videochat_chat_decode_client_frame(mixed $frame): array
{
    $decoded = function_exists('videochat_realtime_decode_client_payload')
        ? videochat_realtime_decode_client_payload($frame)
        : (is_string($frame) ? json_decode($frame, true) : (is_array($frame) ? $frame : null));
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'type' => '',
            'message' => '',
            'client_message_id' => null,
            'error' => 'invalid_json',
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return [
            'ok' => false,
            'type' => '',
            'message' => '',
            'client_message_id' => null,
            'error' => 'missing_type',
        ];
    }

    if (!in_array($type, ['chat/send', 'chat/message'], true)) {
        return [
            'ok' => false,
            'type' => $type,
            'message' => '',
            'client_message_id' => null,
            'error' => 'unsupported_type',
        ];
    }

    $rawMessage = is_string($decoded['message'] ?? null) ? (string) $decoded['message'] : '';
    $message = trim($rawMessage);
    if ($message === '') {
        return [
            'ok' => false,
            'type' => $type,
            'message' => '',
            'client_message_id' => null,
            'error' => 'empty_message',
        ];
    }

    if (strlen($message) > videochat_chat_max_bytes()) {
        return [
            'ok' => false,
            'type' => $type,
            'message' => '',
            'client_message_id' => null,
            'error' => 'message_too_large',
        ];
    }

    if (videochat_chat_message_length($message) > videochat_chat_max_chars()) {
        return [
            'ok' => false,
            'type' => $type,
            'message' => '',
            'client_message_id' => null,
            'error' => 'message_too_long',
        ];
    }

    $clientMessageId = null;
    if (is_string($decoded['client_message_id'] ?? null)) {
        $candidate = trim((string) $decoded['client_message_id']);
        if ($candidate !== '') {
            if (strlen($candidate) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $candidate) !== 1) {
                return [
                    'ok' => false,
                    'type' => $type,
                    'message' => '',
                    'client_message_id' => null,
                    'error' => 'invalid_client_message_id',
                ];
            }
            $clientMessageId = $candidate;
        }
    }

    return [
        'ok' => true,
        'type' => 'chat/send',
        'message' => $message,
        'client_message_id' => $clientMessageId,
        'error' => '',
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   error: string,
 *   event: array<string, mixed>|null,
 *   sent_count: int
 * }
 */
function videochat_chat_publish(
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
        ];
    }

    $senderUserId = (int) ($connection['user_id'] ?? 0);
    if ($senderUserId <= 0) {
        return [
            'ok' => false,
            'error' => 'invalid_sender',
            'event' => null,
            'sent_count' => 0,
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
        ];
    }

    $effectiveNowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);
    $effectiveNowIso = gmdate('c', (int) floor($effectiveNowMs / 1000));

    $event = [
        'type' => 'chat/message',
        'room_id' => $roomId,
        'message' => [
            'id' => videochat_chat_resolve_message_id($connection, $command, $roomId, $effectiveNowMs),
            'client_message_id' => $command['client_message_id'] ?? null,
            'text' => (string) ($command['message'] ?? ''),
            'sender' => [
                'user_id' => $senderUserId,
                'display_name' => (string) ($connection['display_name'] ?? ''),
                'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'user')),
            ],
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
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'event' => $event,
        'sent_count' => $sentCount,
    ];
}
