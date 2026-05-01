<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_reaction.php';

function videochat_realtime_reaction_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-reaction-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_realtime_reaction_last_frame(array $frames, string $socket): array
{
    $socketFrames = $frames[$socket] ?? [];
    if (!is_array($socketFrames) || $socketFrames === []) {
        return [];
    }

    $last = end($socketFrames);
    return is_array($last) ? $last : [];
}

try {
    putenv('VIDEOCHAT_WS_REACTION_FLOOD_WINDOW_MS=1000');
    putenv('VIDEOCHAT_WS_REACTION_FLOOD_THRESHOLD_PER_WINDOW=2');
    putenv('VIDEOCHAT_WS_REACTION_FLOOD_BATCH_SIZE=3');
    putenv('VIDEOCHAT_WS_REACTION_CLIENT_BATCH_MAX_COUNT=25');
    putenv("VIDEOCHAT_WS_REACTION_ALLOWED_EMOJIS=\u{1F44D},\u{2764}\u{FE0F}");

    $presenceState = videochat_presence_state_init();
    $reactionState = videochat_reaction_state_init();
    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        if (!isset($frames[$key]) || !is_array($frames[$key])) {
            $frames[$key] = [];
        }
        $frames[$key][] = $payload;
        return true;
    };

    $senderConnection = videochat_presence_connection_descriptor(
        [
            'id' => 100,
            'display_name' => 'Caller Admin',
            'role' => 'admin',
        ],
        'sess-sender',
        'conn-sender',
        'socket-sender',
        'lobby'
    );
    $senderJoin = videochat_presence_join_room($presenceState, $senderConnection, 'lobby', $sender);
    $senderConnection = (array) ($senderJoin['connection'] ?? $senderConnection);

    $peerConnection = videochat_presence_connection_descriptor(
        [
            'id' => 200,
            'display_name' => 'Peer User',
            'role' => 'user',
        ],
        'sess-peer',
        'conn-peer',
        'socket-peer',
        'lobby'
    );
    $peerJoin = videochat_presence_join_room($presenceState, $peerConnection, 'lobby', $sender);
    $peerConnection = (array) ($peerJoin['connection'] ?? $peerConnection);

    $otherRoomConnection = videochat_presence_connection_descriptor(
        [
            'id' => 300,
            'display_name' => 'Other Room User',
            'role' => 'user',
        ],
        'sess-other',
        'conn-other',
        'socket-other',
        'other-room'
    );
    $otherJoin = videochat_presence_join_room($presenceState, $otherRoomConnection, 'other-room', $sender);
    $otherRoomConnection = (array) ($otherJoin['connection'] ?? $otherRoomConnection);

    $frames = [];

    $validCommand = videochat_reaction_decode_client_frame(json_encode([
        'type' => 'reaction/send',
        'emoji' => "\u{1F44D}",
        'client_reaction_id' => 'rx-001',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    videochat_realtime_reaction_assert((bool) ($validCommand['ok'] ?? false), 'reaction/send should decode');

    $firstPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $senderConnection,
        $validCommand,
        $sender,
        1_780_400_000_000
    );
    videochat_realtime_reaction_assert((bool) ($firstPublish['ok'] ?? false), 'first reaction publish should succeed');
    videochat_realtime_reaction_assert((int) ($firstPublish['sent_count'] ?? 0) === 2, 'reaction publish should fanout to sender and lobby peer');
    videochat_realtime_reaction_assert((int) ($firstPublish['remaining_in_window'] ?? -1) === 1, 'remaining_in_window after first publish mismatch');

    $senderFrame = videochat_realtime_reaction_last_frame($frames, 'socket-sender');
    $peerFrame = videochat_realtime_reaction_last_frame($frames, 'socket-peer');
    $otherFrame = videochat_realtime_reaction_last_frame($frames, 'socket-other');
    videochat_realtime_reaction_assert((string) ($senderFrame['type'] ?? '') === 'reaction/event', 'sender should receive reaction/event');
    videochat_realtime_reaction_assert((string) ($peerFrame['type'] ?? '') === 'reaction/event', 'peer should receive reaction/event');
    videochat_realtime_reaction_assert($otherFrame === [], 'other room must not receive reaction/event');

    $senderReaction = is_array($senderFrame['reaction'] ?? null) ? $senderFrame['reaction'] : [];
    $peerReaction = is_array($peerFrame['reaction'] ?? null) ? $peerFrame['reaction'] : [];
    videochat_realtime_reaction_assert((string) ($senderReaction['id'] ?? '') !== '', 'reaction id must be present');
    videochat_realtime_reaction_assert((string) ($senderReaction['id'] ?? '') === (string) ($peerReaction['id'] ?? ''), 'reaction id must stay stable across recipients');
    videochat_realtime_reaction_assert((string) ($senderReaction['emoji'] ?? '') === "\u{1F44D}", 'reaction emoji mismatch');
    videochat_realtime_reaction_assert((string) ($senderReaction['client_reaction_id'] ?? '') === 'rx-001', 'reaction client_reaction_id mismatch');
    videochat_realtime_reaction_assert((string) ($senderReaction['server_time'] ?? '') === gmdate('c', 1_780_400_000), 'reaction server_time mismatch');

    $secondPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $senderConnection,
        $validCommand,
        $sender,
        1_780_400_000_250
    );
    videochat_realtime_reaction_assert((bool) ($secondPublish['ok'] ?? false), 'second reaction publish should succeed within direct budget');
    videochat_realtime_reaction_assert((int) ($secondPublish['remaining_in_window'] ?? -1) === 0, 'remaining_in_window after second publish mismatch');

    $secondSenderFrame = videochat_realtime_reaction_last_frame($frames, 'socket-sender');
    $secondSenderReaction = is_array($secondSenderFrame['reaction'] ?? null) ? $secondSenderFrame['reaction'] : [];
    videochat_realtime_reaction_assert(
        (string) ($secondSenderReaction['id'] ?? '') === (string) ($senderReaction['id'] ?? ''),
        'reaction id must remain stable for same sender+room+client_reaction_id'
    );

    $thirdPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $senderConnection,
        $validCommand,
        $sender,
        1_780_400_000_500
    );
    videochat_realtime_reaction_assert((bool) ($thirdPublish['ok'] ?? false), 'third reaction in flood window should be buffered');
    videochat_realtime_reaction_assert((int) ($thirdPublish['sent_count'] ?? -1) === 0, 'third reaction should stay buffered until flood batch fills');

    $fourthPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $senderConnection,
        $validCommand,
        $sender,
        1_780_400_000_600
    );
    videochat_realtime_reaction_assert((bool) ($fourthPublish['ok'] ?? false), 'fourth reaction in flood window should be buffered');
    videochat_realtime_reaction_assert((int) ($fourthPublish['sent_count'] ?? -1) === 0, 'fourth reaction should stay buffered until flood batch fills');

    $fifthPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $senderConnection,
        $validCommand,
        $sender,
        1_780_400_000_700
    );
    videochat_realtime_reaction_assert((bool) ($fifthPublish['ok'] ?? false), 'fifth reaction should flush flood batch');
    videochat_realtime_reaction_assert((int) ($fifthPublish['sent_count'] ?? 0) === 2, 'fifth reaction should broadcast one flood batch frame');

    $senderBatchFrame = videochat_realtime_reaction_last_frame($frames, 'socket-sender');
    $peerBatchFrame = videochat_realtime_reaction_last_frame($frames, 'socket-peer');
    videochat_realtime_reaction_assert((string) ($senderBatchFrame['type'] ?? '') === 'reaction/batch', 'sender should receive reaction/batch after flood threshold');
    videochat_realtime_reaction_assert((string) ($peerBatchFrame['type'] ?? '') === 'reaction/batch', 'peer should receive reaction/batch after flood threshold');
    $senderBatchMeta = is_array($senderBatchFrame['batch'] ?? null) ? $senderBatchFrame['batch'] : [];
    $senderBatchRows = is_array($senderBatchFrame['reactions'] ?? null) ? $senderBatchFrame['reactions'] : [];
    videochat_realtime_reaction_assert((int) ($senderBatchMeta['size'] ?? 0) === 3, 'flood batch size should match configured batch size');
    videochat_realtime_reaction_assert(count($senderBatchRows) === 3, 'reaction/batch should include configured chunk size');

    $peerCommand = videochat_reaction_decode_client_frame(json_encode([
        'type' => 'reaction/send',
        'emoji' => "\u{2764}\u{FE0F}",
        'client_reaction_id' => 'peer-001',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    videochat_realtime_reaction_assert((bool) ($peerCommand['ok'] ?? false), 'peer reaction command should decode');

    $peerPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $peerConnection,
        $peerCommand,
        $sender,
        1_780_400_000_800
    );
    videochat_realtime_reaction_assert((bool) ($peerPublish['ok'] ?? false), 'peer reaction publish should stay responsive while sender is in flood mode');
    videochat_realtime_reaction_assert((int) ($peerPublish['sent_count'] ?? 0) === 2, 'peer reaction fanout should target active room participants only');

    $senderNotInRoom = $senderConnection;
    $senderNotInRoom['room_id'] = 'other-room';
    $senderNotInRoomPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $senderNotInRoom,
        $validCommand,
        $sender,
        1_780_400_000_850
    );
    videochat_realtime_reaction_assert(!(bool) ($senderNotInRoomPublish['ok'] ?? true), 'sender outside room must fail reaction publish');
    videochat_realtime_reaction_assert((string) ($senderNotInRoomPublish['error'] ?? '') === 'sender_not_in_room', 'sender outside room error mismatch');

    $invalidSender = $senderConnection;
    $invalidSender['user_id'] = 0;
    $invalidSenderPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $invalidSender,
        $validCommand,
        $sender,
        1_780_400_000_900
    );
    videochat_realtime_reaction_assert(!(bool) ($invalidSenderPublish['ok'] ?? true), 'invalid sender must fail reaction publish');
    videochat_realtime_reaction_assert((string) ($invalidSenderPublish['error'] ?? '') === 'invalid_sender', 'invalid sender reaction error mismatch');

    $validBatchCommand = videochat_reaction_decode_client_frame(json_encode([
        'type' => 'reaction/send_batch',
        'emojis' => ["\u{1F44D}", "\u{2764}\u{FE0F}"],
        'client_reaction_id' => 'batch-001',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    videochat_realtime_reaction_assert((bool) ($validBatchCommand['ok'] ?? false), 'reaction/send_batch should decode');
    $decodedBatchEmojis = is_array($validBatchCommand['emojis'] ?? null) ? $validBatchCommand['emojis'] : [];
    videochat_realtime_reaction_assert(count($decodedBatchEmojis) === 2, 'decoded reaction/send_batch emoji count mismatch');

    $dropSender = static function (mixed $socket, array $payload): bool {
        return false;
    };
    $deliveryFailed = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $peerConnection,
        $peerCommand,
        $dropSender,
        1_780_400_001_500
    );
    videochat_realtime_reaction_assert(!(bool) ($deliveryFailed['ok'] ?? true), 'reaction publish should fail when delivery fails');
    videochat_realtime_reaction_assert((string) ($deliveryFailed['error'] ?? '') === 'delivery_failed', 'delivery failed reaction error mismatch');

    $unsupportedEmoji = videochat_reaction_decode_client_frame(json_encode([
        'type' => 'reaction/send',
        'emoji' => "\u{1F44E}",
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    videochat_realtime_reaction_assert(!(bool) ($unsupportedEmoji['ok'] ?? true), 'unsupported emoji must fail decode');
    videochat_realtime_reaction_assert((string) ($unsupportedEmoji['error'] ?? '') === 'unsupported_emoji', 'unsupported emoji error mismatch');

    $invalidClientReactionId = videochat_reaction_decode_client_frame(json_encode([
        'type' => 'reaction/send',
        'emoji' => "\u{1F44D}",
        'client_reaction_id' => str_repeat('x', 129),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    videochat_realtime_reaction_assert(!(bool) ($invalidClientReactionId['ok'] ?? true), 'invalid client_reaction_id must fail decode');
    videochat_realtime_reaction_assert((string) ($invalidClientReactionId['error'] ?? '') === 'invalid_client_reaction_id', 'invalid client_reaction_id error mismatch');

    $tooLargeBatch = videochat_reaction_decode_client_frame(json_encode([
        'type' => 'reaction/send_batch',
        'emojis' => array_fill(0, 26, "\u{1F44D}"),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    videochat_realtime_reaction_assert(!(bool) ($tooLargeBatch['ok'] ?? true), 'reaction/send_batch over max count must fail decode');
    videochat_realtime_reaction_assert((string) ($tooLargeBatch['error'] ?? '') === 'batch_too_large', 'reaction/send_batch batch_too_large mismatch');

    putenv('VIDEOCHAT_WS_REACTION_MAX_CHARS=1');
    $emojiTooLong = videochat_reaction_decode_client_frame(json_encode([
        'type' => 'reaction/send',
        'emoji' => "\u{2764}\u{FE0F}",
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    videochat_realtime_reaction_assert(!(bool) ($emojiTooLong['ok'] ?? true), 'emoji over char limit must fail decode');
    videochat_realtime_reaction_assert((string) ($emojiTooLong['error'] ?? '') === 'emoji_too_long', 'emoji_too_long error mismatch');
    putenv('VIDEOCHAT_WS_REACTION_MAX_CHARS=8');

    putenv('VIDEOCHAT_WS_REACTION_MAX_BYTES=4');
    $emojiTooLarge = videochat_reaction_decode_client_frame(json_encode([
        'type' => 'reaction/send',
        'emoji' => "\u{2764}\u{FE0F}",
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    videochat_realtime_reaction_assert(!(bool) ($emojiTooLarge['ok'] ?? true), 'emoji over byte limit must fail decode');
    videochat_realtime_reaction_assert((string) ($emojiTooLarge['error'] ?? '') === 'emoji_too_large', 'emoji_too_large error mismatch');
    putenv('VIDEOCHAT_WS_REACTION_MAX_BYTES=32');

    $unsupportedType = videochat_reaction_decode_client_frame(json_encode([
        'type' => 'typing/start',
        'emoji' => "\u{1F44D}",
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    videochat_realtime_reaction_assert(!(bool) ($unsupportedType['ok'] ?? true), 'unsupported reaction type must fail decode');
    videochat_realtime_reaction_assert((string) ($unsupportedType['error'] ?? '') === 'unsupported_type', 'unsupported reaction type error mismatch');

    $invalidJson = videochat_reaction_decode_client_frame('{invalid json');
    videochat_realtime_reaction_assert(!(bool) ($invalidJson['ok'] ?? true), 'invalid json reaction frame must fail decode');
    videochat_realtime_reaction_assert((string) ($invalidJson['error'] ?? '') === 'invalid_json', 'invalid json reaction error mismatch');

    $cleared = videochat_reaction_clear_for_connection($reactionState, $senderConnection);
    videochat_realtime_reaction_assert((bool) $cleared, 'reaction clear_for_connection should clear sender flood state');
    $postClearPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $senderConnection,
        $validCommand,
        $sender,
        1_780_400_001_800
    );
    videochat_realtime_reaction_assert((bool) ($postClearPublish['ok'] ?? false), 'reaction publish should recover after clear_for_connection');

    $brokerPdo = new PDO('sqlite::memory:');
    $brokerPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    videochat_reaction_broker_bootstrap($brokerPdo);

    $brokerPresenceState = videochat_presence_state_init();
    $brokerReactionState = videochat_reaction_state_init();
    $brokerFrames = [];
    $brokerJoinSender = static function (mixed $socket, array $payload) use (&$brokerFrames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        if (!isset($brokerFrames[$key]) || !is_array($brokerFrames[$key])) {
            $brokerFrames[$key] = [];
        }
        $brokerFrames[$key][] = $payload;
        return true;
    };
    $brokerSenderConnection = videochat_presence_connection_descriptor(
        [
            'id' => 100,
            'display_name' => 'Caller Admin',
            'role' => 'admin',
        ],
        'sess-broker-sender',
        'conn-broker-sender',
        'socket-broker-sender',
        'broker-room'
    );
    $brokerSenderJoin = videochat_presence_join_room($brokerPresenceState, $brokerSenderConnection, 'broker-room', $brokerJoinSender);
    $brokerSenderConnection = (array) ($brokerSenderJoin['connection'] ?? $brokerSenderConnection);
    $brokerPeerConnection = videochat_presence_connection_descriptor(
        [
            'id' => 200,
            'display_name' => 'Peer User',
            'role' => 'user',
        ],
        'sess-broker-peer',
        'conn-broker-peer',
        'socket-broker-peer',
        'broker-room'
    );
    $brokerPeerJoin = videochat_presence_join_room($brokerPresenceState, $brokerPeerConnection, 'broker-room', $brokerJoinSender);
    $brokerPeerConnection = (array) ($brokerPeerJoin['connection'] ?? $brokerPeerConnection);

    $brokerCommand = videochat_reaction_decode_client_frame(json_encode([
        'type' => 'reaction/send',
        'emoji' => "\u{1F44D}",
        'client_reaction_id' => 'broker-001',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $brokerPublish = videochat_reaction_publish(
        $brokerReactionState,
        $brokerPresenceState,
        $brokerSenderConnection,
        $brokerCommand,
        null,
        1_780_400_002_000,
        static function (string $roomId, array $event) use ($brokerPdo): bool {
            return videochat_reaction_broker_insert_event($brokerPdo, $roomId, $event);
        }
    );
    videochat_realtime_reaction_assert((bool) ($brokerPublish['ok'] ?? false), 'broker reaction publish should succeed');
    videochat_realtime_reaction_assert((int) ($brokerPublish['sent_count'] ?? 0) === 1, 'broker publish should count persisted fanout event');

    $brokerLatestId = videochat_reaction_broker_latest_event_id($brokerPdo, 'broker-room');
    videochat_realtime_reaction_assert($brokerLatestId > 0, 'broker latest event id should advance after publish');
    $brokerPeerRows = videochat_reaction_broker_fetch_events_since(
        $brokerPdo,
        'broker-room',
        0,
        (int) ($brokerPeerConnection['user_id'] ?? 0)
    );
    videochat_realtime_reaction_assert(count($brokerPeerRows) === 1, 'broker peer should fetch one cross-worker reaction event');
    $brokerSenderRows = videochat_reaction_broker_fetch_events_since(
        $brokerPdo,
        'broker-room',
        0,
        (int) ($brokerSenderConnection['user_id'] ?? 0)
    );
    videochat_realtime_reaction_assert(count($brokerSenderRows) === 0, 'broker sender should not receive its own persisted echo');
    $brokerPeerEvent = json_decode((string) ($brokerPeerRows[0]['event_json'] ?? ''), true);
    videochat_realtime_reaction_assert(is_array($brokerPeerEvent), 'broker peer event json should decode');
    videochat_realtime_reaction_assert((string) ($brokerPeerEvent['type'] ?? '') === 'reaction/event', 'broker peer event type mismatch');
    $brokerPeerReaction = is_array($brokerPeerEvent['reaction'] ?? null) ? $brokerPeerEvent['reaction'] : [];
    videochat_realtime_reaction_assert((string) ($brokerPeerReaction['emoji'] ?? '') === "\u{1F44D}", 'broker peer reaction emoji mismatch');
    videochat_realtime_reaction_assert((string) ($brokerPeerReaction['client_reaction_id'] ?? '') === 'broker-001', 'broker peer client_reaction_id mismatch');

    videochat_presence_remove_connection($presenceState, (string) ($senderConnection['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($peerConnection['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($otherRoomConnection['connection_id'] ?? ''), $sender);

    fwrite(STDOUT, "[realtime-reaction-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-reaction-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
