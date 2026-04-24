<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_signaling.php';

function videochat_realtime_signaling_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-signaling-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_realtime_signaling_last_frame(array $frames, string $socket): array
{
    $socketFrames = $frames[$socket] ?? [];
    if (!is_array($socketFrames) || $socketFrames === []) {
        return [];
    }

    $last = end($socketFrames);
    return is_array($last) ? $last : [];
}

try {
    $presenceState = videochat_presence_state_init();
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

    $targetPrimary = videochat_presence_connection_descriptor(
        [
            'id' => 200,
            'display_name' => 'Target User',
            'role' => 'user',
        ],
        'sess-target-1',
        'conn-target-1',
        'socket-target-1',
        'lobby'
    );
    $targetPrimaryJoin = videochat_presence_join_room($presenceState, $targetPrimary, 'lobby', $sender);
    $targetPrimary = (array) ($targetPrimaryJoin['connection'] ?? $targetPrimary);

    $targetSecondary = videochat_presence_connection_descriptor(
        [
            'id' => 200,
            'display_name' => 'Target User',
            'role' => 'user',
        ],
        'sess-target-2',
        'conn-target-2',
        'socket-target-2',
        'lobby'
    );
    $targetSecondaryJoin = videochat_presence_join_room($presenceState, $targetSecondary, 'lobby', $sender);
    $targetSecondary = (array) ($targetSecondaryJoin['connection'] ?? $targetSecondary);

    $otherRoomTarget = videochat_presence_connection_descriptor(
        [
            'id' => 200,
            'display_name' => 'Target User Other Room',
            'role' => 'user',
        ],
        'sess-target-other',
        'conn-target-other',
        'socket-target-other',
        'other-room'
    );
    $otherRoomJoin = videochat_presence_join_room($presenceState, $otherRoomTarget, 'other-room', $sender);
    $otherRoomTarget = (array) ($otherRoomJoin['connection'] ?? $otherRoomTarget);

    $lobbyOther = videochat_presence_connection_descriptor(
        [
            'id' => 300,
            'display_name' => 'Other Lobby User',
            'role' => 'user',
        ],
        'sess-other',
        'conn-other',
        'socket-other',
        'lobby'
    );
    $lobbyOtherJoin = videochat_presence_join_room($presenceState, $lobbyOther, 'lobby', $sender);
    $lobbyOther = (array) ($lobbyOtherJoin['connection'] ?? $lobbyOther);

    $frames = [];

    $decodedOffer = videochat_signaling_decode_client_frame(json_encode([
        'type' => 'call/offer',
        'target_user_id' => 200,
        'payload' => ['sdp' => 'offer-sdp'],
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_signaling_assert((bool) ($decodedOffer['ok'] ?? false), 'call/offer should decode');
    videochat_realtime_signaling_assert((int) ($decodedOffer['target_user_id'] ?? 0) === 200, 'target_user_id decode mismatch');

    $offerPublish = videochat_signaling_publish(
        $presenceState,
        $senderConnection,
        $decodedOffer,
        $sender,
        1_780_300_123_000
    );
    videochat_realtime_signaling_assert((bool) ($offerPublish['ok'] ?? false), 'offer publish should succeed');
    videochat_realtime_signaling_assert((int) ($offerPublish['sent_count'] ?? 0) === 2, 'offer should fanout to both target lobby sockets');

    $senderFrame = videochat_realtime_signaling_last_frame($frames, 'socket-sender');
    $targetOneFrame = videochat_realtime_signaling_last_frame($frames, 'socket-target-1');
    $targetTwoFrame = videochat_realtime_signaling_last_frame($frames, 'socket-target-2');
    $targetOtherRoomFrame = videochat_realtime_signaling_last_frame($frames, 'socket-target-other');
    $lobbyOtherFrame = videochat_realtime_signaling_last_frame($frames, 'socket-other');

    videochat_realtime_signaling_assert($senderFrame === [], 'sender must not receive signaling self-echo');
    videochat_realtime_signaling_assert((string) ($targetOneFrame['type'] ?? '') === 'call/offer', 'target connection 1 should receive call/offer');
    videochat_realtime_signaling_assert((string) ($targetTwoFrame['type'] ?? '') === 'call/offer', 'target connection 2 should receive call/offer');
    videochat_realtime_signaling_assert($targetOtherRoomFrame === [], 'other-room target socket must not receive lobby signal');
    videochat_realtime_signaling_assert($lobbyOtherFrame === [], 'non-target lobby user must not receive directed signal');
    videochat_realtime_signaling_assert((string) ($targetOneFrame['room_id'] ?? '') === 'lobby', 'signal room_id mismatch');
    videochat_realtime_signaling_assert((int) ($targetOneFrame['target_user_id'] ?? 0) === 200, 'signal target_user_id mismatch');
    videochat_realtime_signaling_assert((string) (($targetOneFrame['sender'] ?? [])['display_name'] ?? '') === 'Caller Admin', 'signal sender mismatch');
    videochat_realtime_signaling_assert((string) (($targetOneFrame['payload'] ?? [])['sdp'] ?? '') === 'offer-sdp', 'signal payload mismatch');
    videochat_realtime_signaling_assert((string) (($targetOneFrame['signal'] ?? [])['server_time'] ?? '') === gmdate('c', 1_780_300_123), 'signal server_time mismatch');
    videochat_realtime_signaling_assert((string) (($targetOneFrame['signal'] ?? [])['id'] ?? '') !== '', 'signal id must be present');
    videochat_realtime_signaling_assert(
        (string) (($targetOneFrame['signal'] ?? [])['id'] ?? '') === (string) (($targetTwoFrame['signal'] ?? [])['id'] ?? ''),
        'signal id must stay stable across target connections'
    );

    $decodedAnswerAlias = videochat_signaling_decode_client_frame(json_encode([
        'type' => 'call/answer',
        'targetUserId' => '100',
        'payload' => ['sdp' => 'answer-sdp'],
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_signaling_assert((bool) ($decodedAnswerAlias['ok'] ?? false), 'call/answer should decode with targetUserId alias');
    videochat_realtime_signaling_assert((int) ($decodedAnswerAlias['target_user_id'] ?? 0) === 100, 'targetUserId alias decode mismatch');

    $answerPublish = videochat_signaling_publish(
        $presenceState,
        $targetPrimary,
        $decodedAnswerAlias,
        $sender,
        1_780_300_124_000
    );
    videochat_realtime_signaling_assert((bool) ($answerPublish['ok'] ?? false), 'answer publish should succeed');
    videochat_realtime_signaling_assert((int) ($answerPublish['sent_count'] ?? 0) === 1, 'answer should be delivered only to sender user');

    $decodedMediaSecurityHello = videochat_signaling_decode_client_frame(json_encode([
        'type' => 'media-security/hello',
        'target_user_id' => 200,
        'payload' => [
            'kind' => 'media_security_hello',
            'public_key' => 'peer-public-key',
        ],
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_signaling_assert((bool) ($decodedMediaSecurityHello['ok'] ?? false), 'media-security hello should decode');
    $mediaSecurityPublish = videochat_signaling_publish(
        $presenceState,
        $senderConnection,
        $decodedMediaSecurityHello,
        $sender,
        1_780_300_124_500
    );
    videochat_realtime_signaling_assert((bool) ($mediaSecurityPublish['ok'] ?? false), 'media-security hello publish should succeed');
    $mediaSecurityTargetFrame = videochat_realtime_signaling_last_frame($frames, 'socket-target-1');
    videochat_realtime_signaling_assert((string) ($mediaSecurityTargetFrame['type'] ?? '') === 'media-security/hello', 'target should receive media-security hello');
    videochat_realtime_signaling_assert((string) (($mediaSecurityTargetFrame['payload'] ?? [])['kind'] ?? '') === 'media_security_hello', 'media-security payload kind mismatch');

    $decodedControlState = videochat_signaling_decode_client_frame(json_encode([
        'type' => 'call/control-state',
        'target_user_id' => 200,
        'payload' => [
            'kind' => 'workspace-control-state',
            'state' => [
                'handRaised' => true,
                'cameraEnabled' => false,
            ],
        ],
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_signaling_assert((bool) ($decodedControlState['ok'] ?? false), 'call/control-state should decode');
    $controlStatePublish = videochat_signaling_publish(
        $presenceState,
        $senderConnection,
        $decodedControlState,
        $sender,
        1_780_300_124_700
    );
    videochat_realtime_signaling_assert((bool) ($controlStatePublish['ok'] ?? false), 'call/control-state publish should succeed');
    $controlStateTargetFrame = videochat_realtime_signaling_last_frame($frames, 'socket-target-1');
    videochat_realtime_signaling_assert((string) ($controlStateTargetFrame['type'] ?? '') === 'call/control-state', 'target should receive control-state');
    videochat_realtime_signaling_assert((string) (($controlStateTargetFrame['payload'] ?? [])['kind'] ?? '') === 'workspace-control-state', 'control-state payload kind mismatch');

    $decodedModerationState = videochat_signaling_decode_client_frame(json_encode([
        'type' => 'call/moderation-state',
        'target_user_id' => 200,
        'payload' => [
            'kind' => 'workspace-moderation-state',
            'moderated_users' => [
                'pin:200' => ['pinned' => true],
            ],
        ],
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_signaling_assert((bool) ($decodedModerationState['ok'] ?? false), 'call/moderation-state should decode');

    $decodedInvalidSelfTarget = videochat_signaling_decode_client_frame(json_encode([
        'type' => 'call/ice',
        'target_user_id' => 100,
        'payload' => ['candidate' => 'abc'],
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_signaling_assert((bool) ($decodedInvalidSelfTarget['ok'] ?? false), 'call/ice with self target should decode before publish checks');
    $selfTargetPublish = videochat_signaling_publish(
        $presenceState,
        $senderConnection,
        $decodedInvalidSelfTarget,
        $sender,
        1_780_300_125_000
    );
    videochat_realtime_signaling_assert(!(bool) ($selfTargetPublish['ok'] ?? true), 'self-target signaling must fail');
    videochat_realtime_signaling_assert((string) ($selfTargetPublish['error'] ?? '') === 'invalid_target_user_id', 'self-target error mismatch');

    $invalidSenderConnection = $senderConnection;
    $invalidSenderConnection['user_id'] = 0;
    $invalidSenderPublish = videochat_signaling_publish(
        $presenceState,
        $invalidSenderConnection,
        $decodedOffer,
        $sender,
        1_780_300_125_500
    );
    videochat_realtime_signaling_assert(!(bool) ($invalidSenderPublish['ok'] ?? true), 'invalid sender signaling must fail');
    videochat_realtime_signaling_assert((string) ($invalidSenderPublish['error'] ?? '') === 'invalid_sender', 'invalid sender signaling error mismatch');

    $senderNotInRoomConnection = $senderConnection;
    $senderNotInRoomConnection['room_id'] = 'other-room';
    $senderNotInRoomPublish = videochat_signaling_publish(
        $presenceState,
        $senderNotInRoomConnection,
        $decodedOffer,
        $sender,
        1_780_300_125_800
    );
    videochat_realtime_signaling_assert(!(bool) ($senderNotInRoomPublish['ok'] ?? true), 'sender outside room signaling must fail');
    videochat_realtime_signaling_assert((string) ($senderNotInRoomPublish['error'] ?? '') === 'sender_not_in_room', 'sender outside room signaling error mismatch');

    $decodedMissingTarget = videochat_signaling_decode_client_frame(json_encode([
        'type' => 'call/hangup',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_signaling_assert(!(bool) ($decodedMissingTarget['ok'] ?? true), 'missing target_user_id should fail');
    videochat_realtime_signaling_assert((string) ($decodedMissingTarget['error'] ?? '') === 'missing_target_user_id', 'missing target error mismatch');

    $decodedInvalidTarget = videochat_signaling_decode_client_frame(json_encode([
        'type' => 'call/hangup',
        'target_user_id' => 'abc',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_signaling_assert(!(bool) ($decodedInvalidTarget['ok'] ?? true), 'invalid target_user_id should fail');
    videochat_realtime_signaling_assert((string) ($decodedInvalidTarget['error'] ?? '') === 'invalid_target_user_id', 'invalid target error mismatch');

    $decodedUnsupported = videochat_signaling_decode_client_frame(json_encode([
        'type' => 'chat/send',
        'target_user_id' => 200,
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_signaling_assert(!(bool) ($decodedUnsupported['ok'] ?? true), 'unsupported signaling type should fail');
    videochat_realtime_signaling_assert((string) ($decodedUnsupported['error'] ?? '') === 'unsupported_type', 'unsupported signaling type error mismatch');

    $decodedNotInRoom = videochat_signaling_decode_client_frame(json_encode([
        'type' => 'call/hangup',
        'target_user_id' => 999,
        'payload' => null,
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_signaling_assert((bool) ($decodedNotInRoom['ok'] ?? false), 'valid hangup command should decode');
    $notInRoomPublish = videochat_signaling_publish(
        $presenceState,
        $senderConnection,
        $decodedNotInRoom,
        $sender,
        1_780_300_126_000
    );
    videochat_realtime_signaling_assert(!(bool) ($notInRoomPublish['ok'] ?? true), 'target outside room should fail signaling publish');
    videochat_realtime_signaling_assert((string) ($notInRoomPublish['error'] ?? '') === 'target_not_in_room', 'target not in room error mismatch');

    if (extension_loaded('pdo_sqlite')) {
        $brokerPdo = new PDO('sqlite::memory:');
        $brokerPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        videochat_signaling_broker_bootstrap($brokerPdo);

        $decodedBrokerOffer = videochat_signaling_decode_client_frame(json_encode([
            'type' => 'call/offer',
            'target_user_id' => 400,
            'payload' => [
                'kind' => 'webrtc_offer',
                'sdp' => ['type' => 'offer', 'sdp' => 'broker-offer-sdp'],
            ],
        ], JSON_UNESCAPED_SLASHES));
        videochat_realtime_signaling_assert((bool) ($decodedBrokerOffer['ok'] ?? false), 'broker offer command should decode');

        $brokerPublish = videochat_signaling_publish(
            $presenceState,
            $senderConnection,
            $decodedBrokerOffer,
            $sender,
            1_780_300_127_000,
            static function (string $roomId, int $targetUserId, array $event) use ($brokerPdo): bool {
                return videochat_signaling_broker_insert_event($brokerPdo, $roomId, $targetUserId, $event);
            }
        );
        videochat_realtime_signaling_assert((bool) ($brokerPublish['ok'] ?? false), 'brokered offer should succeed when target is on another worker');
        videochat_realtime_signaling_assert((int) ($brokerPublish['sent_count'] ?? -1) === 0, 'brokered offer should not claim local socket delivery');

        $brokerFrames = [];
        $brokerSender = static function (mixed $socket, array $payload) use (&$brokerFrames): bool {
            $key = is_scalar($socket) ? (string) $socket : 'unknown';
            if (!isset($brokerFrames[$key]) || !is_array($brokerFrames[$key])) {
                $brokerFrames[$key] = [];
            }
            $brokerFrames[$key][] = $payload;
            return true;
        };
        $lastBrokerEventId = 0;
        videochat_signaling_broker_poll($brokerPdo, 'socket-target-broker', 'lobby', 400, $lastBrokerEventId, $brokerSender);
        $brokerTargetFrame = videochat_realtime_signaling_last_frame($brokerFrames, 'socket-target-broker');
        videochat_realtime_signaling_assert((string) ($brokerTargetFrame['type'] ?? '') === 'call/offer', 'broker target should receive call/offer');
        videochat_realtime_signaling_assert((int) ($brokerTargetFrame['target_user_id'] ?? 0) === 400, 'broker target_user_id mismatch');
        videochat_realtime_signaling_assert((string) (($brokerTargetFrame['payload'] ?? [])['kind'] ?? '') === 'webrtc_offer', 'broker payload kind mismatch');
        videochat_realtime_signaling_assert($lastBrokerEventId > 0, 'broker poll should advance last event id');
    }

    videochat_presence_remove_connection($presenceState, (string) ($senderConnection['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($targetPrimary['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($targetSecondary['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($otherRoomTarget['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($lobbyOther['connection_id'] ?? ''), $sender);

    fwrite(STDOUT, "[realtime-signaling-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-signaling-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
