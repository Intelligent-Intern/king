<?php

declare(strict_types=1);

function videochat_media_capability_plan_fail(string $message): never
{
    fwrite(STDERR, "[media-capability-plan-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_media_capability_plan_assert(bool $condition, string $message): void
{
    if (!$condition) {
        videochat_media_capability_plan_fail($message);
    }
}

function videochat_media_capability_plan_assert_no_forbidden_data(mixed $value, string $label, string $path = '$'): void
{
    if (is_string($value)) {
        videochat_media_capability_plan_assert(
            preg_match('/(?:secret-token|cookie=value|v=0|candidate:|raw-frame|encoded-frame-bytes|private-device-label)/i', $value) !== 1,
            "{$label} leaked forbidden value at {$path}"
        );
        return;
    }
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $key => $entry) {
        $keyText = is_string($key) ? $key : (string) $key;
        videochat_media_capability_plan_assert(
            preg_match('/(?:^|_)(?:token|cookie|credential|secret|sdp|ice|candidate|frame|raw_frame|encoded_frame|protected_frame|device_label|label)(?:$|_)/i', $keyText) !== 1,
            "{$label} leaked forbidden key {$path}.{$keyText}"
        );
        videochat_media_capability_plan_assert_no_forbidden_data($entry, $label, "{$path}.{$keyText}");
    }
}

function videochat_media_capability_plan_assert_allowed_states(array $plan, string $label): void
{
    $allowedStates = videochat_media_session_plan_allowed_states();
    videochat_media_capability_plan_assert(
        ($plan['state_catalog'] ?? null) === $allowedStates,
        "{$label} must publish the backend state catalog"
    );

    foreach ((array) ($plan['participants'] ?? []) as $index => $participant) {
        videochat_media_capability_plan_assert(is_array($participant), "{$label} participant {$index} must be an object");
        $state = (string) ($participant['media_state'] ?? '');
        videochat_media_capability_plan_assert(
            in_array($state, $allowedStates, true),
            "{$label} participant {$index} has unsupported media state {$state}"
        );
    }
}

function videochat_media_capability_plan_participant(array $plan, string $participantSessionId): array
{
    foreach ((array) ($plan['participants'] ?? []) as $participant) {
        if (is_array($participant) && (string) ($participant['participant_session_id'] ?? '') === $participantSessionId) {
            return $participant;
        }
    }

    videochat_media_capability_plan_fail("missing participant {$participantSessionId}");
}

function videochat_media_capability_plan_frames_by_type(array $frames, string $socket, string $type): array
{
    $rows = $frames[$socket] ?? [];
    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_filter(
        $rows,
        static fn (mixed $frame): bool => is_array($frame) && (string) ($frame['type'] ?? '') === $type
    ));
}

try {
    require_once __DIR__ . '/../support/auth_rbac.php';
    require_once __DIR__ . '/../domain/realtime/realtime_client_capabilities.php';
    require_once __DIR__ . '/../domain/realtime/realtime_media_session_plan.php';
    require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
    require_once __DIR__ . '/../domain/realtime/realtime_room_snapshot.php';
    if (!function_exists('videochat_realtime_secondary_handled_result')) {
        function videochat_realtime_secondary_handled_result(): array
        {
            return [
                'handled' => true,
                'command_type' => '',
                'command_error' => '',
            ];
        }
    }
    if (!function_exists('videochat_realtime_secondary_invalid_result')) {
        function videochat_realtime_secondary_invalid_result(
            array $command,
            string $fallbackType = '',
            string $fallbackError = 'unsupported_type'
        ): array {
            return [
                'handled' => false,
                'command_type' => (string) ($command['type'] ?? $fallbackType),
                'command_error' => (string) ($command['error'] ?? $fallbackError),
            ];
        }
    }
    require_once __DIR__ . '/../http/module_realtime_media_session_commands.php';

    videochat_media_capability_plan_assert(
        videochat_client_capabilities_schema_version() === 'king.video.client_capabilities.v1',
        'client capabilities schema mismatch'
    );
    videochat_media_capability_plan_assert(
        videochat_media_session_plan_schema_version() === 'king.video.media_session_plan.v1',
        'media session plan schema mismatch'
    );
    videochat_media_capability_plan_assert(
        videochat_call_media_state_event_schema_version() === 'king.video.call_media_state.v1',
        'call media state event schema mismatch'
    );
    $expectedStates = [
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
    ];
    videochat_media_capability_plan_assert(
        videochat_media_session_plan_allowed_states() === $expectedStates,
        'media session plan state catalog mismatch'
    );
    $expectedSessionStates = [
        'pending',
        'connecting',
        'gossip_720p30',
        'gossip_360p30',
        'gossip_360p5',
        'sfu_720p30',
        'sfu_320p30',
        'ready',
        'failed',
    ];
    videochat_media_capability_plan_assert(
        videochat_media_session_plan_session_states() === $expectedSessionStates,
        'media session plan session-state catalog mismatch'
    );
    $expectedLadderIds = ['gossip_720p30', 'gossip_360p30', 'gossip_360p5', 'sfu_720p30', 'sfu_320p30'];
    $backendLadder = videochat_media_session_plan_ladder();
    videochat_media_capability_plan_assert(
        array_column($backendLadder, 'plan_id') === $expectedLadderIds,
        'media session plan ladder order mismatch'
    );
    videochat_media_capability_plan_assert(
        array_column($backendLadder, 'render_window_ms') === [30_000, 30_000, 30_000, 30_000, 30_000],
        'media session plan ladder render windows mismatch'
    );
    videochat_media_capability_plan_assert(
        ($backendLadder[3]['selected_by'] ?? '') === 'orchestrator'
            && ($backendLadder[4]['selected_by'] ?? '') === 'orchestrator',
        'SFU ladder entries must be orchestrator-selected'
    );

    $capabilities = videochat_client_capabilities_normalize([
        'schema_version' => 'king.video.client_capabilities.v1',
        'participant_session_id' => 'call-session-alpha',
        'media' => [
            'camera' => true,
            'camera_720p30' => true,
            'microphone' => true,
            'screen_share' => true,
        ],
        'runtime' => [
            'websocket' => true,
            'webrtc' => true,
            'webassembly' => true,
            'webcodecs' => false,
            'gpu' => 'available_or_unknown',
            'wlvc_encoder' => true,
            'wlvc_decoder' => true,
        ],
        'constraints' => [
            'video_width' => 1280,
            'video_height' => 720,
            'video_fps' => 30,
        ],
        'token' => 'secret-token',
        'cookie' => 'cookie=value',
        'sdp' => "v=0\r\nsecret",
        'ice_candidates' => ['candidate:private-ice'],
        'encoded_frame' => 'encoded-frame-bytes',
        'device_label' => 'private-device-label',
    ], [
        'connection_id' => 'conn-alpha',
    ]);
    $publicCapabilities = videochat_client_capabilities_public_projection($capabilities);

    videochat_media_capability_plan_assert(($publicCapabilities['media']['camera_720p30'] ?? false) === true, '720p30 capability should survive projection');
    videochat_media_capability_plan_assert(($publicCapabilities['runtime']['wlvc_encoder'] ?? false) === true, 'runtime encoder capability should survive projection');
    videochat_media_capability_plan_assert(($publicCapabilities['codec']['preferred_path'] ?? '') === 'wlvc_wasm', 'codec path should survive projection');
    videochat_media_capability_plan_assert(array_key_exists('network', $publicCapabilities), 'network/backpressure capability summary should survive projection');
    videochat_media_capability_plan_assert(($publicCapabilities['constraints']['browser_family'] ?? '') === 'unknown', 'browser constraint should be normalized');
    videochat_media_capability_plan_assert_no_forbidden_data($publicCapabilities, 'client.capabilities.v1');

    $connection = videochat_presence_connection_descriptor(
        [
            'id' => 101,
            'display_name' => 'Capability User',
            'role' => 'user',
        ],
        'auth-session-alpha',
        'conn-alpha',
        'socket-alpha',
        'room-alpha'
    );
    $connection['client_capabilities'] = $capabilities;
    $publicConnection = videochat_presence_public_connection($connection);
    videochat_media_capability_plan_assert(
        ($publicConnection['client_capabilities'] ?? null) === $publicCapabilities,
        'presence public connection must expose redacted client capabilities'
    );

    $plan = videochat_media_session_plan_public_projection(videochat_media_session_plan_build([
        'call_id' => 'call-alpha',
        'room_id' => 'room-alpha',
        'plan_epoch' => 2,
        'participants' => [
            [
                'participant_session_id' => 'call-session-alpha',
                'client_capabilities' => $publicCapabilities,
                'gossip_readiness' => [
                    'topology_ready' => true,
                    'peer_ready' => true,
                    'peer_count' => 2,
                    'assigned_neighbor_count' => 1,
                    'topology_epoch' => 1_778_393_600_000,
                    'redacted' => true,
                ],
                'token' => 'secret-token',
                'sdp' => "v=0\r\nsecret",
                'ice_candidates' => ['candidate:private-ice'],
                'frame' => 'raw-frame',
            ],
        ],
    ]));

    videochat_media_capability_plan_assert(($plan['schema_version'] ?? '') === 'king.video.media_session_plan.v1', 'plan schema mismatch');
    videochat_media_capability_plan_assert(($plan['session_state_catalog'] ?? []) === $expectedSessionStates, 'plan must publish session-state catalog');
    videochat_media_capability_plan_assert(($plan['session_state'] ?? '') === 'gossip_720p30', 'ready 720p30 sender should expose selected session state');
    videochat_media_capability_plan_assert(($plan['participants'][0]['media_state'] ?? '') === 'streaming_720p30', '720p30 sender should be planned as streaming_720p30');
    videochat_media_capability_plan_assert(($plan['participants'][0]['transport'] ?? '') === 'gossip', '720p30 sender must be planned over gossip');
    videochat_media_capability_plan_assert((int) ($plan['plan_epoch'] ?? 0) >= 2, 'plan must expose a monotonic plan epoch');
    videochat_media_capability_plan_assert_allowed_states($plan, 'media_session_plan.v1');
    videochat_media_capability_plan_assert_no_forbidden_data($plan, 'media_session_plan.v1');

    $mediaStateEvent = videochat_call_media_state_event($connection, $capabilities, 'client_capabilities');
    videochat_media_capability_plan_assert((string) ($mediaStateEvent['type'] ?? '') === 'call/media-state.v1', 'media state event type mismatch');
    videochat_media_capability_plan_assert(($mediaStateEvent['state_catalog'] ?? []) === $expectedStates, 'media state event state catalog mismatch');
    videochat_media_capability_plan_assert(($mediaStateEvent['session_state_catalog'] ?? []) === $expectedSessionStates, 'media state event session-state catalog mismatch');
    videochat_media_capability_plan_assert((bool) ($mediaStateEvent['redacted'] ?? false), 'media state event must be marked redacted');
    videochat_media_capability_plan_assert(
        (string) (($mediaStateEvent['participant'] ?? [])['media_state'] ?? '') === 'waiting_for_gossip',
        'media state event must wait for authoritative gossip readiness'
    );
    videochat_media_capability_plan_assert(
        (string) (($mediaStateEvent['participant'] ?? [])['transport'] ?? '') === '',
        'media state event must not expose gossip transport before readiness'
    );
    videochat_media_capability_plan_assert_no_forbidden_data($mediaStateEvent, 'call/media-state.v1');

    $receiveOnlyCapabilities = videochat_client_capabilities_public_projection(videochat_client_capabilities_normalize([
        'participant_session_id' => 'call-session-receive',
        'media' => [
            'camera' => false,
            'microphone' => true,
        ],
        'runtime' => [
            'websocket' => true,
            'webrtc' => true,
            'webassembly' => false,
        ],
    ]));
    $non720TalkCapabilities = videochat_client_capabilities_public_projection(videochat_client_capabilities_normalize([
        'participant_session_id' => 'call-session-non720-talk',
        'media' => [
            'camera' => true,
            'camera_720p30' => false,
            'microphone' => true,
        ],
        'runtime' => [
            'websocket' => false,
            'webrtc' => true,
            'webassembly' => false,
            'wlvc_encoder' => false,
        ],
        'constraints' => [
            'video_width' => 640,
            'video_height' => 480,
            'video_fps' => 15,
        ],
    ]));
    $videoUnavailableCapabilities = videochat_client_capabilities_public_projection(videochat_client_capabilities_normalize([
        'participant_session_id' => 'call-session-video-unavailable',
        'media' => [
            'camera' => true,
            'camera_720p30' => false,
            'microphone' => false,
        ],
        'runtime' => [
            'websocket' => true,
            'webrtc' => true,
            'wlvc_encoder' => true,
        ],
        'constraints' => [
            'video_width' => 640,
            'video_height' => 480,
            'video_fps' => 15,
        ],
    ]));
    videochat_media_capability_plan_assert(
        videochat_media_session_plan_state(['schema_valid' => false]) === 'blocked_capability',
        'invalid capability schema must be blocked'
    );
    $blockedCapabilities = videochat_client_capabilities_public_projection(videochat_client_capabilities_normalize([
        'participant_session_id' => 'call-session-blocked',
        'media' => [
            'camera' => false,
            'camera_720p30' => false,
            'microphone' => false,
        ],
        'runtime' => [
            'websocket' => false,
            'webrtc' => false,
            'webassembly' => false,
        ],
    ]));

    $statePlan = videochat_media_session_plan_public_projection(videochat_media_session_plan_build([
        'call_id' => 'call-alpha',
        'room_id' => 'room-alpha',
        'plan_epoch' => 4,
        'participants' => [
            [
                'participant_session_id' => 'call-session-waiting',
            ],
            [
                'participant_session_id' => 'call-session-alpha',
                'client_capabilities' => $publicCapabilities,
                'gossip_readiness' => [
                    'topology_ready' => true,
                    'peer_ready' => true,
                    'peer_count' => 2,
                    'assigned_neighbor_count' => 1,
                    'topology_epoch' => 1_778_393_600_000,
                    'redacted' => true,
                ],
            ],
            [
                'participant_session_id' => 'call-session-receive',
                'client_capabilities' => $receiveOnlyCapabilities,
            ],
            [
                'participant_session_id' => 'call-session-non720-talk',
                'client_capabilities' => $non720TalkCapabilities,
            ],
            [
                'participant_session_id' => 'call-session-video-unavailable',
                'client_capabilities' => $videoUnavailableCapabilities,
            ],
            [
                'participant_session_id' => 'call-session-blocked',
                'client_capabilities' => $blockedCapabilities,
            ],
            [
                'participant_session_id' => 'call-session-left',
                'client_capabilities' => $publicCapabilities,
                'left' => true,
            ],
        ],
    ]));
    videochat_media_capability_plan_assert_allowed_states($statePlan, 'integrated state plan');
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-waiting')['media_state'] ?? '') === 'waiting_for_capabilities',
        'missing capabilities must wait'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-alpha')['media_state'] ?? '') === 'streaming_720p30',
        '720p30 capabilities with ready gossip peers must send 720p30'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-alpha')['transport'] ?? '') === 'gossip',
        '720p30 capabilities must use gossip transport'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-receive')['media_state'] ?? '') === 'audio_only',
        'microphone-only WebRTC capabilities must keep native talk audio available'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-receive')['transport'] ?? '') === '',
        'audio_only talk must not require Gossip/SFU video transport'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-receive')['security_policy'] ?? '') === 'transport_only',
        'audio_only talk must not require MediaSecurity protected-frame transforms'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-non720-talk')['media_state'] ?? '') === 'audio_only',
        'non-720p camera must not block plain native WebRTC talk audio'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-non720-talk')['security_policy'] ?? '') === 'transport_only',
        'non-720p talk audio must keep transport-only security policy'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-video-unavailable')['media_state'] ?? '') === 'video_unavailable',
        'non-720p camera capability must be marked video_unavailable instead of blocking talk audio'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-video-unavailable')['security_policy'] ?? '') === 'transport_only',
        'video_unavailable state must not become a MediaSecurity block'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-blocked')['media_state'] ?? '') === 'blocked_capability',
        'missing usable audio/video capability must be blocked'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-left')['media_state'] ?? '') === 'left',
        'left participants must stay left'
    );
    videochat_media_capability_plan_assert(
        array_column((array) ($statePlan['ladder'] ?? []), 'plan_id') === $expectedLadderIds,
        'built plan must publish the authoritative media ladder'
    );
    $defaultSelectedPlan = (array) ($statePlan['selected_plan'] ?? []);
    videochat_media_capability_plan_assert(
        ($defaultSelectedPlan['plan_id'] ?? '') === 'gossip_720p30'
            && ($defaultSelectedPlan['transport'] ?? '') === 'gossip'
            && ($defaultSelectedPlan['profile'] ?? '') === '720p30',
        'built plan must default to selected Gossip 720p30'
    );
    videochat_media_capability_plan_assert(
        ($statePlan['session_state'] ?? '') === 'pending',
        'mixed participant readiness must keep the plan session pending'
    );
    videochat_media_capability_plan_assert(
        ($defaultSelectedPlan['reason'] ?? '') === 'initial_gossip_720p30'
            && (int) ($defaultSelectedPlan['selected_at_ms'] ?? 0) > 0
            && (int) ($defaultSelectedPlan['updated_at_ms'] ?? 0) > 0,
        'selected plan must carry reason and timestamps'
    );
    $orchestratedPlan = videochat_media_session_plan_public_projection(videochat_media_session_plan_build([
        'call_id' => 'call-alpha',
        'room_id' => 'room-alpha',
        'now_ms' => 1_778_393_600_000,
        'selected_plan' => [
            'plan_id' => 'sfu_320p30',
            'reason' => 'sfu_720_render_failed',
            'selected_at_ms' => 1_778_393_570_000,
            'updated_at_ms' => 1_778_393_590_000,
        ],
        'participants' => [[
            'participant_session_id' => 'call-session-alpha',
            'client_capabilities' => $publicCapabilities,
            'gossip_readiness' => ['topology_ready' => false, 'peer_ready' => false],
        ]],
    ]));
    $orchestratedSelected = (array) ($orchestratedPlan['selected_plan'] ?? []);
    videochat_media_capability_plan_assert(
        ($orchestratedSelected['plan_id'] ?? '') === 'sfu_320p30'
            && ($orchestratedSelected['selected_by'] ?? '') === 'orchestrator'
            && ($orchestratedSelected['selection_gate'] ?? '') === 'after_sfu_720p30_render_failure',
        'stored selected SFU 320p30 must stay orchestrator-gated'
    );
    videochat_media_capability_plan_assert(
        ($orchestratedSelected['session_state'] ?? '') === 'sfu_320p30'
            && ($orchestratedPlan['session_state'] ?? '') === 'sfu_320p30',
        'stored selected SFU 320p30 must publish canonical session state'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($orchestratedPlan, 'call-session-alpha')['transport'] ?? '') === 'sfu'
            && (videochat_media_capability_plan_participant($orchestratedPlan, 'call-session-alpha')['profile'] ?? '') === '320p30',
        'selected plan transport/profile must flow into sending participant plan'
    );
    videochat_media_capability_plan_assert(
        (int) ($orchestratedSelected['selected_at_ms'] ?? 0) === 1_778_393_570_000
            && (int) ($orchestratedSelected['updated_at_ms'] ?? 0) === 1_778_393_590_000
            && ($orchestratedSelected['reason'] ?? '') === 'sfu_720_render_failed',
        'stored selected plan reason and timestamps must be idempotent'
    );

    $transitionParticipants = [
        [
            'participant_session_id' => 'call-session-alpha',
            'client_capabilities' => $publicCapabilities,
            'gossip_readiness' => [
                'topology_ready' => true,
                'peer_ready' => true,
                'peer_count' => 2,
                'assigned_neighbor_count' => 1,
                'topology_epoch' => 1_778_393_600_000,
            ],
        ],
        [
            'participant_session_id' => 'call-session-beta',
            'client_capabilities' => $publicCapabilities,
            'gossip_readiness' => [
                'topology_ready' => true,
                'peer_ready' => true,
                'peer_count' => 2,
                'assigned_neighbor_count' => 1,
                'topology_epoch' => 1_778_393_600_000,
            ],
        ],
    ];
    $gossip720TimeoutPlan = videochat_media_session_plan_public_projection(videochat_media_session_plan_build([
        'call_id' => 'call-alpha',
        'room_id' => 'room-alpha',
        'now_ms' => 1_778_393_600_000,
        'selected_plan' => [
            'plan_id' => 'gossip_720p30',
            'selected_at_ms' => 1_778_393_569_999,
        ],
        'participants' => $transitionParticipants,
        'receiver_render_evidence' => [
            'last_rendered_at_ms' => 0,
        ],
    ]));
    $gossip720TimeoutSelected = (array) ($gossip720TimeoutPlan['selected_plan'] ?? []);
    videochat_media_capability_plan_assert(
        ($gossip720TimeoutSelected['plan_id'] ?? '') === 'gossip_360p30'
            && ($gossip720TimeoutSelected['reason'] ?? '') === 'after_gossip_720_render_failure'
            && ($gossip720TimeoutSelected['transition']['previous_plan_id'] ?? '') === 'gossip_720p30'
            && ($gossip720TimeoutSelected['transition']['next_plan_id'] ?? '') === 'gossip_360p30',
        'Gossip 720p30 must downgrade to Gossip 360p30 after one render window without receiver render evidence'
    );
    videochat_media_capability_plan_assert(
        (int) ($gossip720TimeoutSelected['transition']['no_receiver_render_for_ms'] ?? 0) >= 30_000
            && (int) ($gossip720TimeoutSelected['transition']['render_window_ms'] ?? 0) === 30_000
            && (string) ($gossip720TimeoutSelected['transition']['idempotency_key'] ?? '') !== '',
        'Gossip 720p30 transition must carry idempotent render-window evidence'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($gossip720TimeoutPlan, 'call-session-alpha')['profile'] ?? '') === '360p30'
            && (videochat_media_capability_plan_participant($gossip720TimeoutPlan, 'call-session-alpha')['transport'] ?? '') === 'gossip',
        'downgraded Gossip 360p30 plan must flow into participant transport/profile'
    );
    $recentRenderPlan = videochat_media_session_plan_public_projection(videochat_media_session_plan_build([
        'call_id' => 'call-alpha',
        'room_id' => 'room-alpha',
        'now_ms' => 1_778_393_600_000,
        'selected_plan' => [
            'plan_id' => 'gossip_720p30',
            'selected_at_ms' => 1_778_393_560_000,
        ],
        'participants' => $transitionParticipants,
        'receiver_render_evidence' => [
            'last_rendered_at_ms' => 1_778_393_590_001,
            'sample_count' => 4,
        ],
    ]));
    videochat_media_capability_plan_assert(
        (($recentRenderPlan['selected_plan'] ?? [])['plan_id'] ?? '') === 'gossip_720p30',
        'recent receiver render evidence must keep Gossip 720p30 selected'
    );
    $gossip360p30TimeoutPlan = videochat_media_session_plan_public_projection(videochat_media_session_plan_build([
        'call_id' => 'call-alpha',
        'room_id' => 'room-alpha',
        'now_ms' => 1_778_393_640_000,
        'selected_plan' => [
            'plan_id' => 'gossip_360p30',
            'selected_at_ms' => 1_778_393_609_000,
        ],
        'participants' => $transitionParticipants,
    ]));
    videochat_media_capability_plan_assert(
        (($gossip360p30TimeoutPlan['selected_plan'] ?? [])['plan_id'] ?? '') === 'gossip_360p5',
        'Gossip 360p30 must downgrade to Gossip 360p5 after its render window without evidence'
    );
    $gossip360p5TimeoutPlan = videochat_media_session_plan_public_projection(videochat_media_session_plan_build([
        'call_id' => 'call-alpha',
        'room_id' => 'room-alpha',
        'now_ms' => 1_778_393_680_000,
        'selected_plan' => [
            'plan_id' => 'gossip_360p5',
            'selected_at_ms' => 1_778_393_649_000,
        ],
        'participants' => $transitionParticipants,
    ]));
    videochat_media_capability_plan_assert(
        (($gossip360p5TimeoutPlan['selected_plan'] ?? [])['plan_id'] ?? '') === 'sfu_720p30'
            && (($gossip360p5TimeoutPlan['selected_plan'] ?? [])['selected_by'] ?? '') === 'orchestrator',
        'Gossip 360p5 must hand off to orchestrator-selected SFU 720p30 after its render window'
    );
    $sfu720TimeoutPlan = videochat_media_session_plan_public_projection(videochat_media_session_plan_build([
        'call_id' => 'call-alpha',
        'room_id' => 'room-alpha',
        'now_ms' => 1_778_393_720_000,
        'selected_plan' => [
            'plan_id' => 'sfu_720p30',
            'selected_at_ms' => 1_778_393_689_000,
        ],
        'participants' => $transitionParticipants,
    ]));
    videochat_media_capability_plan_assert(
        (($sfu720TimeoutPlan['selected_plan'] ?? [])['plan_id'] ?? '') === 'sfu_320p30'
            && (($sfu720TimeoutPlan['selected_plan'] ?? [])['selection_gate'] ?? '') === 'after_sfu_720p30_render_failure',
        'SFU 720p30 must downgrade to SFU 320p30 after its render window without evidence'
    );
    $idempotentNextPlan = videochat_media_session_plan_public_projection(videochat_media_session_plan_build([
        'call_id' => 'call-alpha',
        'room_id' => 'room-alpha',
        'now_ms' => 1_778_393_601_000,
        'selected_plan' => $gossip720TimeoutSelected,
        'participants' => $transitionParticipants,
    ]));
    videochat_media_capability_plan_assert(
        (($idempotentNextPlan['selected_plan'] ?? [])['plan_id'] ?? '') === 'gossip_360p30',
        'transition result must be idempotent and must not skip multiple ladder steps'
    );
    videochat_media_capability_plan_assert_no_forbidden_data($statePlan, 'integrated state plan');
    videochat_media_capability_plan_assert_no_forbidden_data($orchestratedPlan, 'orchestrated selected plan');
    videochat_media_capability_plan_assert_no_forbidden_data($gossip720TimeoutPlan, 'orchestrated Gossip 360p30 transition plan');

    $staleCapabilities = $publicCapabilities;
    $staleCapabilities['received_at'] = '2026-05-10T00:00:00Z';
    $readinessTimeoutPlan = videochat_media_session_plan_public_projection(videochat_media_session_plan_build([
        'call_id' => 'call-alpha',
        'room_id' => 'room-alpha',
        'now_ms' => (strtotime('2026-05-10T00:05:01Z') ?: 0) * 1000,
        'participants' => [
            [
                'participant_session_id' => 'call-session-timeout',
                'client_capabilities' => $staleCapabilities,
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
    ]));
    $timedOutParticipant = videochat_media_capability_plan_participant($readinessTimeoutPlan, 'call-session-timeout');
    videochat_media_capability_plan_assert(
        ($timedOutParticipant['media_state'] ?? '') === 'stuck_not_sending',
        'stale gossip readiness wait must become stuck_not_sending'
    );
    videochat_media_capability_plan_assert(
        ($timedOutParticipant['stuck_reason'] ?? '') === 'gossip_readiness_timeout',
        'stale gossip readiness wait must expose timeout reason'
    );
    videochat_media_capability_plan_assert_no_forbidden_data($readinessTimeoutPlan, 'gossip readiness timeout plan');

    $restoreConnection = videochat_presence_connection_descriptor(
        [
            'id' => 101,
            'display_name' => 'Capability User',
            'role' => 'user',
        ],
        'auth-session-alpha',
        'conn-alpha',
        'socket-alpha',
        'room-alpha'
    );
    $restoreConnection['active_call_id'] = 'call-alpha';
    $restoreNowMs = videochat_client_capabilities_now_ms();
    $persistedCapabilities = ['conn-alpha' => $publicCapabilities];
    $sqliteAvailable = in_array('sqlite', PDO::getAvailableDrivers(), true);
    $openDatabase = static function (): PDO {
        throw new RuntimeException('sqlite driver unavailable');
    };
    if ($sqliteAvailable) {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        videochat_realtime_presence_db_upsert($pdo, $restoreConnection, $restoreNowMs);
        videochat_media_capability_plan_assert(
            videochat_client_capabilities_upsert($pdo, $restoreConnection, $capabilities),
            'capabilities must persist for restore'
        );
        $persistedCapabilities = videochat_client_capabilities_fetch_room($pdo, 'call-alpha', 'room-alpha', $restoreNowMs + 1_000);
        videochat_media_capability_plan_assert(
            ($persistedCapabilities['conn-alpha'] ?? null) === $publicCapabilities,
            'persisted capabilities must restore as public redacted projection'
        );
        $openDatabase = static fn (): PDO => $pdo;
    } else {
        $capabilitiesSource = (string) file_get_contents(__DIR__ . '/../domain/realtime/realtime_client_capabilities.php');
        videochat_media_capability_plan_assert(
            str_contains($capabilitiesSource, 'INSERT INTO realtime_client_capabilities')
                && str_contains($capabilitiesSource, 'videochat_client_capabilities_fetch_room'),
            'persistence restore contract must expose upsert and fetch paths'
        );
    }
    videochat_media_capability_plan_assert_no_forbidden_data($persistedCapabilities, 'persisted client capabilities');

    $restoredPlan = videochat_media_session_plan_for_snapshot(
        [],
        $restoreConnection,
        [
            [
                'connection_id' => 'conn-alpha',
                'room_id' => 'room-alpha',
                'user' => [
                    'id' => 101,
                    'display_name' => 'Capability User',
                    'role' => 'user',
                    'call_role' => 'participant',
                ],
                'connected_at' => '2026-05-10T00:00:00Z',
            ],
        ],
        $persistedCapabilities
    );
    videochat_media_capability_plan_assert_allowed_states($restoredPlan, 'restored media_session_plan.v1');
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($restoredPlan, 'conn-alpha')['media_state'] ?? '') === 'waiting_for_gossip',
        'snapshot plan must restore persisted capabilities but wait for gossip readiness'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($restoredPlan, 'conn-alpha')['transport'] ?? '') === '',
        'snapshot plan must not restore gossip transport before readiness'
    );
    videochat_media_capability_plan_assert_no_forbidden_data($restoredPlan, 'restored media_session_plan.v1');

    $snapshotPresence = videochat_presence_state_init();
    $snapshotConnection = $restoreConnection;
    if (!$sqliteAvailable) {
        $snapshotConnection['client_capabilities'] = $capabilities;
        $snapshotPresence['client_capabilities'] = ['conn-alpha' => $publicCapabilities];
    }
    $snapshotPresence['rooms'][videochat_presence_room_key_for_connection($snapshotConnection)] = ['conn-alpha' => true];
    $snapshotPresence['connections']['conn-alpha'] = $snapshotConnection;
    $snapshotPayload = videochat_realtime_room_snapshot_payload(
        $snapshotPresence,
        $snapshotConnection,
        $openDatabase,
        'capability_restore_contract',
        $restoreNowMs + 1_000
    );
    videochat_media_capability_plan_assert(isset($snapshotPayload['media_session_plan']), 'room snapshot must carry media_session_plan');
    videochat_media_capability_plan_assert_allowed_states((array) $snapshotPayload['media_session_plan'], 'snapshot media_session_plan.v1');
    videochat_media_capability_plan_assert(
        (($snapshotPayload['media_session_plan'] ?? [])['session_state_catalog'] ?? []) === $expectedSessionStates,
        'room snapshot media_session_plan must carry canonical session states'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant((array) $snapshotPayload['media_session_plan'], 'conn-alpha')['media_state'] ?? '') === 'waiting_for_gossip',
        'room snapshot media_session_plan must use restored capabilities while waiting for peers'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant((array) $snapshotPayload['media_session_plan'], 'conn-alpha')['transport'] ?? '') === '',
        'room snapshot media_session_plan must not expose gossip transport without peers'
    );
    $signatureWithPlan = videochat_realtime_room_snapshot_signature($snapshotPayload);
    $snapshotWithoutPlan = $snapshotPayload;
    unset($snapshotWithoutPlan['media_session_plan']);
    videochat_media_capability_plan_assert(
        $signatureWithPlan !== videochat_realtime_room_snapshot_signature($snapshotWithoutPlan),
        'room snapshot signature must include media_session_plan'
    );
    videochat_media_capability_plan_assert_no_forbidden_data($snapshotPayload['media_session_plan'], 'snapshot media_session_plan.v1');

    $fanoutPresence = videochat_presence_state_init();
    $fanoutFrames = [];
    $sender = static function (mixed $socket, array $payload) use (&$fanoutFrames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        if (!isset($fanoutFrames[$key]) || !is_array($fanoutFrames[$key])) {
            $fanoutFrames[$key] = [];
        }
        $fanoutFrames[$key][] = $payload;
        return true;
    };
    $fanoutSenderConnection = videochat_presence_connection_descriptor(
        [
            'id' => 201,
            'display_name' => 'Fanout Sender',
            'role' => 'user',
        ],
        'sess-fanout-sender',
        'conn-fanout-sender',
        'socket-fanout-sender',
        'room-fanout'
    );
    $fanoutSenderConnection['active_call_id'] = 'call-fanout';
    $fanoutSenderConnection['requested_call_id'] = 'call-fanout';
    $senderJoin = videochat_presence_join_room($fanoutPresence, $fanoutSenderConnection, 'room-fanout', $sender);
    $fanoutSenderConnection = (array) ($senderJoin['connection'] ?? $fanoutSenderConnection);

    $fanoutPeerConnection = videochat_presence_connection_descriptor(
        [
            'id' => 202,
            'display_name' => 'Fanout Peer',
            'role' => 'user',
        ],
        'sess-fanout-peer',
        'conn-fanout-peer',
        'socket-fanout-peer',
        'room-fanout'
    );
    $fanoutPeerConnection['active_call_id'] = 'call-fanout';
    $fanoutPeerConnection['requested_call_id'] = 'call-fanout';
    videochat_presence_join_room($fanoutPresence, $fanoutPeerConnection, 'room-fanout', $sender);

    $otherRoomConnection = videochat_presence_connection_descriptor(
        [
            'id' => 203,
            'display_name' => 'Other Room',
            'role' => 'user',
        ],
        'sess-other-room',
        'conn-other-room',
        'socket-other-room',
        'room-other'
    );
    $otherRoomConnection['active_call_id'] = 'call-fanout';
    $otherRoomConnection['requested_call_id'] = 'call-fanout';
    videochat_presence_join_room($fanoutPresence, $otherRoomConnection, 'room-other', $sender);

    $fanoutFrames = [];
    $capabilitiesFrame = json_encode([
        'type' => 'client/capabilities.v1',
        'schema_version' => 'king.video.client_capabilities.v1',
        'participant_session_id' => 'fanout-session-alpha',
        'media' => [
            'camera' => true,
            'camera_720p30' => true,
            'microphone' => true,
        ],
        'runtime' => [
            'websocket' => true,
            'webrtc' => true,
            'wlvc_encoder' => true,
        ],
        'constraints' => [
            'video_width' => 1280,
            'video_height' => 720,
            'video_fps' => 30,
        ],
        'token' => 'secret-token',
        'sdp' => "v=0\r\nsecret",
        'ice_candidates' => ['candidate:private-ice'],
        'raw_frame' => 'raw-frame',
    ], JSON_UNESCAPED_SLASHES);
    videochat_media_capability_plan_assert(is_string($capabilitiesFrame), 'capabilities frame should encode');
    $capabilitiesCommand = videochat_realtime_decode_client_capabilities_frame($capabilitiesFrame);
    $handled = videochat_realtime_handle_media_capabilities_websocket_command(
        $capabilitiesCommand,
        'socket-fanout-sender',
        $fanoutPresence,
        $fanoutSenderConnection,
        $openDatabase,
        $sender
    );
    videochat_media_capability_plan_assert((bool) ($handled['handled'] ?? false), 'client/capabilities.v1 command should be handled');
    videochat_media_capability_plan_assert(
        count(videochat_media_capability_plan_frames_by_type($fanoutFrames, 'socket-fanout-sender', 'client.capabilities.v1/ack')) === 1,
        'capabilities sender must receive an ack'
    );
    $successAcks = videochat_media_capability_plan_frames_by_type($fanoutFrames, 'socket-fanout-sender', 'client.capabilities.v1/ack');
    if ($sqliteAvailable) {
        videochat_media_capability_plan_assert((bool) ($successAcks[0]['ok'] ?? false), 'successful capabilities ack must be ok');
        videochat_media_capability_plan_assert((bool) ($successAcks[0]['stored'] ?? false), 'successful capabilities ack must report stored state');
        videochat_media_capability_plan_assert((int) ($successAcks[0]['plan_epoch'] ?? 0) >= 1, 'successful capabilities ack must report plan epoch');
        videochat_media_capability_plan_assert_no_forbidden_data($successAcks[0], 'client.capabilities.v1 success ack');
        videochat_media_capability_plan_assert(
            count(videochat_media_capability_plan_frames_by_type($fanoutFrames, 'socket-fanout-sender', 'room/snapshot')) === 1,
            'capabilities sender must receive a refreshed room snapshot'
        );
        $senderMediaEvents = videochat_media_capability_plan_frames_by_type($fanoutFrames, 'socket-fanout-sender', 'call/media-state.v1');
        $peerMediaEvents = videochat_media_capability_plan_frames_by_type($fanoutFrames, 'socket-fanout-peer', 'call/media-state.v1');
        $otherMediaEvents = videochat_media_capability_plan_frames_by_type($fanoutFrames, 'socket-other-room', 'call/media-state.v1');
        videochat_media_capability_plan_assert(count($senderMediaEvents) === 1, 'sender must receive room media state fanout');
        videochat_media_capability_plan_assert(count($peerMediaEvents) === 1, 'room peer must receive room media state fanout');
        videochat_media_capability_plan_assert(count($otherMediaEvents) === 0, 'other rooms must not receive media state fanout');
        videochat_media_capability_plan_assert(($peerMediaEvents[0]['state_catalog'] ?? []) === $expectedStates, 'fanout event state catalog mismatch');
        videochat_media_capability_plan_assert(
            (string) (($peerMediaEvents[0]['participant'] ?? [])['media_state'] ?? '') === 'waiting_for_gossip',
            'fanout event must wait for authoritative gossip readiness'
        );
        videochat_media_capability_plan_assert(
            (string) (($peerMediaEvents[0]['participant'] ?? [])['transport'] ?? '') === '',
            'fanout event must not expose gossip transport before readiness'
        );
        videochat_media_capability_plan_assert_no_forbidden_data($peerMediaEvents[0], 'call/media-state.v1 fanout');
    } else {
        videochat_media_capability_plan_assert(!((bool) ($successAcks[0]['ok'] ?? true)), 'unavailable persistence ack must fail closed');
        videochat_media_capability_plan_assert(!((bool) ($successAcks[0]['stored'] ?? true)), 'unavailable persistence ack must report stored=false');
        videochat_media_capability_plan_assert((int) ($successAcks[0]['plan_epoch'] ?? 0) >= 1, 'unavailable persistence ack must report plan epoch');
        videochat_media_capability_plan_assert_no_forbidden_data($successAcks[0], 'client.capabilities.v1 unavailable persistence ack');
        videochat_media_capability_plan_assert(
            count(videochat_media_capability_plan_frames_by_type($fanoutFrames, 'socket-fanout-sender', 'room/snapshot')) === 0,
            'unavailable persistence must not publish a success snapshot'
        );
    }

    $failedFrames = [];
    $failedSender = static function (mixed $socket, array $payload) use (&$failedFrames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        if (!isset($failedFrames[$key]) || !is_array($failedFrames[$key])) {
            $failedFrames[$key] = [];
        }
        $failedFrames[$key][] = $payload;
        return true;
    };
    $failedPresence = videochat_presence_state_init();
    $failedConnection = $fanoutSenderConnection;
    unset($failedConnection['client_capabilities']);
    $failedHandled = videochat_realtime_handle_media_capabilities_websocket_command(
        $capabilitiesCommand,
        'socket-failed-sender',
        $failedPresence,
        $failedConnection,
        static function (): PDO {
            throw new RuntimeException('forced persistence failure');
        },
        $failedSender
    );
    videochat_media_capability_plan_assert((bool) ($failedHandled['handled'] ?? false), 'failed persistence command should still be handled');
    $failedAcks = videochat_media_capability_plan_frames_by_type($failedFrames, 'socket-failed-sender', 'client.capabilities.v1/ack');
    videochat_media_capability_plan_assert(count($failedAcks) === 1, 'failed persistence must receive one ack');
    videochat_media_capability_plan_assert(!((bool) ($failedAcks[0]['ok'] ?? true)), 'failed persistence ack must not report ok');
    videochat_media_capability_plan_assert(!((bool) ($failedAcks[0]['stored'] ?? true)), 'failed persistence ack must report stored=false');
    videochat_media_capability_plan_assert((int) ($failedAcks[0]['plan_epoch'] ?? 0) >= 1, 'failed persistence ack must report plan epoch');
    videochat_media_capability_plan_assert(
        count(videochat_media_capability_plan_frames_by_type($failedFrames, 'socket-failed-sender', 'room/snapshot')) === 0,
        'failed persistence must not publish a success snapshot'
    );
    videochat_media_capability_plan_assert_no_forbidden_data($failedAcks[0], 'client.capabilities.v1 failure ack');

    $commandsSource = (string) file_get_contents(__DIR__ . '/../http/module_realtime_websocket_commands.php');
    $mediaCommandsSource = (string) file_get_contents(__DIR__ . '/../http/module_realtime_media_session_commands.php');
    $snapshotSource = (string) file_get_contents(__DIR__ . '/../domain/realtime/realtime_room_snapshot.php');
    videochat_media_capability_plan_assert(str_contains($commandsSource, 'videochat_realtime_decode_client_capabilities_frame'), 'websocket dispatcher must decode client/capabilities.v1');
    videochat_media_capability_plan_assert(str_contains($commandsSource, 'videochat_realtime_handle_media_capabilities_websocket_command'), 'websocket dispatcher must call media capabilities handler');
    videochat_media_capability_plan_assert(str_contains($mediaCommandsSource, 'videochat_presence_broadcast_room_event'), 'capabilities handler must fanout media state as a room event');
    videochat_media_capability_plan_assert(str_contains($snapshotSource, 'media_session_plan'), 'room snapshot must expose media_session_plan');
    videochat_media_capability_plan_assert(
        preg_match('/videochat_realtime_room_snapshot_signature[\s\S]*media_session_plan/', $snapshotSource) === 1,
        'room snapshot signature must include media_session_plan'
    );

    fwrite(STDOUT, "[media-capability-plan-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[media-capability-plan-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
