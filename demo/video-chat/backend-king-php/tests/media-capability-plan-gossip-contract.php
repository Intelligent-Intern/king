<?php

declare(strict_types=1);

function videochat_media_capability_gossip_fail(string $message): never
{
    fwrite(STDERR, "[media-capability-plan-gossip-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_media_capability_gossip_assert(bool $condition, string $message): void
{
    if (!$condition) {
        videochat_media_capability_gossip_fail($message);
    }
}

function videochat_realtime_secondary_handled_result(): array
{
    return [
        'handled' => true,
        'command_type' => '',
        'command_error' => '',
    ];
}

function videochat_realtime_secondary_invalid_result(array $command): array
{
    return [
        'handled' => false,
        'command_type' => (string) ($command['type'] ?? ''),
        'command_error' => (string) ($command['error'] ?? 'unsupported_type'),
    ];
}

function videochat_presence_send_frame(mixed $socket, array $payload, ?callable $sender = null): bool
{
    if (is_callable($sender)) {
        return (bool) $sender($socket, $payload);
    }

    return true;
}

function videochat_realtime_send_room_snapshot(
    array $presenceState,
    array $presenceConnection,
    callable $openDatabase,
    string $reason,
    ?callable $sender = null
): void {
    if (is_callable($sender)) {
        $sender((string) ($presenceConnection['socket_id'] ?? ''), [
            'type' => 'room/snapshot',
            'reason' => $reason,
        ]);
    }
}

function videochat_presence_broadcast_room_event(
    array &$presenceState,
    string $roomId,
    array $event,
    mixed $exceptSocket = null,
    ?callable $sender = null,
    ?int $tenantId = null
): void {
    if (is_callable($sender)) {
        $sender('broadcast', $event);
    }
}

require_once __DIR__ . '/../http/module_realtime_media_session_commands.php';

try {
    $capabilities = videochat_client_capabilities_normalize([
        'type' => 'client/capabilities.v1',
        'schema_version' => 'king.video.client_capabilities.v1',
        'participant_session_id' => 'participant-gossip-a',
        'media' => [
            'camera' => true,
            'camera_720p30' => true,
        ],
        'runtime' => [
            'websocket' => true,
            'webrtc' => false,
            'webassembly' => false,
            'wlvc_encoder' => true,
        ],
        'constraints' => [
            'video_width' => 1280,
            'video_height' => 720,
            'video_fps' => 30,
        ],
        'token' => 'must-not-project',
        'sdp' => "v=0\r\nmust-not-project",
    ], ['connection_id' => 'conn-gossip-a']);
    $publicCapabilities = videochat_client_capabilities_public_projection($capabilities);

    $plan = videochat_media_session_plan_build([
        'call_id' => 'call-gossip',
        'room_id' => 'room-gossip',
        'previous_plan_epoch' => 7,
        'participants' => [
            [
                'participant_session_id' => 'participant-gossip-a',
                'client_capabilities' => $publicCapabilities,
                'gossip_readiness' => [
                    'topology_ready' => true,
                    'peer_ready' => true,
                    'peer_count' => 3,
                    'assigned_neighbor_count' => 2,
                    'topology_epoch' => 1_778_393_600_000,
                    'redacted' => true,
                ],
            ],
            [
                'participant_session_id' => 'participant-throttled-50',
                'client_capabilities' => $publicCapabilities,
                'media_state' => 'throttled_50',
            ],
            [
                'participant_session_id' => 'participant-throttled-25',
                'client_capabilities' => $publicCapabilities,
                'media_state' => 'throttled_25',
            ],
            [
                'participant_session_id' => 'participant-stuck',
                'client_capabilities' => $publicCapabilities,
                'media_state' => 'stuck_not_sending',
                'stuck_reason' => 'gossip_backpressure_timeout',
            ],
            [
                'participant_session_id' => 'participant-audio-only',
                'client_capabilities' => videochat_client_capabilities_public_projection(videochat_client_capabilities_normalize([
                    'participant_session_id' => 'participant-audio-only',
                    'media' => [
                        'camera' => false,
                        'microphone' => true,
                    ],
                    'runtime' => [
                        'websocket' => false,
                        'webrtc' => true,
                        'wlvc_encoder' => false,
                    ],
                ])),
            ],
            [
                'participant_session_id' => 'participant-non720-talk',
                'client_capabilities' => videochat_client_capabilities_public_projection(videochat_client_capabilities_normalize([
                    'participant_session_id' => 'participant-non720-talk',
                    'media' => [
                        'camera' => true,
                        'camera_720p30' => false,
                        'microphone' => true,
                    ],
                    'runtime' => [
                        'websocket' => false,
                        'webrtc' => true,
                        'wlvc_encoder' => false,
                    ],
                    'constraints' => [
                        'video_width' => 640,
                        'video_height' => 480,
                        'video_fps' => 15,
                    ],
                ])),
            ],
            [
                'participant_session_id' => 'participant-readiness-timeout',
                'client_capabilities' => [
                    ...$publicCapabilities,
                    'received_at' => '2026-05-10T00:00:00Z',
                ],
                'gossip_readiness' => [
                    'topology_ready' => false,
                    'peer_ready' => false,
                    'peer_count' => 1,
                    'assigned_neighbor_count' => 0,
                    'topology_epoch' => 1_778_393_600_000,
                    'reason' => 'gossip_waiting_for_peer',
                    'redacted' => true,
                ],
            ],
        ],
        'now_ms' => (strtotime('2026-05-10T00:05:01Z') ?: 0) * 1000,
    ]);
    $projectedPlan = videochat_media_session_plan_public_projection($plan);
    videochat_media_capability_gossip_assert((int) ($projectedPlan['plan_epoch'] ?? 0) === 8, 'plan epoch must advance monotonically');
    videochat_media_capability_gossip_assert(($projectedPlan['state_catalog'] ?? []) === [
        'waiting_for_capabilities',
        'waiting_for_gossip',
        'streaming_720p30',
        'throttled_50',
        'throttled_25',
        'stuck_not_sending',
        'audio_only',
        'video_unavailable',
        'blocked_capability',
        'left',
    ], 'plan must publish gossip state catalog');
    videochat_media_capability_gossip_assert(($projectedPlan['participants'][0]['media_state'] ?? '') === 'streaming_720p30', '720p30 sender must stream');
    videochat_media_capability_gossip_assert(($projectedPlan['participants'][0]['transport'] ?? '') === 'gossip', '720p30 sender must use gossip transport');
    videochat_media_capability_gossip_assert(($projectedPlan['participants'][1]['transport'] ?? '') === 'gossip', 'throttled_50 must keep gossip transport');
    videochat_media_capability_gossip_assert(($projectedPlan['participants'][2]['transport'] ?? '') === 'gossip', 'throttled_25 must keep gossip transport');
    videochat_media_capability_gossip_assert(($projectedPlan['participants'][3]['stuck_reason'] ?? '') === 'gossip_backpressure_timeout', 'stuck state must expose reason');
    videochat_media_capability_gossip_assert(($projectedPlan['participants'][4]['media_state'] ?? '') === 'audio_only', 'plain WebRTC talk audio must bypass Gossip/SFU video send gates');
    videochat_media_capability_gossip_assert(($projectedPlan['participants'][4]['transport'] ?? '') === '', 'audio_only must not claim a Gossip/SFU video transport');
    videochat_media_capability_gossip_assert(($projectedPlan['participants'][4]['security_policy'] ?? '') === 'transport_only', 'audio_only must not require MediaSecurity protected-frame transforms');
    videochat_media_capability_gossip_assert(($projectedPlan['participants'][5]['media_state'] ?? '') === 'audio_only', 'non-720p camera must not block plain native WebRTC talk audio');
    videochat_media_capability_gossip_assert(($projectedPlan['participants'][5]['security_policy'] ?? '') === 'transport_only', 'non-720p talk audio must keep transport-only security');
    videochat_media_capability_gossip_assert(($projectedPlan['participants'][6]['media_state'] ?? '') === 'stuck_not_sending', 'readiness timeout must become stuck_not_sending');
    videochat_media_capability_gossip_assert(($projectedPlan['participants'][6]['stuck_reason'] ?? '') === 'gossip_readiness_timeout', 'readiness timeout must expose reason');

    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $frames[] = [
            'socket' => $socket,
            'payload' => $payload,
        ];
        return true;
    };
    $command = videochat_realtime_decode_client_capabilities_frame(json_encode([
        'type' => 'client/capabilities.v1',
        'schema_version' => 'king.video.client_capabilities.v1',
        'participant_session_id' => 'participant-gossip-a',
        'media' => ['camera' => true, 'camera_720p30' => true],
        'runtime' => ['websocket' => true],
        'constraints' => ['video_width' => 1280, 'video_height' => 720, 'video_fps' => 30],
    ], JSON_UNESCAPED_SLASHES) ?: '');
    $presenceState = ['connections' => []];
    $presenceConnection = [
        'connection_id' => 'conn-gossip-a',
        'session_id' => 'session-gossip-a',
        'socket_id' => 'socket-gossip-a',
        'room_id' => 'room-gossip',
        'active_call_id' => 'call-gossip',
        'user_id' => 101,
    ];
    $handled = videochat_realtime_handle_media_capabilities_websocket_command(
        $command,
        'socket-gossip-a',
        $presenceState,
        $presenceConnection,
        static function (): PDO {
            throw new RuntimeException('forced persistence failure');
        },
        $sender
    );
    videochat_media_capability_gossip_assert((bool) ($handled['handled'] ?? false), 'failed persistence command must be handled');
    videochat_media_capability_gossip_assert(count($frames) === 1, 'failed persistence must only send one ack frame');
    $ack = (array) ($frames[0]['payload'] ?? []);
    videochat_media_capability_gossip_assert(($ack['type'] ?? '') === 'client.capabilities.v1/ack', 'failed persistence must ack capabilities command');
    videochat_media_capability_gossip_assert((bool) ($ack['ok'] ?? true) === false, 'failed persistence ack must not report success');
    videochat_media_capability_gossip_assert((bool) ($ack['stored'] ?? true) === false, 'failed persistence ack must report stored=false');
    videochat_media_capability_gossip_assert((int) ($ack['plan_epoch'] ?? 0) >= 1, 'failed persistence ack must report plan epoch');
    videochat_media_capability_gossip_assert(is_array($ack['client_capabilities'] ?? null), 'failed persistence ack must include public capability projection');

    fwrite(STDOUT, "[media-capability-plan-gossip-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[media-capability-plan-gossip-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
