<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/gateway_backend_mapping.php';
require_once __DIR__ . '/../domain/realtime/realtime_signaling.php';

function videochat_gateway_backend_mapping_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[gateway-backend-mapping-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_gateway_backend_mapping_backend_event(string $type, array $payload): array
{
    return [
        'type' => $type,
        'room_id' => 'call-room-1',
        'target_user_id' => 200,
        'sender' => ['user_id' => 100, 'display_name' => 'Caller'],
        'payload' => $payload,
        'signal' => ['id' => 'signal-test-1', 'server_unix_ms' => 1780300123000],
    ];
}

try {
    $offerPayload = [
        'kind' => 'webrtc_offer',
        'runtime_path' => 'webrtc_native',
        'room_id' => 'call-room-1',
        'sdp' => ['type' => 'offer', 'sdp' => 'v=0 offer'],
    ];
    $offerEnvelopeResult = videochat_gateway_backend_signal_to_amqp(
        videochat_gateway_backend_mapping_backend_event('call/offer', $offerPayload),
        ['call_id' => 'call-room-1']
    );
    videochat_gateway_backend_mapping_assert((bool) ($offerEnvelopeResult['ok'] ?? false), 'backend offer should map to AMQP');
    $offerEnvelope = is_array($offerEnvelopeResult['amqp_envelope'] ?? null) ? $offerEnvelopeResult['amqp_envelope'] : [];
    $offerAmqpPayload = is_array($offerEnvelope['payload'] ?? null) ? $offerEnvelope['payload'] : [];
    $offerMessage = is_array($offerAmqpPayload['gateway_message'] ?? null) ? $offerAmqpPayload['gateway_message'] : [];
    videochat_gateway_backend_mapping_assert((string) ($offerEnvelope['topic'] ?? '') === 'call.signaling', 'offer topic mismatch');
    videochat_gateway_backend_mapping_assert((string) ($offerEnvelope['routing_key'] ?? '') === 'call.signaling.call-room-1', 'offer routing key mismatch');
    videochat_gateway_backend_mapping_assert((string) ($offerAmqpPayload['room_id'] ?? '') === 'call-room-1', 'offer room_id mismatch');
    videochat_gateway_backend_mapping_assert((string) ($offerAmqpPayload['call_id'] ?? '') === 'call-room-1', 'offer call_id alias mismatch');
    videochat_gateway_backend_mapping_assert((string) ($offerAmqpPayload['sender_peer_id'] ?? '') === '100', 'offer sender peer mismatch');
    videochat_gateway_backend_mapping_assert((string) ($offerAmqpPayload['target_peer_id'] ?? '') === '200', 'offer target peer mismatch');
    videochat_gateway_backend_mapping_assert((string) ($offerMessage['kind'] ?? '') === 'SessionDescription', 'offer gateway kind mismatch');
    videochat_gateway_backend_mapping_assert((string) ($offerMessage['type'] ?? '') === 'offer', 'offer gateway type mismatch');
    videochat_gateway_backend_mapping_assert((string) ((($offerMessage['description'] ?? [])['sdp'] ?? '')) === 'v=0 offer', 'offer SDP mismatch');

    $roundTripOfferResult = videochat_gateway_amqp_to_backend_signal($offerEnvelope);
    videochat_gateway_backend_mapping_assert((bool) ($roundTripOfferResult['ok'] ?? false), 'gateway offer should map to backend');
    $roundTripOffer = is_array($roundTripOfferResult['backend_command'] ?? null) ? $roundTripOfferResult['backend_command'] : [];
    videochat_gateway_backend_mapping_assert((string) ($roundTripOffer['type'] ?? '') === 'call/offer', 'round-trip offer type mismatch');
    videochat_gateway_backend_mapping_assert((int) ($roundTripOffer['target_user_id'] ?? 0) === 200, 'round-trip offer target mismatch');
    videochat_gateway_backend_mapping_assert((string) ((($roundTripOffer['payload'] ?? [])['sdp'] ?? [])['type'] ?? '') === 'offer', 'round-trip offer SDP type mismatch');
    videochat_gateway_backend_mapping_assert((string) ((($roundTripOffer['payload'] ?? [])['sdp'] ?? [])['sdp'] ?? '') === 'v=0 offer', 'round-trip offer SDP mismatch');
    videochat_gateway_backend_mapping_assert((string) ((($roundTripOffer['gateway'] ?? [])['sender_user_id'] ?? '')) === '100', 'round-trip offer sender context mismatch');

    $answerResult = videochat_gateway_backend_signal_to_amqp(videochat_gateway_backend_mapping_backend_event('call/answer', [
        'kind' => 'webrtc_answer',
        'runtime_path' => 'webrtc_native',
        'room_id' => 'call-room-1',
        'sdp' => ['type' => 'answer', 'sdp' => 'v=0 answer'],
    ]));
    videochat_gateway_backend_mapping_assert((bool) ($answerResult['ok'] ?? false), 'backend answer should map to AMQP');
    $answerMessage = (($answerResult['amqp_envelope'] ?? [])['payload'] ?? [])['gateway_message'] ?? [];
    videochat_gateway_backend_mapping_assert((string) ($answerMessage['type'] ?? '') === 'answer', 'answer gateway type mismatch');

    $iceResult = videochat_gateway_backend_signal_to_amqp(videochat_gateway_backend_mapping_backend_event('call/ice', [
        'kind' => 'webrtc_ice',
        'runtime_path' => 'webrtc_native',
        'room_id' => 'call-room-1',
        'candidate' => [
            'candidate' => 'candidate:1 1 udp 2122260223 192.0.2.1 54400 typ host',
            'sdpMid' => '0',
            'sdpMLineIndex' => 0,
            'usernameFragment' => 'ufrag',
        ],
    ]));
    videochat_gateway_backend_mapping_assert((bool) ($iceResult['ok'] ?? false), 'backend ICE should map to AMQP');
    $iceMessage = (($iceResult['amqp_envelope'] ?? [])['payload'] ?? [])['gateway_message'] ?? [];
    videochat_gateway_backend_mapping_assert((string) ($iceMessage['kind'] ?? '') === 'IceCandidate', 'ICE kind mismatch');
    videochat_gateway_backend_mapping_assert((string) (($iceMessage['candidate'] ?? [])['sdp_mid'] ?? '') === '0', 'ICE sdp_mid mismatch');
    videochat_gateway_backend_mapping_assert((int) (($iceMessage['candidate'] ?? [])['sdp_m_line_index'] ?? -1) === 0, 'ICE sdp_m_line_index mismatch');
    $iceBack = videochat_gateway_amqp_to_backend_signal((array) ($iceResult['amqp_envelope'] ?? []));
    videochat_gateway_backend_mapping_assert((bool) ($iceBack['ok'] ?? false), 'gateway ICE should map back to backend');
    $iceBackCandidate = (($iceBack['backend_command'] ?? [])['payload'] ?? [])['candidate'] ?? [];
    videochat_gateway_backend_mapping_assert((string) ($iceBackCandidate['sdpMid'] ?? '') === '0', 'ICE backend sdpMid mismatch');
    videochat_gateway_backend_mapping_assert((int) ($iceBackCandidate['sdpMLineIndex'] ?? -1) === 0, 'ICE backend sdpMLineIndex mismatch');

    $hangupResult = videochat_gateway_backend_signal_to_amqp(videochat_gateway_backend_mapping_backend_event('call/hangup', [
        'kind' => 'webrtc_hangup',
        'runtime_path' => 'webrtc_native',
        'room_id' => 'call-room-1',
        'reason' => 'user_left',
    ]));
    videochat_gateway_backend_mapping_assert((bool) ($hangupResult['ok'] ?? false), 'backend hangup should map to AMQP');
    $hangupMessage = (($hangupResult['amqp_envelope'] ?? [])['payload'] ?? [])['gateway_message'] ?? [];
    videochat_gateway_backend_mapping_assert((string) ($hangupMessage['kind'] ?? '') === 'LeaveRequest', 'hangup kind mismatch');
    videochat_gateway_backend_mapping_assert((string) ($hangupMessage['reason'] ?? '') === 'user_left', 'hangup reason mismatch');

    $callIdOnlyEnvelope = $offerEnvelope;
    unset($callIdOnlyEnvelope['payload']['room_id']);
    $callIdOnlyBack = videochat_gateway_amqp_to_backend_signal($callIdOnlyEnvelope);
    videochat_gateway_backend_mapping_assert((bool) ($callIdOnlyBack['ok'] ?? false), 'call_id-only alias should map to backend');
    videochat_gateway_backend_mapping_assert((string) ((($callIdOnlyBack['backend_command'] ?? [])['payload'] ?? [])['room_id'] ?? '') === 'call-room-1', 'call_id-only alias room mismatch');

    $roomMismatchEnvelope = $offerEnvelope;
    $roomMismatchEnvelope['payload']['call_id'] = 'other-call';
    $roomMismatch = videochat_gateway_amqp_to_backend_signal($roomMismatchEnvelope);
    videochat_gateway_backend_mapping_assert(!(bool) ($roomMismatch['ok'] ?? true), 'room/call mismatch should fail');
    videochat_gateway_backend_mapping_assert((string) ($roomMismatch['reason'] ?? '') === 'room_call_mismatch', 'room/call mismatch reason mismatch');

    $payloadRoomMismatch = videochat_gateway_backend_signal_to_amqp(videochat_gateway_backend_mapping_backend_event('call/offer', [
        'kind' => 'webrtc_offer',
        'runtime_path' => 'webrtc_native',
        'room_id' => 'other-room',
        'sdp' => ['type' => 'offer', 'sdp' => 'v=0 offer'],
    ]));
    videochat_gateway_backend_mapping_assert(!(bool) ($payloadRoomMismatch['ok'] ?? true), 'backend payload room mismatch should fail');
    videochat_gateway_backend_mapping_assert((string) ($payloadRoomMismatch['reason'] ?? '') === 'payload_room_mismatch', 'payload room mismatch reason mismatch');

    $invalidTopic = videochat_gateway_amqp_to_backend_signal(['topic' => 'other.topic', 'payload' => $offerAmqpPayload]);
    videochat_gateway_backend_mapping_assert(!(bool) ($invalidTopic['ok'] ?? true), 'invalid AMQP topic should fail');
    videochat_gateway_backend_mapping_assert((string) ($invalidTopic['reason'] ?? '') === 'invalid_topic', 'invalid topic reason mismatch');

    $invalidTargetEnvelope = $offerEnvelope;
    $invalidTargetEnvelope['payload']['target_peer_id'] = 'peer-two-hundred';
    $invalidTarget = videochat_gateway_amqp_to_backend_signal($invalidTargetEnvelope);
    videochat_gateway_backend_mapping_assert(!(bool) ($invalidTarget['ok'] ?? true), 'invalid target peer should fail');
    videochat_gateway_backend_mapping_assert((string) ($invalidTarget['reason'] ?? '') === 'invalid_target_peer_id', 'invalid target peer reason mismatch');

    $unsupportedEnvelope = $offerEnvelope;
    $unsupportedEnvelope['payload']['gateway_message'] = ['kind' => 'RoomEnd', 'type' => 'room_end'];
    $unsupported = videochat_gateway_amqp_to_backend_signal($unsupportedEnvelope);
    videochat_gateway_backend_mapping_assert(!(bool) ($unsupported['ok'] ?? true), 'unsupported gateway kind should fail');
    videochat_gateway_backend_mapping_assert((string) ($unsupported['reason'] ?? '') === 'unsupported_gateway_kind', 'unsupported kind reason mismatch');

    $decodedBackendCommand = videochat_signaling_decode_client_frame(json_encode($roundTripOffer, JSON_UNESCAPED_SLASHES));
    videochat_gateway_backend_mapping_assert((bool) ($decodedBackendCommand['ok'] ?? false), 'round-trip backend command should satisfy realtime signaling decoder');
    videochat_gateway_backend_mapping_assert((string) ($decodedBackendCommand['type'] ?? '') === 'call/offer', 'decoded round-trip type mismatch');
    videochat_gateway_backend_mapping_assert((int) ($decodedBackendCommand['target_user_id'] ?? 0) === 200, 'decoded round-trip target mismatch');

    fwrite(STDOUT, "[gateway-backend-mapping-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[gateway-backend-mapping-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
