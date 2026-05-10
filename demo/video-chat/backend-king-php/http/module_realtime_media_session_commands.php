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
    $capabilities = videochat_client_capabilities_normalize($payload, $presenceConnection);
    $connectionId = trim((string) ($presenceConnection['connection_id'] ?? ''));
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

    try {
        videochat_client_capabilities_upsert($openDatabase(), $presenceConnection, $capabilities);
    } catch (Throwable) {
        // Persistence is best-effort; the current room snapshot still carries
        // the in-memory capability record for this websocket generation.
    }

    videochat_presence_send_frame($websocket, [
        'type' => 'client.capabilities.v1/ack',
        'ok' => true,
        'schema_version' => videochat_client_capabilities_schema_version(),
        'client_capabilities' => videochat_client_capabilities_public_projection($capabilities),
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
