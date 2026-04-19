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

    $repeatPublish = videochat_chat_publish(
        $state,
        $userConnection,
        $decodedSend,
        $sender,
        1_780_100_124_000
    );
    videochat_realtime_chat_assert((bool) ($repeatPublish['ok'] ?? false), 'repeat chat publish with same client id should succeed');
    $repeatAdminChat = videochat_realtime_chat_last_frame($frames, 'socket-admin');
    $repeatAdminMessage = is_array($repeatAdminChat['message'] ?? null) ? $repeatAdminChat['message'] : [];
    videochat_realtime_chat_assert(
        (string) ($repeatAdminMessage['id'] ?? '') === (string) ($adminMessage['id'] ?? ''),
        'chat message id must stay stable for repeated sender+room+client_message_id'
    );

    $ackPayload = videochat_chat_ack_payload('lobby', $adminMessage, (int) ($publish['sent_count'] ?? 0), 1_780_100_124_500);
    videochat_realtime_chat_assert((string) ($ackPayload['type'] ?? '') === 'chat/ack', 'chat ack type mismatch');
    videochat_realtime_chat_assert((string) ($ackPayload['room_id'] ?? '') === 'lobby', 'chat ack room_id mismatch');
    videochat_realtime_chat_assert((string) ($ackPayload['message_id'] ?? '') === (string) ($adminMessage['id'] ?? ''), 'chat ack message_id mismatch');
    videochat_realtime_chat_assert((string) ($ackPayload['ack_id'] ?? '') !== '', 'chat ack_id must be present');
    videochat_realtime_chat_assert((int) ($ackPayload['sent_count'] ?? -1) === 2, 'chat ack sent_count mismatch');
    $ackPayloadRepeat = videochat_chat_ack_payload('lobby', $adminMessage, (int) ($publish['sent_count'] ?? 0), 1_780_100_125_000);
    videochat_realtime_chat_assert(
        (string) ($ackPayloadRepeat['ack_id'] ?? '') === (string) ($ackPayload['ack_id'] ?? ''),
        'chat ack_id must remain stable for the same message id'
    );

    $invalidSenderConnection = $userConnection;
    $invalidSenderConnection['user_id'] = 0;
    $invalidSenderPublish = videochat_chat_publish($state, $invalidSenderConnection, $decodedSend, $sender, 1_780_100_126_000);
    videochat_realtime_chat_assert(!(bool) ($invalidSenderPublish['ok'] ?? true), 'chat publish with invalid sender should fail');
    videochat_realtime_chat_assert((string) ($invalidSenderPublish['error'] ?? '') === 'invalid_sender', 'invalid sender chat error mismatch');

    $mismatchedRoomConnection = $userConnection;
    $mismatchedRoomConnection['room_id'] = 'other-room';
    $mismatchedRoomPublish = videochat_chat_publish($state, $mismatchedRoomConnection, $decodedSend, $sender, 1_780_100_127_000);
    videochat_realtime_chat_assert(!(bool) ($mismatchedRoomPublish['ok'] ?? true), 'chat publish from sender outside room should fail');
    videochat_realtime_chat_assert((string) ($mismatchedRoomPublish['error'] ?? '') === 'sender_not_in_room', 'sender outside room chat error mismatch');

    $dropSender = static function (mixed $socket, array $payload): bool {
        return false;
    };
    $deliveryFailedPublish = videochat_chat_publish($state, $userConnection, $decodedSend, $dropSender, 1_780_100_128_000);
    videochat_realtime_chat_assert(!(bool) ($deliveryFailedPublish['ok'] ?? true), 'chat publish should fail when delivery fanout fails');
    videochat_realtime_chat_assert((string) ($deliveryFailedPublish['error'] ?? '') === 'delivery_failed', 'chat delivery failed error mismatch');

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
    videochat_realtime_chat_assert((string) ($decodedTooLong['error'] ?? '') === 'chat_inline_too_large', 'too long chat error mismatch');

    $decodedAttachmentOnly = videochat_chat_decode_client_frame(json_encode([
        'type' => 'chat/send',
        'message' => '',
        'attachments' => [
            ['id' => 'att_contract_001'],
        ],
        'client_message_id' => 'cmsg-attachment-001',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_chat_assert((bool) ($decodedAttachmentOnly['ok'] ?? false), 'attachment-only chat/send should decode');
    videochat_realtime_chat_assert((string) (($decodedAttachmentOnly['attachments'] ?? [])[0] ?? '') === 'att_contract_001', 'attachment ref should normalize');

    $attachmentPublish = videochat_chat_publish(
        $state,
        $userConnection,
        $decodedAttachmentOnly,
        $sender,
        1_780_100_125_000,
        null,
        static function (array $attachmentIds, string $roomId, int $senderUserId, string $messageId, array $connection): array {
            return [
                'ok' => true,
                'error' => '',
                'attachments' => [[
                    'id' => $attachmentIds[0] ?? '',
                    'name' => 'notes.txt',
                    'content_type' => 'text/plain',
                    'size_bytes' => 128,
                    'kind' => 'text',
                    'extension' => 'txt',
                    'download_url' => '/api/calls/call-chat/chat/attachments/' . ($attachmentIds[0] ?? ''),
                    'message_id_seen' => $messageId,
                    'room_id_seen' => $roomId,
                    'sender_user_id_seen' => $senderUserId,
                ]],
            ];
        }
    );
    videochat_realtime_chat_assert((bool) ($attachmentPublish['ok'] ?? false), 'attachment chat publish should succeed');
    $attachmentAdminChat = videochat_realtime_chat_last_frame($frames, 'socket-admin');
    $attachmentMessage = is_array($attachmentAdminChat['message'] ?? null) ? $attachmentAdminChat['message'] : [];
    $attachmentRows = is_array($attachmentMessage['attachments'] ?? null) ? $attachmentMessage['attachments'] : [];
    videochat_realtime_chat_assert((string) ($attachmentMessage['text'] ?? 'missing') === '', 'attachment-only message text should stay empty');
    videochat_realtime_chat_assert(count($attachmentRows) === 1, 'attachment message should fanout one attachment metadata row');
    videochat_realtime_chat_assert((string) (($attachmentRows[0] ?? [])['id'] ?? '') === 'att_contract_001', 'attachment metadata id mismatch');
    videochat_realtime_chat_assert((string) (($attachmentRows[0] ?? [])['download_url'] ?? '') !== '', 'attachment metadata needs download url');

    $attachmentWithoutResolver = videochat_chat_publish(
        $state,
        $userConnection,
        $decodedAttachmentOnly,
        $sender,
        1_780_100_125_500
    );
    videochat_realtime_chat_assert(!(bool) ($attachmentWithoutResolver['ok'] ?? true), 'attachment publish without resolver should fail closed');
    videochat_realtime_chat_assert((string) ($attachmentWithoutResolver['error'] ?? '') === 'attachment_resolver_missing', 'missing resolver error mismatch');

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

    $brokerPdo = new PDO('sqlite::memory:');
    $brokerPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    videochat_chat_broker_bootstrap($brokerPdo);

    $brokerState = videochat_presence_state_init();
    $brokerFrames = [];
    $brokerSender = static function (mixed $socket, array $payload) use (&$brokerFrames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        if (!isset($brokerFrames[$key]) || !is_array($brokerFrames[$key])) {
            $brokerFrames[$key] = [];
        }
        $brokerFrames[$key][] = $payload;
        return true;
    };
    $brokerAdminConnection = videochat_presence_connection_descriptor(
        [
            'id' => 100,
            'display_name' => 'Admin User',
            'role' => 'admin',
        ],
        'sess-broker-admin',
        'conn-broker-admin',
        'socket-broker-admin',
        'broker-room',
        1_780_100_200
    );
    $brokerAdminJoin = videochat_presence_join_room($brokerState, $brokerAdminConnection, 'broker-room', $brokerSender);
    $brokerAdminConnection = (array) ($brokerAdminJoin['connection'] ?? $brokerAdminConnection);
    $brokerUserConnection = videochat_presence_connection_descriptor(
        [
            'id' => 200,
            'display_name' => 'Call User',
            'role' => 'user',
        ],
        'sess-broker-user',
        'conn-broker-user',
        'socket-broker-user',
        'broker-room',
        1_780_100_220
    );
    $brokerUserJoin = videochat_presence_join_room($brokerState, $brokerUserConnection, 'broker-room', $brokerSender);
    $brokerUserConnection = (array) ($brokerUserJoin['connection'] ?? $brokerUserConnection);

    $brokerCommand = videochat_chat_decode_client_frame(json_encode([
        'type' => 'chat/send',
        'message' => 'Broker fanout message',
        'client_message_id' => 'broker-chat-001',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_chat_assert((bool) ($brokerCommand['ok'] ?? false), 'broker chat command should decode');
    $brokerPublish = videochat_chat_publish(
        $brokerState,
        $brokerUserConnection,
        $brokerCommand,
        null,
        1_780_100_222_000,
        static function (string $roomId, array $event) use ($brokerPdo): bool {
            return videochat_chat_broker_insert_event($brokerPdo, $roomId, $event);
        }
    );
    videochat_realtime_chat_assert((bool) ($brokerPublish['ok'] ?? false), 'broker chat publish should succeed');
    videochat_realtime_chat_assert((int) ($brokerPublish['sent_count'] ?? 0) === 1, 'broker chat publish should count persisted fanout event');
    videochat_realtime_chat_assert(videochat_chat_broker_latest_event_id($brokerPdo, 'broker-room') > 0, 'broker chat latest event id should advance');

    $brokerRows = videochat_chat_broker_fetch_events_since($brokerPdo, 'broker-room', 0);
    videochat_realtime_chat_assert(count($brokerRows) === 1, 'broker chat fetch should return one persisted event');
    $brokerEvent = json_decode((string) ($brokerRows[0]['event_json'] ?? ''), true);
    videochat_realtime_chat_assert(is_array($brokerEvent), 'broker chat event json should decode');
    videochat_realtime_chat_assert((string) ($brokerEvent['type'] ?? '') === 'chat/message', 'broker chat event type mismatch');
    $brokerMessage = is_array($brokerEvent['message'] ?? null) ? $brokerEvent['message'] : [];
    videochat_realtime_chat_assert((string) ($brokerMessage['text'] ?? '') === 'Broker fanout message', 'broker chat message text mismatch');
    videochat_realtime_chat_assert((string) ($brokerMessage['client_message_id'] ?? '') === 'broker-chat-001', 'broker chat client_message_id mismatch');

    videochat_presence_remove_connection($state, (string) ($adminConnection['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($state, (string) ($userConnection['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($state, (string) ($otherConnection['connection_id'] ?? ''), $sender);

    fwrite(STDOUT, "[realtime-chat-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-chat-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
