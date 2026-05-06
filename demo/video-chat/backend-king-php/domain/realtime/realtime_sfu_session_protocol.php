<?php

declare(strict_types=1);

function videochat_sfu_session_protocol_name(): string
{
    return 'king_sfu_session_v1';
}

function videochat_sfu_session_protocol_version(): int
{
    return 1;
}

function videochat_sfu_join_visible_slo_ms(): int
{
    return 1000;
}

/**
 * @return list<string>
 */
function videochat_sfu_session_capability_list(mixed $value): array
{
    $items = [];
    $values = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
    if (!is_array($values)) {
        return [];
    }

    foreach ($values as $entry) {
        $normalized = strtolower(trim((string) $entry));
        if ($normalized === '' || strlen($normalized) > 96) {
            continue;
        }
        $items[$normalized] = $normalized;
    }

    return array_values($items);
}

/**
 * @return list<int>
 */
function videochat_sfu_session_capability_ints(mixed $value): array
{
    $items = [];
    $values = is_array($value) ? $value : [$value];
    foreach ($values as $entry) {
        $version = (int) $entry;
        if ($version > 0 && $version <= videochat_sfu_session_protocol_version()) {
            $items[$version] = $version;
        }
    }

    return array_values($items);
}

/**
 * @param list<string> $clientValues
 * @param list<string> $serverValues
 */
function videochat_sfu_session_select_value(array $clientValues, array $serverValues, string $fallback): string
{
    if ($clientValues === []) {
        return $fallback;
    }

    foreach ($serverValues as $serverValue) {
        if (in_array($serverValue, $clientValues, true)) {
            return $serverValue;
        }
    }

    return $fallback;
}

/**
 * @return array<string, mixed>
 */
function videochat_sfu_build_session_acceptance(array $msg, array $context): array
{
    $protocolVersions = videochat_sfu_session_capability_ints($msg['protocol_versions'] ?? ($msg['protocolVersions'] ?? [1]));
    $runtimePaths = videochat_sfu_session_capability_list($msg['runtime_paths'] ?? ($msg['runtimePaths'] ?? []));
    $codecs = videochat_sfu_session_capability_list($msg['codecs'] ?? []);
    $mediaTransports = videochat_sfu_session_capability_list($msg['media_transports'] ?? ($msg['mediaTransports'] ?? []));
    $features = videochat_sfu_session_capability_list($msg['features'] ?? []);

    $serverRuntimePaths = ['wlvc_sfu', 'webrtc_native'];
    $serverCodecs = ['wlvc_wasm', 'wlvc_ts', 'wlvc_unknown'];
    $serverMediaTransports = ['websocket_binary_media_fallback'];

    return [
        'type' => 'sfu/session-accepted',
        'session_protocol' => videochat_sfu_session_protocol_name(),
        'protocol_version' => $protocolVersions !== [] ? max($protocolVersions) : 1,
        'room_id' => (string) ($context['room_id'] ?? ''),
        'tenant_id' => (int) ($context['tenant_id'] ?? 0),
        'publisher_id' => (string) ($context['publisher_id'] ?? ''),
        'publisher_user_id' => (string) ($context['publisher_user_id'] ?? ''),
        'server_time_ms' => videochat_sfu_now_ms(),
        'join_visible_slo_ms' => videochat_sfu_join_visible_slo_ms(),
        'selected' => [
            'runtime_path' => videochat_sfu_session_select_value($runtimePaths, $serverRuntimePaths, 'wlvc_sfu'),
            'codec_id' => videochat_sfu_session_select_value($codecs, $serverCodecs, 'wlvc_wasm'),
            'control_transport' => function_exists('videochat_sfu_control_transport_id') ? videochat_sfu_control_transport_id() : 'websocket_sfu_control',
            'media_transport' => videochat_sfu_session_select_value($mediaTransports, $serverMediaTransports, 'websocket_binary_media_fallback'),
            'first_frame_policy' => 'keyframe_or_full_frame',
            'fast_first_frame' => true,
            'requires_track_acceptance' => false,
        ],
        'server_capabilities' => [
            'runtime_paths' => $serverRuntimePaths,
            'codecs' => $serverCodecs,
            'control_transports' => ['websocket_sfu_control'],
            'media_transports' => $serverMediaTransports,
            'track_kinds' => ['audio', 'video', 'screen'],
            'features' => ['fast_first_frame', 'keyframe_first', 'delta_after_keyframe', 'screen_as_participant', 'protected_media', 'binary_envelope'],
        ],
        'client_capabilities' => [
            'runtime_paths' => $runtimePaths,
            'codecs' => $codecs,
            'media_transports' => $mediaTransports,
            'features' => $features,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_sfu_build_track_acceptance(array $msg, array $context): array
{
    $trackId = trim((string) ($msg['track_id'] ?? ($msg['trackId'] ?? '')));
    $kind = strtolower(trim((string) ($msg['kind'] ?? 'video')));
    if (!in_array($kind, ['audio', 'video', 'screen'], true)) {
        $kind = 'video';
    }

    return [
        'type' => 'sfu/track-accepted',
        'session_protocol' => videochat_sfu_session_protocol_name(),
        'protocol_version' => videochat_sfu_session_protocol_version(),
        'room_id' => (string) ($context['room_id'] ?? ''),
        'publisher_id' => (string) ($context['publisher_id'] ?? ''),
        'publisher_user_id' => (string) ($context['publisher_user_id'] ?? ''),
        'track_id' => $trackId,
        'kind' => $kind,
        'server_time_ms' => videochat_sfu_now_ms(),
        'selected' => [
            'codec_id' => 'wlvc_wasm',
            'first_frame_policy' => 'keyframe_or_full_frame',
            'fast_first_frame' => true,
            'join_visible_slo_ms' => videochat_sfu_join_visible_slo_ms(),
        ],
    ];
}
