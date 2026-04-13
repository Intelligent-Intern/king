<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_chat.php';

function videochat_realtime_chat_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-chat-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_realtime_chat_last_frame(array $frames, string $socket): array
{
    $socketFrames = $frames[$socket] ?? [];
    if (!is_array($socketFrames) || $socketFrames === []) {
        return [];
    }

    $last = end($socketFrames);
    return is_array($last) ? $last : [];
}

try {
    $state = videochat_presence_state_init();
    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        if (!isset($frames[$key]) || !is_array($frames[$key])) {
            $frames[$key] = [];
        }
        $frames[$key][] = $payload;
        return true;
    };

    $adminConnection = videochat_presence_connection_descriptor(
        [
            'id' => 100,
            'display_name' => 'Admin User',
            'role' => 'admin',
        ],
        'sess-admin',
        'conn-admin',
        'socket-admin',
        'lobby',
        1_780_100_000
    );
    $adminJoin = videochat_presence_join_room($state, $adminConnection, 'lobby', $sender);
    $adminConnection = (array) ($adminJoin['connection'] ?? $adminConnection);

    $userConnection = videochat_presence_connection_descriptor(
        [
            'id' => 200,
            'display_name' => 'Call User',
            'role' => 'user',
        ],
        'sess-user',
        'conn-user',
        'socket-user',
        'lobby',
        1_780_100_020
    );
    $userJoin = videochat_presence_join_room($state, $userConnection, 'lobby', $sender);
    $userConnection = (array) ($userJoin['connection'] ?? $userConnection);

    $otherConnection = videochat_presence_connection_descriptor(
        [
            'id' => 300,
            'display_name' => 'Other Room User',
            'role' => 'user',
        ],
        'sess-other',
        'conn-other',
        'socket-other',
        'other-room',
        1_780_100_040
    );
    $otherJoin = videochat_presence_join_room($state, $otherConnection, 'other-room', $sender);
    $otherConnection = (array) ($otherJoin['connection'] ?? $otherConnection);

    $frames = [];

    $decodedSend = videochat_chat_decode_client_frame(json_encode([
        'type' => 'chat/send',
        'message' => 'Hello room from user',
        'client_message_id' => 'cmsg-001',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_chat_assert((bool) ($decodedSend['ok'] ?? false), 'valid chat/send should decode');

    $publish = videochat_chat_publish(
        $state,
        $userConnection,
        $decodedSend,
        $sender,
        1_780_100_123_000
    );
    videochat_realtime_chat_assert((bool) ($publish['ok'] ?? false), 'chat publish should succeed');
    videochat_realtime_chat_assert((int) ($publish['sent_count'] ?? 0) === 2, 'chat publish should fanout only to lobby peers');

    $adminChat = videochat_realtime_chat_last_frame($frames, 'socket-admin');
    $userChat = videochat_realtime_chat_last_frame($frames, 'socket-user');
    $otherChat = videochat_realtime_chat_last_frame($frames, 'socket-other');

    videochat_realtime_chat_assert((string) ($adminChat['type'] ?? '') === 'chat/message', 'admin should receive chat/message');
    videochat_realtime_chat_assert((string) ($userChat['type'] ?? '') === 'chat/message', 'sender should receive chat/message');
    videochat_realtime_chat_assert($otherChat === [], 'other room peer must not receive lobby chat message');
    videochat_realtime_chat_assert((string) ($adminChat['room_id'] ?? '') === 'lobby', 'chat room_id mismatch');

    $adminMessage = is_array($adminChat['message'] ?? null) ? $adminChat['message'] : [];
    $userMessage = is_array($userChat['message'] ?? null) ? $userChat['message'] : [];
    videochat_realtime_chat_assert((string) ($adminMessage['id'] ?? '') !== '', 'chat message id must be present');
    videochat_realtime_chat_assert((string) ($adminMessage['id'] ?? '') === (string) ($userMessage['id'] ?? ''), 'chat message ids must match for all recipients');
    videochat_realtime_chat_assert((string) ($adminMessage['text'] ?? '') === 'Hello room from user', 'chat message text mismatch');
    videochat_realtime_chat_assert((string) ($adminMessage['client_message_id'] ?? '') === 'cmsg-001', 'chat client_message_id mismatch');
    videochat_realtime_chat_assert((string) (($adminMessage['sender'] ?? [])['display_name'] ?? '') === 'Call User', 'chat sender display_name mismatch');
    videochat_realtime_chat_assert((int) (($adminMessage['sender'] ?? [])['user_id'] ?? 0) === 200, 'chat sender user_id mismatch');
    videochat_realtime_chat_assert((string) ($adminMessage['server_time'] ?? '') === gmdate('c', 1_780_100_123), 'chat server_time mismatch');
    videochat_realtime_chat_assert((int) ($adminMessage['server_unix_ms'] ?? 0) === 1_780_100_123_000, 'chat server_unix_ms mismatch');

    $decodedAlias = videochat_chat_decode_client_frame(json_encode([
        'type' => 'chat/message',
        'message' => 'Alias route',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_chat_assert((bool) ($decodedAlias['ok'] ?? false), 'chat/message alias should decode');
    videochat_realtime_chat_assert((string) ($decodedAlias['type'] ?? '') === 'chat/send', 'chat/message alias should normalize to chat/send');

    $decodedWhitespace = videochat_chat_decode_client_frame(json_encode([
        'type' => 'chat/send',
        'message' => '    ',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_chat_assert(!(bool) ($decodedWhitespace['ok'] ?? true), 'whitespace chat payload should fail');
    videochat_realtime_chat_assert((string) ($decodedWhitespace['error'] ?? '') === 'empty_message', 'whitespace chat error mismatch');

    $decodedTooLong = videochat_chat_decode_client_frame(json_encode([
        'type' => 'chat/send',
        'message' => str_repeat('a', videochat_chat_max_chars() + 1),
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_chat_assert(!(bool) ($decodedTooLong['ok'] ?? true), 'too long chat payload should fail');
    videochat_realtime_chat_assert((string) ($decodedTooLong['error'] ?? '') === 'message_too_long', 'too long chat error mismatch');

    $decodedInvalidClientId = videochat_chat_decode_client_frame(json_encode([
        'type' => 'chat/send',
        'message' => 'hello',
        'client_message_id' => str_repeat('x', 129),
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_chat_assert(!(bool) ($decodedInvalidClientId['ok'] ?? true), 'invalid client_message_id should fail');
    videochat_realtime_chat_assert((string) ($decodedInvalidClientId['error'] ?? '') === 'invalid_client_message_id', 'invalid client_message_id error mismatch');

    $decodedUnsupported = videochat_chat_decode_client_frame(json_encode([
        'type' => 'typing/start',
        'message' => 'hello',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_chat_assert(!(bool) ($decodedUnsupported['ok'] ?? true), 'unsupported type should fail');
    videochat_realtime_chat_assert((string) ($decodedUnsupported['error'] ?? '') === 'unsupported_type', 'unsupported type error mismatch');

    $decodedInvalidJson = videochat_chat_decode_client_frame('{invalid json');
    videochat_realtime_chat_assert(!(bool) ($decodedInvalidJson['ok'] ?? true), 'invalid JSON should fail');
    videochat_realtime_chat_assert((string) ($decodedInvalidJson['error'] ?? '') === 'invalid_json', 'invalid JSON error mismatch');

    videochat_presence_remove_connection($state, (string) ($adminConnection['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($state, (string) ($userConnection['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($state, (string) ($otherConnection['connection_id'] ?? ''), $sender);

    fwrite(STDOUT, "[realtime-chat-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-chat-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
