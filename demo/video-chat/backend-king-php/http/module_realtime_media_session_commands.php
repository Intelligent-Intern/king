<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/realtime_client_capabilities.php';
require_once __DIR__ . '/../domain/realtime/realtime_media_session_plan.php';

/**
 * @return array{ok: bool, type: string, payload: array<string, mixed>, error: string}
 */
function videochat_realtime_decode_client_capabilities_frame(string $frame): array
{
    $decoded = json_decode($frame, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'type' => '', 'payload' => [], 'error' => 'invalid_json'];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type !== 'client/capabilities.v1') {
        return ['ok' => false, 'type' => $type, 'payload' => [], 'error' => $type === '' ? 'missing_type' : 'unsupported_type'];
    }

    return ['ok' => true, 'type' => 'client/capabilities.v1', 'payload' => $decoded, 'error' => ''];
}

function videochat_realtime_handle_media_capabilities_websocket_command(
    array $capabilitiesCommand,
    mixed $websocket,
    array &$presenceState,
    array &$presenceConnection,
    callable $openDatabase,
    ?callable $sender = null
): ?array {
    if (!(bool) ($capabilitiesCommand['ok'] ?? false)) {
        return (string) ($capabilitiesCommand['error'] ?? '') === 'unsupported_type'
            ? null
            : videochat_realtime_secondary_invalid_result($capabilitiesCommand);
    }

    $payload = is_array($capabilitiesCommand['payload'] ?? null) ? (array) $capabilitiesCommand['payload'] : [];
    $payloadCallId = function_exists('videochat_realtime_normalize_call_id')
        ? videochat_realtime_normalize_call_id((string) ($payload['call_id'] ?? ($payload['callId'] ?? '')), '')
        : strtolower(trim((string) ($payload['call_id'] ?? ($payload['callId'] ?? ''))));
    if ($payloadCallId !== '') {
        $presenceConnection['active_call_id'] = $payloadCallId;
        if (trim((string) ($presenceConnection['requested_call_id'] ?? '')) === '') {
            $presenceConnection['requested_call_id'] = $payloadCallId;
        }
    }
    $payloadRoomId = function_exists('videochat_presence_normalize_room_id')
        ? videochat_presence_normalize_room_id((string) ($payload['room_id'] ?? ($payload['roomId'] ?? '')), '')
        : strtolower(trim((string) ($payload['room_id'] ?? ($payload['roomId'] ?? ''))));
    if ($payloadRoomId !== '') {
        $presenceConnection['room_id'] = $payloadRoomId;
    }
    $capabilities = videochat_client_capabilities_normalize($payload, $presenceConnection);
    $connectionId = trim((string) ($presenceConnection['connection_id'] ?? ''));
    $publicCapabilities = videochat_client_capabilities_public_projection($capabilities);
    $stored = false;
    $persistenceError = '';
    try {
        $stored = videochat_client_capabilities_upsert($openDatabase(), $presenceConnection, $capabilities);
        if (!$stored) {
            $persistenceError = 'capabilities_not_stored';
        }
    } catch (Throwable) {
        $persistenceError = 'capabilities_persistence_failed';
    }

    if (!$stored) {
        videochat_presence_send_frame($websocket, [
            'type' => 'client.capabilities.v1/ack',
            'ok' => false,
            'stored' => false,
            'error' => $persistenceError,
            'schema_version' => videochat_client_capabilities_schema_version(),
            'plan_epoch' => videochat_media_session_plan_epoch([
                'participants' => [[
                    'participant_session_id' => (string) ($publicCapabilities['participant_session_id'] ?? $connectionId),
                    'client_capabilities' => $publicCapabilities,
                ]],
            ]),
            'client_capabilities' => $publicCapabilities,
            'time' => gmdate('c'),
        ], $sender);

        return videochat_realtime_secondary_handled_result();
    }

    if (!isset($presenceState['client_capabilities']) || !is_array($presenceState['client_capabilities'])) {
        $presenceState['client_capabilities'] = [];
    }
    if ($connectionId !== '') {
        $presenceState['client_capabilities'][$connectionId] = $capabilities;
        if (is_array($presenceState['connections'][$connectionId] ?? null)) {
            $presenceState['connections'][$connectionId]['client_capabilities'] = $capabilities;
        }
    }
    $presenceConnection['client_capabilities'] = $capabilities;

    videochat_presence_send_frame($websocket, [
        'type' => 'client.capabilities.v1/ack',
        'ok' => true,
        'stored' => true,
        'schema_version' => videochat_client_capabilities_schema_version(),
        'plan_epoch' => videochat_media_session_plan_epoch([
            'participants' => [[
                'participant_session_id' => (string) ($publicCapabilities['participant_session_id'] ?? $connectionId),
                'client_capabilities' => $publicCapabilities,
            ]],
        ]),
        'client_capabilities' => $publicCapabilities,
        'time' => gmdate('c'),
    ], $sender);
    videochat_realtime_send_room_snapshot(
        $presenceState,
        $presenceConnection,
        $openDatabase,
        'client_capabilities',
        $sender
    );
    $mediaStateEvent = videochat_call_media_state_event($presenceConnection, $capabilities, 'client_capabilities');
    $eventRoomId = (string) ($mediaStateEvent['room_id'] ?? '');
    if ($eventRoomId !== '') {
        videochat_presence_broadcast_room_event(
            $presenceState,
            $eventRoomId,
            $mediaStateEvent,
            null,
            $sender,
            is_numeric($presenceConnection['tenant_id'] ?? null) ? (int) $presenceConnection['tenant_id'] : null
        );
    }

    return videochat_realtime_secondary_handled_result();
}
