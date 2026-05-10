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

$capabilitiesByConnectionId = (array) (($plan['capabilities'] ?? [])['by_connection_id'] ?? []);
videochat_room_snapshot_media_authority_assert(isset($capabilitiesByConnectionId['conn-owner'], $capabilitiesByConnectionId['conn-peer']), 'authoritative plan must carry room capabilities by connection');
videochat_room_snapshot_media_authority_assert((bool) (($capabilitiesByConnectionId['conn-owner']['media'] ?? [])['camera_720p30'] ?? false), 'owner 720p30 capability missing');

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
videochat_room_snapshot_media_authority_assert(($stateByConnectionId['conn-peer']['media_state'] ?? '') === 'blocked_capability', 'peer media state must block non-720p capability from the authoritative plan');

$gossip = is_array($plan['gossip'] ?? null) ? $plan['gossip'] : [];
videochat_room_snapshot_media_authority_assert((string) (($gossip['topology'] ?? [])['type'] ?? '') === 'topology_hint', 'authoritative plan must carry gossip topology');
videochat_room_snapshot_media_authority_assert((bool) (($gossip['readiness'] ?? [])['topology_ready'] ?? false), 'authoritative plan must carry topology readiness');
videochat_room_snapshot_media_authority_assert((bool) (($gossip['readiness'] ?? [])['telemetry_ready'] ?? false), 'authoritative plan must carry telemetry readiness');

$diagnostics = is_array($plan['diagnostics'] ?? null) ? $plan['diagnostics'] : [];
$counters = is_array($diagnostics['gossip_counters'] ?? null) ? $diagnostics['gossip_counters'] : [];
videochat_room_snapshot_media_authority_assert((int) ($counters['sent'] ?? 0) === 12, 'diagnostics counters must include sent total');
videochat_room_snapshot_media_authority_assert((int) ($counters['rtc_datachannel_sends'] ?? 0) === 9, 'diagnostics counters must include RTC data-channel sends');

$signatureWithAuthority = videochat_realtime_room_snapshot_signature($snapshot);
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
