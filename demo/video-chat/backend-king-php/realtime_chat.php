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

/**
 * @return array{
 *   ok: bool,
 *   type: string,
 *   message: string,
 *   client_message_id: ?string,
 *   error: string
 * }
 */
function videochat_chat_decode_client_frame(string $frame): array
{
    $decoded = json_decode($frame, true);
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
            'event' => null,
            'sent_count' => 0,
        ];
    }

    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? 'lobby'));
    $effectiveNowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);
    $effectiveNowIso = gmdate('c', (int) floor($effectiveNowMs / 1000));

    $event = [
        'type' => 'chat/message',
        'room_id' => $roomId,
        'message' => [
            'id' => videochat_chat_message_id($effectiveNowMs),
            'client_message_id' => $command['client_message_id'] ?? null,
            'text' => (string) ($command['message'] ?? ''),
            'sender' => [
                'user_id' => (int) ($connection['user_id'] ?? 0),
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

    return [
        'ok' => true,
        'event' => $event,
        'sent_count' => $sentCount,
    ];
}
