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
    require_once __DIR__ . '/../support/auth.php';
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
        'sending_720p30',
        'receive_only',
        'video_unavailable',
        'blocked_capability',
        'left',
    ];
    videochat_media_capability_plan_assert(
        videochat_media_session_plan_allowed_states() === $expectedStates,
        'media session plan state catalog mismatch'
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
                'token' => 'secret-token',
                'sdp' => "v=0\r\nsecret",
                'ice_candidates' => ['candidate:private-ice'],
                'frame' => 'raw-frame',
            ],
        ],
    ]));

    videochat_media_capability_plan_assert(($plan['schema_version'] ?? '') === 'king.video.media_session_plan.v1', 'plan schema mismatch');
    videochat_media_capability_plan_assert(($plan['participants'][0]['media_state'] ?? '') === 'sending_720p30', '720p30 sender should be planned as sending_720p30');
    videochat_media_capability_plan_assert_allowed_states($plan, 'media_session_plan.v1');
    videochat_media_capability_plan_assert_no_forbidden_data($plan, 'media_session_plan.v1');

    $mediaStateEvent = videochat_call_media_state_event($connection, $capabilities, 'client_capabilities');
    videochat_media_capability_plan_assert((string) ($mediaStateEvent['type'] ?? '') === 'call/media-state.v1', 'media state event type mismatch');
    videochat_media_capability_plan_assert(($mediaStateEvent['state_catalog'] ?? []) === $expectedStates, 'media state event state catalog mismatch');
    videochat_media_capability_plan_assert((bool) ($mediaStateEvent['redacted'] ?? false), 'media state event must be marked redacted');
    videochat_media_capability_plan_assert(
        (string) (($mediaStateEvent['participant'] ?? [])['media_state'] ?? '') === 'sending_720p30',
        'media state event should expose the computed allowed state'
    );
    videochat_media_capability_plan_assert_no_forbidden_data($mediaStateEvent, 'call/media-state.v1');

    $receiveOnlyCapabilities = videochat_client_capabilities_public_projection(videochat_client_capabilities_normalize([
        'participant_session_id' => 'call-session-receive',
        'media' => [
            'camera' => false,
            'microphone' => true,
        ],
        'runtime' => [
            'websocket' => false,
            'webrtc' => false,
            'webassembly' => false,
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
            'camera' => true,
            'camera_720p30' => true,
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
            ],
            [
                'participant_session_id' => 'call-session-receive',
                'client_capabilities' => $receiveOnlyCapabilities,
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
        (videochat_media_capability_plan_participant($statePlan, 'call-session-alpha')['media_state'] ?? '') === 'sending_720p30',
        '720p30 capabilities must send 720p30'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-receive')['media_state'] ?? '') === 'receive_only',
        'microphone-only capabilities must be receive_only'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-video-unavailable')['media_state'] ?? '') === 'video_unavailable',
        'non-720p camera capability must be video_unavailable'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-blocked')['media_state'] ?? '') === 'blocked_capability',
        'unsupported media transport must be blocked'
    );
    videochat_media_capability_plan_assert(
        (videochat_media_capability_plan_participant($statePlan, 'call-session-left')['media_state'] ?? '') === 'left',
        'left participants must stay left'
    );
    videochat_media_capability_plan_assert_no_forbidden_data($statePlan, 'integrated state plan');

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
        (videochat_media_capability_plan_participant($restoredPlan, 'conn-alpha')['media_state'] ?? '') === 'sending_720p30',
        'snapshot plan must restore persisted capabilities'
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
        (videochat_media_capability_plan_participant((array) $snapshotPayload['media_session_plan'], 'conn-alpha')['media_state'] ?? '') === 'sending_720p30',
        'room snapshot media_session_plan must use restored capabilities'
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
        (string) (($peerMediaEvents[0]['participant'] ?? [])['media_state'] ?? '') === 'sending_720p30',
        'fanout event should expose computed allowed state'
    );
    videochat_media_capability_plan_assert_no_forbidden_data($peerMediaEvents[0], 'call/media-state.v1 fanout');

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
