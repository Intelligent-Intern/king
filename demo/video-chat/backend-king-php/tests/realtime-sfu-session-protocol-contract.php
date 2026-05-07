<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_sfu_store.php';
require_once __DIR__ . '/../domain/realtime/realtime_sfu_session_protocol.php';

function videochat_realtime_sfu_session_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-sfu-session-protocol-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $gatewaySource = (string) file_get_contents(__DIR__ . '/../domain/realtime/realtime_sfu_gateway.php');
    $moduleRealtimeSource = (string) file_get_contents(__DIR__ . '/../http/module_realtime.php');
    videochat_realtime_sfu_session_assert(
        str_contains($moduleRealtimeSource, 'realtime_sfu_session_protocol.php')
        && str_contains($gatewaySource, "case 'sfu/session-hello':")
        && str_contains($gatewaySource, 'videochat_sfu_build_track_acceptance($msg'),
        'active SFU gateway must wire the session protocol helper'
    );
    $storeSource = (string) file_get_contents(__DIR__ . '/../domain/realtime/realtime_sfu_store.php');
    videochat_realtime_sfu_session_assert(
        str_contains($storeSource, "'publisher_join_started_at_ms' => ['publisher_join_started_at_ms', 'publisherJoinStartedAtMs']"),
        'SFU frame metadata must preserve publisher join start for visible-frame SLO telemetry'
    );

    $decoded = videochat_sfu_decode_client_frame(
        json_encode([
            'type' => 'sfu/session-hello',
            'room_id' => 'room-fast',
            'protocol_versions' => [1],
            'runtime_paths' => ['wlvc_sfu'],
            'codecs' => ['wlvc_wasm'],
            'media_transports' => ['websocket_binary_media_fallback'],
        ], JSON_UNESCAPED_SLASHES),
        'room-fast'
    );
    videochat_realtime_sfu_session_assert((bool) ($decoded['ok'] ?? false), 'session hello should decode through the SFU command boundary');

    $accepted = videochat_sfu_build_session_acceptance((array) ($decoded['payload'] ?? []), [
        'room_id' => 'room-fast',
        'tenant_id' => 3,
        'publisher_id' => 'sfu-pub-fast',
        'publisher_user_id' => '9',
    ]);
    videochat_realtime_sfu_session_assert((string) ($accepted['type'] ?? '') === 'sfu/session-accepted', 'session hello should produce a session-accepted payload');
    videochat_realtime_sfu_session_assert((int) ($accepted['join_visible_slo_ms'] ?? 0) === 1000, 'session acceptance should publish the one second visible-join SLO');
    videochat_realtime_sfu_session_assert((string) ((array) ($accepted['selected'] ?? []))['runtime_path'] === 'wlvc_sfu', 'session acceptance should select the WLVC SFU runtime');
    videochat_realtime_sfu_session_assert((bool) ((array) ($accepted['selected'] ?? []))['fast_first_frame'] === true, 'session acceptance should select fast first-frame delivery');

    $trackAccepted = videochat_sfu_build_track_acceptance([
        'track_id' => 'camera-fast',
        'kind' => 'video',
    ], [
        'room_id' => 'room-fast',
        'publisher_id' => 'sfu-pub-fast',
        'publisher_user_id' => '9',
    ]);
    videochat_realtime_sfu_session_assert((string) ($trackAccepted['type'] ?? '') === 'sfu/track-accepted', 'track publish should have an explicit acceptance payload');
    videochat_realtime_sfu_session_assert((int) (((array) ($trackAccepted['selected'] ?? []))['join_visible_slo_ms'] ?? 0) === 1000, 'track acceptance should retain the visible-join SLO');

    fwrite(STDOUT, "[realtime-sfu-session-protocol-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[realtime-sfu-session-protocol-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
