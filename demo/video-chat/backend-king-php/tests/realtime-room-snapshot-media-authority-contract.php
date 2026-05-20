<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth_rbac.php';
require_once __DIR__ . '/../domain/realtime/realtime_room_snapshot.php';

function videochat_room_snapshot_media_authority_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-room-snapshot-media-authority-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_room_snapshot_media_authority_connection(
    int $userId,
    string $displayName,
    string $connectionId,
    string $callRole = 'participant'
): array {
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => 'user',
        ],
        'session-' . $connectionId,
        $connectionId,
        'socket-' . $connectionId,
        'authority-room'
    );
    $connection['active_call_id'] = 'authority-call';
    $connection['requested_call_id'] = 'authority-call';
    $connection['call_role'] = $callRole;
    $connection['effective_call_role'] = $callRole;

    return $connection;
}

$state = videochat_presence_state_init();
$owner = videochat_room_snapshot_media_authority_connection(901, 'Owner', 'conn-owner', 'owner');
$peer = videochat_room_snapshot_media_authority_connection(902, 'Peer', 'conn-peer');
$sender = static fn (mixed $_socket, array $_payload): bool => true;

$ownerJoin = videochat_presence_join_room($state, $owner, 'authority-room', $sender);
$owner = is_array($ownerJoin['connection'] ?? null) ? $ownerJoin['connection'] : $owner;
$peerJoin = videochat_presence_join_room($state, $peer, 'authority-room', $sender);
$peer = is_array($peerJoin['connection'] ?? null) ? $peerJoin['connection'] : $peer;

$state['client_capabilities'] = [
    'conn-owner' => videochat_client_capabilities_public_projection(videochat_client_capabilities_normalize([
        'schema_version' => videochat_client_capabilities_schema_version(),
        'participant_session_id' => 'conn-owner',
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
        'token' => 'must-not-leak',
        'sdp' => "v=0\r\nsecret",
    ])),
    'conn-peer' => videochat_client_capabilities_public_projection(videochat_client_capabilities_normalize([
        'schema_version' => videochat_client_capabilities_schema_version(),
        'participant_session_id' => 'conn-peer',
        'media' => [
            'camera' => true,
            'camera_720p30' => false,
            'microphone' => true,
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
    ])),
];

$state['gossipmesh_telemetry']['authority-room'] = [
    'room_id' => 'authority-room',
    'call_id' => 'authority-call',
    'updated_at_ms' => 1_778_393_600_000,
    'peer_count' => 2,
    'transports' => ['rtc_datachannel' => 2],
    'totals' => [
        'sent' => 12,
        'received' => 11,
        'forwarded' => 3,
        'duplicates' => 1,
        'rtc_datachannel_sends' => 9,
        'topology_repairs_requested' => 0,
    ],
    'rollout_gate' => [
        'telemetry_ready' => true,
        'active_allowed' => true,
        'sfu_first' => false,
    ],
];

$openDatabase = static function (): PDO {
    throw new RuntimeException('database intentionally unavailable for media authority snapshot contract');
};

$snapshot = videochat_realtime_room_snapshot_payload($state, $owner, $openDatabase, 'media_authority_contract', 1_778_393_600_000);
$plan = is_array($snapshot['media_session_plan'] ?? null) ? $snapshot['media_session_plan'] : [];

videochat_room_snapshot_media_authority_assert((string) ($snapshot['type'] ?? '') === 'room/snapshot', 'snapshot type mismatch');
videochat_room_snapshot_media_authority_assert(($plan['schema_version'] ?? '') === videochat_media_session_plan_schema_version(), 'media plan schema mismatch');
videochat_room_snapshot_media_authority_assert((bool) ($plan['authoritative'] ?? false), 'media_session_plan must be authoritative');
videochat_room_snapshot_media_authority_assert((string) ($plan['authority'] ?? '') === 'room_snapshot', 'media authority source mismatch');
videochat_room_snapshot_media_authority_assert(!isset($snapshot['media']), 'snapshot must not expose a parallel legacy media object');
videochat_room_snapshot_media_authority_assert(
    (array) ($plan['session_state_catalog'] ?? []) === ['pending', 'connecting', 'gossip_720p30', 'gossip_360p30', 'gossip_360p5', 'sfu_720p30', 'sfu_320p30', 'ready', 'failed'],
    'authoritative plan must publish canonical session-state catalog'
);

$expectedLadderIds = ['gossip_720p30', 'gossip_360p30', 'gossip_360p5', 'sfu_720p30', 'sfu_320p30'];
$ladder = (array) ($plan['ladder'] ?? []);
videochat_room_snapshot_media_authority_assert(array_column($ladder, 'plan_id') === $expectedLadderIds, 'authoritative plan ladder order mismatch');
videochat_room_snapshot_media_authority_assert(array_column($ladder, 'transport') === ['gossip', 'gossip', 'gossip', 'sfu', 'sfu'], 'authoritative plan ladder transport mismatch');
videochat_room_snapshot_media_authority_assert(array_column($ladder, 'profile') === ['720p30', '360p30', '360p5', '720p30', '320p30'], 'authoritative plan ladder profile mismatch');
videochat_room_snapshot_media_authority_assert(array_column($ladder, 'render_window_ms') === [30_000, 30_000, 30_000, 30_000, 30_000], 'authoritative plan ladder render window mismatch');
videochat_room_snapshot_media_authority_assert((string) ($ladder[3]['selected_by'] ?? '') === 'orchestrator', 'SFU 720p30 must be orchestrator-selected');
videochat_room_snapshot_media_authority_assert((string) ($ladder[4]['selection_gate'] ?? '') === 'after_sfu_720p30_render_failure', 'SFU 320p30 selection gate mismatch');

$selectedPlan = (array) ($plan['selected_plan'] ?? []);
videochat_room_snapshot_media_authority_assert((string) ($selectedPlan['plan_id'] ?? '') === 'gossip_720p30', 'selected plan id mismatch');
videochat_room_snapshot_media_authority_assert((string) ($selectedPlan['transport'] ?? '') === 'gossip', 'selected plan transport mismatch');
videochat_room_snapshot_media_authority_assert((string) ($selectedPlan['profile'] ?? '') === '720p30', 'selected plan profile mismatch');
videochat_room_snapshot_media_authority_assert((string) ($selectedPlan['reason'] ?? '') === 'initial_gossip_720p30', 'selected plan reason mismatch');
videochat_room_snapshot_media_authority_assert((string) ($selectedPlan['session_state'] ?? '') === 'gossip_720p30', 'selected plan session-state mismatch');
videochat_room_snapshot_media_authority_assert((int) ($selectedPlan['selected_at_ms'] ?? 0) === 1_778_393_600_000, 'selected plan timestamp mismatch');
videochat_room_snapshot_media_authority_assert((int) ($selectedPlan['updated_at_ms'] ?? 0) === 1_778_393_600_000, 'selected plan updated timestamp mismatch');
videochat_room_snapshot_media_authority_assert((array) ($selectedPlan['participant_session_ids'] ?? []) === ['conn-owner', 'conn-peer'], 'selected plan participant set mismatch');

$capabilitiesByConnectionId = (array) (($plan['capabilities'] ?? [])['by_connection_id'] ?? []);
videochat_room_snapshot_media_authority_assert(isset($capabilitiesByConnectionId['conn-owner'], $capabilitiesByConnectionId['conn-peer']), 'authoritative plan must carry room capabilities by connection');
videochat_room_snapshot_media_authority_assert((bool) (($capabilitiesByConnectionId['conn-owner']['media'] ?? [])['camera_720p30'] ?? false), 'owner 720p30 capability missing');
$capabilitySummary = (array) ($plan['capability_summary'] ?? []);
videochat_room_snapshot_media_authority_assert((int) ($capabilitySummary['participant_count'] ?? 0) === 2, 'capability summary participant count mismatch');
videochat_room_snapshot_media_authority_assert((int) ($capabilitySummary['camera_720p30_count'] ?? 0) === 1, 'capability summary 720p30 count mismatch');
videochat_room_snapshot_media_authority_assert(isset(($capabilitySummary['by_connection_id'] ?? [])['conn-owner']), 'capability summary by-connection map missing owner');

foreach ((array) ($snapshot['participants'] ?? []) as $participant) {
    videochat_room_snapshot_media_authority_assert(!array_key_exists('client_capabilities', (array) $participant), 'participants must not carry legacy client_capabilities truth');
}

$participantMediaState = (array) ($plan['participant_media_state'] ?? []);
videochat_room_snapshot_media_authority_assert(count($participantMediaState) === 2, 'participant media state count mismatch');
$stateByConnectionId = [];
foreach ($participantMediaState as $row) {
    if (is_array($row)) {
        $stateByConnectionId[(string) ($row['connection_id'] ?? '')] = $row;
    }
}
videochat_room_snapshot_media_authority_assert(($stateByConnectionId['conn-owner']['media_state'] ?? '') === 'streaming_720p30', 'owner media state must come from the authoritative plan');
videochat_room_snapshot_media_authority_assert(($stateByConnectionId['conn-peer']['media_state'] ?? '') === 'audio_only', 'peer non-720p video must not block authoritative native talk audio');
videochat_room_snapshot_media_authority_assert(($stateByConnectionId['conn-peer']['transport'] ?? '') === '', 'peer native talk audio must not require SFU/Gossip video transport');
videochat_room_snapshot_media_authority_assert(($stateByConnectionId['conn-peer']['security_policy'] ?? '') === 'transport_only', 'peer native talk audio must not require MediaSecurity protected-frame transforms');

$gossip = is_array($plan['gossip'] ?? null) ? $plan['gossip'] : [];
videochat_room_snapshot_media_authority_assert((string) (($gossip['topology'] ?? [])['type'] ?? '') === 'topology_hint', 'authoritative plan must carry gossip topology');
videochat_room_snapshot_media_authority_assert((bool) (($gossip['readiness'] ?? [])['topology_ready'] ?? false), 'authoritative plan must carry topology readiness');
videochat_room_snapshot_media_authority_assert((bool) (($gossip['readiness'] ?? [])['telemetry_ready'] ?? false), 'authoritative plan must carry telemetry readiness');

$diagnostics = is_array($plan['diagnostics'] ?? null) ? $plan['diagnostics'] : [];
$counters = is_array($diagnostics['gossip_counters'] ?? null) ? $diagnostics['gossip_counters'] : [];
videochat_room_snapshot_media_authority_assert((int) ($counters['sent'] ?? 0) === 12, 'diagnostics counters must include sent total');
videochat_room_snapshot_media_authority_assert((int) ($counters['rtc_datachannel_sends'] ?? 0) === 9, 'diagnostics counters must include RTC data-channel sends');

$signatureWithAuthority = videochat_realtime_room_snapshot_signature($snapshot);
$snapshotAgain = videochat_realtime_room_snapshot_payload($state, $owner, $openDatabase, 'media_authority_contract', 1_778_393_600_000);
videochat_room_snapshot_media_authority_assert(
    (array) (($snapshotAgain['media_session_plan'] ?? [])['selected_plan'] ?? []) === $selectedPlan,
    'selected plan must be idempotent across equivalent room snapshots'
);
$snapshotWithoutAuthorityDetails = $snapshot;
unset($snapshotWithoutAuthorityDetails['media_session_plan']['capabilities']);
videochat_room_snapshot_media_authority_assert(
    $signatureWithAuthority !== videochat_realtime_room_snapshot_signature($snapshotWithoutAuthorityDetails),
    'snapshot signature must include authoritative capability truth'
);

$encodedPlan = json_encode($plan, JSON_UNESCAPED_SLASHES) ?: '';
foreach (['must-not-leak', 'v=0', 'secret'] as $needle) {
    videochat_room_snapshot_media_authority_assert(!str_contains($encodedPlan, $needle), 'authoritative media plan leaked forbidden value: ' . $needle);
}

fwrite(STDOUT, "[realtime-room-snapshot-media-authority-contract] PASS\n");
