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
    putenv('VIDEOCHAT_WS_REACTION_THROTTLE_WINDOW_MS=1000');
    putenv('VIDEOCHAT_WS_REACTION_THROTTLE_MAX_PER_WINDOW=2');
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
    videochat_realtime_reaction_assert((bool) ($secondPublish['ok'] ?? false), 'second reaction publish should succeed within throttle budget');
    videochat_realtime_reaction_assert((int) ($secondPublish['remaining_in_window'] ?? -1) === 0, 'remaining_in_window after second publish mismatch');

    $secondSenderFrame = videochat_realtime_reaction_last_frame($frames, 'socket-sender');
    $secondSenderReaction = is_array($secondSenderFrame['reaction'] ?? null) ? $secondSenderFrame['reaction'] : [];
    videochat_realtime_reaction_assert(
        (string) ($secondSenderReaction['id'] ?? '') === (string) ($senderReaction['id'] ?? ''),
        'reaction id must remain stable for same sender+room+client_reaction_id'
    );

    $throttledPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $senderConnection,
        $validCommand,
        $sender,
        1_780_400_000_500
    );
    videochat_realtime_reaction_assert(!(bool) ($throttledPublish['ok'] ?? true), 'third reaction in throttle window must fail');
    videochat_realtime_reaction_assert((string) ($throttledPublish['error'] ?? '') === 'throttled', 'throttle error mismatch');
    videochat_realtime_reaction_assert((int) ($throttledPublish['retry_after_ms'] ?? 0) > 0, 'throttle retry_after_ms must be > 0');

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
        1_780_400_000_600
    );
    videochat_realtime_reaction_assert((bool) ($peerPublish['ok'] ?? false), 'peer reaction publish should stay responsive while sender is throttled');
    videochat_realtime_reaction_assert((int) ($peerPublish['sent_count'] ?? 0) === 2, 'peer reaction fanout should target active room participants only');

    $senderNotInRoom = $senderConnection;
    $senderNotInRoom['room_id'] = 'other-room';
    $senderNotInRoomPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $senderNotInRoom,
        $validCommand,
        $sender,
        1_780_400_000_650
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
        1_780_400_000_700
    );
    videochat_realtime_reaction_assert(!(bool) ($invalidSenderPublish['ok'] ?? true), 'invalid sender must fail reaction publish');
    videochat_realtime_reaction_assert((string) ($invalidSenderPublish['error'] ?? '') === 'invalid_sender', 'invalid sender reaction error mismatch');

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
    videochat_realtime_reaction_assert((bool) $cleared, 'reaction clear_for_connection should clear sender throttle state');
    $postClearPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $senderConnection,
        $validCommand,
        $sender,
        1_780_400_001_800
    );
    videochat_realtime_reaction_assert((bool) ($postClearPublish['ok'] ?? false), 'reaction publish should recover after clear_for_connection');

    videochat_presence_remove_connection($presenceState, (string) ($senderConnection['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($peerConnection['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($otherRoomConnection['connection_id'] ?? ''), $sender);

    fwrite(STDOUT, "[realtime-reaction-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-reaction-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
