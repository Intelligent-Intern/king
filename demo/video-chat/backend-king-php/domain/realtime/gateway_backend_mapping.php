<?php

declare(strict_types=1);

function videochat_gateway_mapping_string(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    return trim((string) $value);
}

function videochat_gateway_mapping_normalize_room_id(mixed $value): string
{
    $normalized = videochat_gateway_mapping_string($value);
    if ($normalized !== '' && preg_match('/^[A-Za-z0-9._-]{1,200}$/', $normalized) === 1) {
        return $normalized;
    }

    return '';
}

function videochat_gateway_mapping_normalize_user_id(mixed $value): int
{
    if (is_int($value)) {
        return $value > 0 ? $value : 0;
    }

    $candidate = videochat_gateway_mapping_string($value);
    if ($candidate === '' || preg_match('/^[0-9]+$/', $candidate) !== 1) {
        return 0;
    }

    $id = (int) $candidate;
    return $id > 0 ? $id : 0;
}

function videochat_gateway_mapping_error(string $reason, array $details = []): array
{
    return [
        'ok' => false,
        'reason' => $reason,
        'backend_command' => null,
        'amqp_envelope' => null,
        'details' => $details,
    ];
}

function videochat_gateway_mapping_backend_type_to_gateway_kind(string $backendType): array
{
    return match ($backendType) {
        'call/offer' => ['kind' => 'SessionDescription', 'description_type' => 'offer'],
        'call/answer' => ['kind' => 'SessionDescription', 'description_type' => 'answer'],
        'call/ice' => ['kind' => 'IceCandidate', 'description_type' => 'candidate'],
        'call/hangup' => ['kind' => 'LeaveRequest', 'description_type' => 'leave'],
        default => [],
    };
}

function videochat_gateway_mapping_description_payload(array $payload, string $descriptionType): array
{
    $sdpPayload = is_array($payload['sdp'] ?? null) ? $payload['sdp'] : [];
    $sdp = videochat_gateway_mapping_string($sdpPayload['sdp'] ?? '');
    $type = strtolower(videochat_gateway_mapping_string($sdpPayload['type'] ?? $descriptionType));
    if ($type !== $descriptionType || $sdp === '') {
        return ['ok' => false, 'reason' => 'invalid_session_description'];
    }

    return [
        'ok' => true,
        'message' => [
            'kind' => 'SessionDescription',
            'type' => $descriptionType,
            'description' => [
                'type' => $descriptionType,
                'sdp' => $sdp,
            ],
        ],
    ];
}

function videochat_gateway_mapping_candidate_payload(array $payload): array
{
    $candidatePayload = is_array($payload['candidate'] ?? null) ? $payload['candidate'] : [];
    $candidate = videochat_gateway_mapping_string($candidatePayload['candidate'] ?? '');
    if ($candidate === '') {
        return ['ok' => false, 'reason' => 'invalid_ice_candidate'];
    }

    $sdpMid = videochat_gateway_mapping_string($candidatePayload['sdpMid'] ?? ($candidatePayload['sdp_mid'] ?? ''));
    $rawSdpMLineIndex = $candidatePayload['sdpMLineIndex'] ?? ($candidatePayload['sdp_m_line_index'] ?? null);
    $sdpMLineIndex = is_numeric($rawSdpMLineIndex) ? (int) $rawSdpMLineIndex : null;
    $usernameFragment = videochat_gateway_mapping_string(
        $candidatePayload['usernameFragment'] ?? ($candidatePayload['username_fragment'] ?? '')
    );

    $normalizedCandidate = ['candidate' => $candidate];
    if ($sdpMid !== '') {
        $normalizedCandidate['sdp_mid'] = $sdpMid;
    }
    if ($sdpMLineIndex !== null) {
        $normalizedCandidate['sdp_m_line_index'] = $sdpMLineIndex;
    }
    if ($usernameFragment !== '') {
        $normalizedCandidate['username_fragment'] = $usernameFragment;
    }

    return [
        'ok' => true,
        'message' => [
            'kind' => 'IceCandidate',
            'type' => 'candidate',
            'candidate' => $normalizedCandidate,
        ],
    ];
}

function videochat_gateway_mapping_leave_payload(array $payload): array
{
    $reason = videochat_gateway_mapping_string($payload['reason'] ?? 'hangup');
    if ($reason === '') {
        $reason = 'hangup';
    }

    return [
        'ok' => true,
        'message' => [
            'kind' => 'LeaveRequest',
            'type' => 'leave',
            'reason' => $reason,
        ],
    ];
}

function videochat_gateway_mapping_payload_to_gateway_message(string $backendType, mixed $payload): array
{
    $mapping = videochat_gateway_mapping_backend_type_to_gateway_kind($backendType);
    if ($mapping === []) {
        return ['ok' => false, 'reason' => 'unsupported_backend_type'];
    }

    $payloadArray = is_array($payload) ? $payload : [];
    return match ($backendType) {
        'call/offer', 'call/answer' => videochat_gateway_mapping_description_payload(
            $payloadArray,
            (string) ($mapping['description_type'] ?? '')
        ),
        'call/ice' => videochat_gateway_mapping_candidate_payload($payloadArray),
        'call/hangup' => videochat_gateway_mapping_leave_payload($payloadArray),
        default => ['ok' => false, 'reason' => 'unsupported_backend_type'],
    };
}

function videochat_gateway_mapping_sender_user_id(array $backendSignal, array $context): int
{
    $sender = is_array($backendSignal['sender'] ?? null) ? $backendSignal['sender'] : [];
    $id = videochat_gateway_mapping_normalize_user_id($sender['user_id'] ?? null);
    if ($id > 0) {
        return $id;
    }

    return videochat_gateway_mapping_normalize_user_id($context['sender_user_id'] ?? null);
}

/**
 * @return array{ok: bool, reason: string, backend_command: null, amqp_envelope: ?array<string, mixed>, details: array<string, mixed>}
 */
function videochat_gateway_backend_signal_to_amqp(array $backendSignal, array $context = []): array
{
    $backendType = strtolower(videochat_gateway_mapping_string($backendSignal['type'] ?? ''));
    $typeMapping = videochat_gateway_mapping_backend_type_to_gateway_kind($backendType);
    if ($typeMapping === []) {
        return videochat_gateway_mapping_error('unsupported_backend_type', ['type' => $backendType]);
    }

    $roomId = videochat_gateway_mapping_normalize_room_id($backendSignal['room_id'] ?? ($context['room_id'] ?? ''));
    $payload = $backendSignal['payload'] ?? null;
    if ($roomId === '' && is_array($payload)) {
        $roomId = videochat_gateway_mapping_normalize_room_id($payload['room_id'] ?? '');
    }
    if ($roomId === '') {
        return videochat_gateway_mapping_error('missing_room_id');
    }

    $callId = videochat_gateway_mapping_normalize_room_id($backendSignal['call_id'] ?? ($context['call_id'] ?? $roomId));
    if ($callId === '') {
        $callId = $roomId;
    }
    if ($callId !== $roomId) {
        return videochat_gateway_mapping_error('room_call_mismatch', ['room_id' => $roomId, 'call_id' => $callId]);
    }

    if (is_array($payload)) {
        $payloadRoomId = videochat_gateway_mapping_normalize_room_id($payload['room_id'] ?? '');
        if ($payloadRoomId !== '' && $payloadRoomId !== $roomId) {
            return videochat_gateway_mapping_error('payload_room_mismatch', [
                'room_id' => $roomId,
                'payload_room_id' => $payloadRoomId,
            ]);
        }
    }

    $senderUserId = videochat_gateway_mapping_sender_user_id($backendSignal, $context);
    $targetUserId = videochat_gateway_mapping_normalize_user_id($backendSignal['target_user_id'] ?? null);
    if ($senderUserId <= 0) {
        return videochat_gateway_mapping_error('invalid_sender_user_id');
    }
    if ($targetUserId <= 0) {
        return videochat_gateway_mapping_error('invalid_target_user_id');
    }
    if ($senderUserId === $targetUserId) {
        return videochat_gateway_mapping_error('self_target_forbidden');
    }

    $gatewayMessageResult = videochat_gateway_mapping_payload_to_gateway_message($backendType, $payload);
    if (!(bool) ($gatewayMessageResult['ok'] ?? false)) {
        return videochat_gateway_mapping_error((string) ($gatewayMessageResult['reason'] ?? 'invalid_gateway_message'));
    }

    $signal = is_array($backendSignal['signal'] ?? null) ? $backendSignal['signal'] : [];
    $signalId = videochat_gateway_mapping_string($signal['id'] ?? ($context['signal_id'] ?? ''));
    $serverUnixMs = is_numeric($signal['server_unix_ms'] ?? null) ? (int) $signal['server_unix_ms'] : 0;

    return [
        'ok' => true,
        'reason' => 'ok',
        'backend_command' => null,
        'amqp_envelope' => [
            'topic' => 'call.signaling',
            'routing_key' => 'call.signaling.' . $roomId,
            'payload' => [
                'schema' => 'king.videochat.gateway.signaling.v1',
                'room_id' => $roomId,
                'call_id' => $roomId,
                'backend_type' => $backendType,
                'sender_peer_id' => (string) $senderUserId,
                'target_peer_id' => (string) $targetUserId,
                'signal_id' => $signalId,
                'server_unix_ms' => $serverUnixMs,
                'gateway_message' => $gatewayMessageResult['message'] ?? [],
            ],
        ],
        'details' => [],
    ];
}

function videochat_gateway_mapping_gateway_message_to_backend_type(array $message): string
{
    $kind = videochat_gateway_mapping_string($message['kind'] ?? '');
    $type = strtolower(videochat_gateway_mapping_string($message['type'] ?? ''));

    if ($kind === 'SessionDescription' && $type === 'offer') {
        return 'call/offer';
    }
    if ($kind === 'SessionDescription' && $type === 'answer') {
        return 'call/answer';
    }
    if ($kind === 'IceCandidate' && $type === 'candidate') {
        return 'call/ice';
    }
    if ($kind === 'LeaveRequest' && in_array($type, ['leave', 'hangup'], true)) {
        return 'call/hangup';
    }

    return '';
}

function videochat_gateway_mapping_backend_payload_from_gateway_message(array $message, string $backendType, string $roomId): array
{
    if ($backendType === 'call/offer' || $backendType === 'call/answer') {
        $description = is_array($message['description'] ?? null) ? $message['description'] : [];
        $type = $backendType === 'call/offer' ? 'offer' : 'answer';
        $sdp = videochat_gateway_mapping_string($description['sdp'] ?? '');
        if ($sdp === '') {
            return ['ok' => false, 'reason' => 'invalid_session_description'];
        }

        return [
            'ok' => true,
            'payload' => [
                'kind' => 'webrtc_' . $type,
                'runtime_path' => 'webrtc_native',
                'room_id' => $roomId,
                'sdp' => [
                    'type' => $type,
                    'sdp' => $sdp,
                ],
            ],
        ];
    }

    if ($backendType === 'call/ice') {
        $candidate = is_array($message['candidate'] ?? null) ? $message['candidate'] : [];
        $candidateValue = videochat_gateway_mapping_string($candidate['candidate'] ?? '');
        if ($candidateValue === '') {
            return ['ok' => false, 'reason' => 'invalid_ice_candidate'];
        }

        $backendCandidate = ['candidate' => $candidateValue];
        $sdpMid = videochat_gateway_mapping_string($candidate['sdp_mid'] ?? ($candidate['sdpMid'] ?? ''));
        if ($sdpMid !== '') {
            $backendCandidate['sdpMid'] = $sdpMid;
        }
        $rawSdpMLineIndex = $candidate['sdp_m_line_index'] ?? ($candidate['sdpMLineIndex'] ?? null);
        if (is_numeric($rawSdpMLineIndex)) {
            $backendCandidate['sdpMLineIndex'] = (int) $rawSdpMLineIndex;
        }
        $usernameFragment = videochat_gateway_mapping_string(
            $candidate['username_fragment'] ?? ($candidate['usernameFragment'] ?? '')
        );
        if ($usernameFragment !== '') {
            $backendCandidate['usernameFragment'] = $usernameFragment;
        }

        return [
            'ok' => true,
            'payload' => [
                'kind' => 'webrtc_ice',
                'runtime_path' => 'webrtc_native',
                'room_id' => $roomId,
                'candidate' => $backendCandidate,
            ],
        ];
    }

    if ($backendType === 'call/hangup') {
        $reason = videochat_gateway_mapping_string($message['reason'] ?? 'hangup');
        return [
            'ok' => true,
            'payload' => [
                'kind' => 'webrtc_hangup',
                'runtime_path' => 'webrtc_native',
                'room_id' => $roomId,
                'reason' => $reason === '' ? 'hangup' : $reason,
            ],
        ];
    }

    return ['ok' => false, 'reason' => 'unsupported_backend_type'];
}

/**
 * @return array{ok: bool, reason: string, backend_command: ?array<string, mixed>, amqp_envelope: null, details: array<string, mixed>}
 */
function videochat_gateway_amqp_to_backend_signal(array $amqpEnvelope): array
{
    $topic = videochat_gateway_mapping_string($amqpEnvelope['topic'] ?? '');
    if ($topic !== 'call.signaling') {
        return videochat_gateway_mapping_error('invalid_topic', ['topic' => $topic]);
    }

    $payload = is_array($amqpEnvelope['payload'] ?? null) ? $amqpEnvelope['payload'] : [];
    $roomId = videochat_gateway_mapping_normalize_room_id($payload['room_id'] ?? '');
    $callId = videochat_gateway_mapping_normalize_room_id($payload['call_id'] ?? $roomId);
    if ($roomId === '' && $callId !== '') {
        $roomId = $callId;
    }
    if ($roomId === '') {
        return videochat_gateway_mapping_error('missing_room_id');
    }
    if ($callId === '') {
        $callId = $roomId;
    }
    if ($callId !== $roomId) {
        return videochat_gateway_mapping_error('room_call_mismatch', ['room_id' => $roomId, 'call_id' => $callId]);
    }

    $senderUserId = videochat_gateway_mapping_normalize_user_id($payload['sender_peer_id'] ?? null);
    $targetUserId = videochat_gateway_mapping_normalize_user_id($payload['target_peer_id'] ?? null);
    if ($senderUserId <= 0) {
        return videochat_gateway_mapping_error('invalid_sender_peer_id');
    }
    if ($targetUserId <= 0) {
        return videochat_gateway_mapping_error('invalid_target_peer_id');
    }
    if ($senderUserId === $targetUserId) {
        return videochat_gateway_mapping_error('self_target_forbidden');
    }

    $message = is_array($payload['gateway_message'] ?? null) ? $payload['gateway_message'] : [];
    $backendType = videochat_gateway_mapping_gateway_message_to_backend_type($message);
    if ($backendType === '') {
        return videochat_gateway_mapping_error('unsupported_gateway_kind', ['gateway_message' => $message]);
    }

    $payloadResult = videochat_gateway_mapping_backend_payload_from_gateway_message($message, $backendType, $roomId);
    if (!(bool) ($payloadResult['ok'] ?? false)) {
        return videochat_gateway_mapping_error((string) ($payloadResult['reason'] ?? 'invalid_gateway_payload'));
    }

    return [
        'ok' => true,
        'reason' => 'ok',
        'backend_command' => [
            'ok' => true,
            'type' => $backendType,
            'target_user_id' => $targetUserId,
            'payload' => $payloadResult['payload'] ?? null,
            'error' => '',
            'gateway' => [
                'topic' => $topic,
                'room_id' => $roomId,
                'call_id' => $roomId,
                'sender_user_id' => $senderUserId,
                'signal_id' => videochat_gateway_mapping_string($payload['signal_id'] ?? ''),
            ],
        ],
        'amqp_envelope' => null,
        'details' => [],
    ];
}
