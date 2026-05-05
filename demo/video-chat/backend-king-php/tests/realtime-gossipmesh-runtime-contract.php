<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/realtime_gossipmesh.php';
require_once __DIR__ . '/../support/auth_rbac.php';
require_once __DIR__ . '/../domain/calls/call_management_query.php';
require_once __DIR__ . '/../domain/realtime/realtime_connection_contract.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_presence_db.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby_sync.php';
require_once __DIR__ . '/../domain/realtime/realtime_room_snapshot.php';
require_once __DIR__ . '/../http/module_realtime_websocket_commands.php';

$GLOBALS['gossipmesh_sent_frames'] = [];

if (!function_exists('king_websocket_send')) {
    function king_websocket_send(mixed $socket, string $payload): bool
    {
        $decoded = json_decode($payload, true);
        $GLOBALS['gossipmesh_sent_frames'][] = [
            'socket' => $socket,
            'payload' => is_array($decoded) ? $decoded : [],
        ];

        return true;
    }
}

function videochat_gossipmesh_test_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-gossipmesh-runtime-contract] FAIL: {$message}\n");
    exit(1);
}

$members = [
    [
        'participant_id' => 'owner-1',
        'user_id' => '10',
        'display_name' => 'Owner',
        'invite_state' => 'owner',
        'relay_score' => 90,
        'activity_score' => 7,
    ],
    [
        'participant_id' => 'user-2',
        'user_id' => '20',
        'display_name' => 'User Two',
        'invite_state' => 'allowed',
        'relay_score' => 40,
    ],
    [
        'participant_id' => 'queued-3',
        'user_id' => '30',
        'display_name' => 'Queued',
        'invite_state' => 'pending',
        'relay_score' => 100,
    ],
    [
        'participant_id' => 'bad-4',
        'user_id' => '40',
        'display_name' => 'Bad',
        'invite_state' => 'allowed',
        'raw_media_key' => 'must-not-pass',
    ],
    [
        'participant_id' => 'mod-5',
        'user_id' => '50',
        'display_name' => 'Moderator',
        'invite_state' => 'moderator',
        'relay_score' => 70,
    ],
    [
        'participant_id' => 'left-6',
        'user_id' => '60',
        'display_name' => 'Left User',
        'invite_state' => 'allowed',
        'left_at' => '2026-05-04T00:05:00Z',
        'relay_score' => 95,
    ],
    [
        'participant_id' => 'forced-left-7',
        'user_id' => '70',
        'display_name' => 'Forced Left',
        'admitted' => true,
        'invite_state' => 'left',
        'relay_score' => 95,
    ],
    [
        'participant_id' => 'state-left-8',
        'user_id' => '80',
        'display_name' => 'State Left',
        'invite_state' => 'allowed',
        'state' => 'left',
        'relay_score' => 95,
    ],
];

$plan = videochat_gossipmesh_plan_topology('call-alpha', 'room-alpha', $members, [
    'seed' => 'contract',
    'max_neighbors' => 2,
    'forward_count' => 2,
]);
$planAgain = videochat_gossipmesh_plan_topology('call-alpha', 'room-alpha', $members, [
    'seed' => 'contract',
    'max_neighbors' => 2,
    'forward_count' => 2,
]);

videochat_gossipmesh_test_assert($plan === $planAgain, 'topology planning must be deterministic');
videochat_gossipmesh_test_assert($plan['contract'] === VIDEOCHAT_GOSSIPMESH_CONTRACT, 'contract name mismatch');
videochat_gossipmesh_test_assert($plan['authority'] === 'server', 'topology must be server authoritative');
videochat_gossipmesh_test_assert($plan['runtime_path'] === 'wlvc_sfu', 'runtime path must stay SFU-bound for this port');
videochat_gossipmesh_test_assert(
    $plan['envelope_contract'] === 'king-video-chat-protected-media-transport-envelope',
    'protected envelope contract must be advertised'
);
videochat_gossipmesh_test_assert(count($plan['members']) === 3, 'only admitted members without forbidden payload fields are eligible');
videochat_gossipmesh_test_assert($plan['rejected_members'] === 5, 'pending, secret-bearing, and left members must be rejected');
videochat_gossipmesh_test_assert($plan['ttl'] === 3, 'small room TTL estimate mismatch');
videochat_gossipmesh_test_assert($plan['forward_count'] === VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT, 'requested forward_count below expander minimum must clamp to 3');
videochat_gossipmesh_test_assert(VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT === 3, 'backend minimum expander fanout must be 3');
videochat_gossipmesh_test_assert(VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS === 4, 'backend default topology neighbor degree must remain 4');
videochat_gossipmesh_test_assert(VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT === 4, 'backend default forward count must remain 4');
videochat_gossipmesh_test_assert(VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS === 5, 'backend hard topology/repair/forward fanout cap must be 5');

$expanderMembers = [];
for ($i = 1; $i <= 5; $i++) {
    $expanderMembers[] = [
        'participant_id' => 'expander-' . $i,
        'user_id' => (string) (100 + $i),
        'display_name' => 'Expander ' . $i,
        'invite_state' => 'allowed',
    ];
}
$expanderPlan = videochat_gossipmesh_plan_topology('call-expander', 'room-expander', $expanderMembers, [
    'seed' => 'expander-contract',
    'max_neighbors' => 2,
    'forward_count' => 2,
]);
videochat_gossipmesh_test_assert($expanderPlan['forward_count'] === VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT, 'expander plan forward count must not fall below degree 3');
foreach ($expanderPlan['topology'] as $memberId => $neighbors) {
    videochat_gossipmesh_test_assert(count($neighbors) >= VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT, 'expander topology must not degrade to a cycle graph for ' . $memberId);
    videochat_gossipmesh_test_assert(count($neighbors) <= VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS, 'expander topology must respect hard neighbor cap for ' . $memberId);
}

$cappedMembers = [];
for ($i = 1; $i <= 8; $i++) {
    $cappedMembers[] = [
        'participant_id' => 'capped-' . $i,
        'user_id' => (string) (200 + $i),
        'display_name' => 'Capped ' . $i,
        'invite_state' => 'allowed',
    ];
}
$cappedPlan = videochat_gossipmesh_plan_topology('call-capped', 'room-capped', $cappedMembers, [
    'seed' => 'cap-contract',
    'max_neighbors' => 99,
    'forward_count' => 99,
]);
videochat_gossipmesh_test_assert($cappedPlan['forward_count'] === VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS, 'backend forward count must clamp to hard cap 5');
foreach ($cappedPlan['topology'] as $memberId => $neighbors) {
    videochat_gossipmesh_test_assert(count($neighbors) === VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS, 'backend topology creation must cap neighbors at 5 for ' . $memberId);
}

$weakeningProbePlan = videochat_gossipmesh_plan_topology('call-alpha', 'room-alpha', [
    [
        'participant_id' => 'safe-1',
        'user_id' => '10',
        'invite_state' => 'allowed',
    ],
    [
        'participant_id' => 'sdp-2',
        'user_id' => '20',
        'invite_state' => 'allowed',
        'sdp' => 'offer',
    ],
    [
        'participant_id' => 'ice-3',
        'user_id' => '30',
        'invite_state' => 'allowed',
        'ice_candidate' => 'candidate',
    ],
    [
        'participant_id' => 'socket-4',
        'user_id' => '40',
        'invite_state' => 'allowed',
        'socket' => 'raw-socket',
    ],
], ['seed' => 'weakening']);
videochat_gossipmesh_test_assert(count($weakeningProbePlan['members']) === 1, 'weakened member plan must keep only clean admitted members');
videochat_gossipmesh_test_assert($weakeningProbePlan['rejected_members'] === 3, 'weakened member plan must reject SDP, ICE, and socket fields');

$knownIds = array_map(static fn(array $member): string => $member['id'], $plan['members']);
sort($knownIds);
videochat_gossipmesh_test_assert($knownIds === ['mod-5', 'owner-1', 'user-2'], 'eligible member ids mismatch');

foreach ($plan['topology'] as $memberId => $neighbors) {
    videochat_gossipmesh_test_assert(in_array($memberId, $knownIds, true), 'topology contains unknown member id');
    videochat_gossipmesh_test_assert(count($neighbors) <= VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS, 'neighbor count must be bounded');
    foreach ($neighbors as $neighborId) {
        videochat_gossipmesh_test_assert($neighborId !== $memberId, 'topology must not self-connect');
        videochat_gossipmesh_test_assert(in_array($neighborId, $knownIds, true), 'topology contains unknown neighbor');
    }
}

videochat_gossipmesh_test_assert($plan['relay_candidates'][0] === 'owner-1', 'relay candidates should rank by relay score');
videochat_gossipmesh_test_assert(!in_array('queued-3', $plan['relay_candidates'], true), 'queued users must not be relay candidates');
videochat_gossipmesh_test_assert(!in_array('left-6', $plan['relay_candidates'], true), 'left users must not be relay candidates');
videochat_gossipmesh_test_assert(!in_array('forced-left-7', $plan['relay_candidates'], true), 'left state must override admitted=true for relay candidates');
videochat_gossipmesh_test_assert(!in_array('state-left-8', $plan['relay_candidates'], true), 'left state must override allowed invite_state for relay candidates');

$roomParticipantMembers = videochat_gossipmesh_members_from_room_participants([
    [
        'user_id' => 10,
        'display_name' => 'Owner',
        'call_role' => 'owner',
        'invite_state' => 'allowed',
    ],
    [
        'user_id' => 20,
        'display_name' => 'Left Participant',
        'invite_state' => 'allowed',
        'left_at' => '2026-05-04T00:10:00Z',
    ],
    [
        'user_id' => 30,
        'display_name' => 'Participant',
        'state' => 'joined',
    ],
]);
$roomParticipantPlan = videochat_gossipmesh_plan_topology('call-room-participants', 'room-alpha', $roomParticipantMembers, [
    'seed' => 'room-participant-left-contract',
    'max_neighbors' => 3,
    'forward_count' => 3,
]);
$roomParticipantIds = array_map(static fn(array $member): string => $member['id'], $roomParticipantPlan['members']);
sort($roomParticipantIds);
videochat_gossipmesh_test_assert($roomParticipantIds === ['10', '30'], 'room participant conversion must preserve left markers for pruning');
videochat_gossipmesh_test_assert(!array_key_exists('20', $roomParticipantPlan['topology']), 'left room participants must not receive topology assignments');

$first = videochat_gossipmesh_accept_frame_once([], 'owner-1', 1, 2);
videochat_gossipmesh_test_assert($first['accepted'] === true && $first['duplicate'] === false, 'first frame should be accepted');
$duplicate = videochat_gossipmesh_accept_frame_once($first['seen_window'], 'owner-1', 1, 2);
videochat_gossipmesh_test_assert($duplicate['accepted'] === false && $duplicate['duplicate'] === true, 'duplicate frame should be rejected');
$second = videochat_gossipmesh_accept_frame_once($first['seen_window'], 'owner-1', 2, 2);
$third = videochat_gossipmesh_accept_frame_once($second['seen_window'], 'owner-1', 3, 2);
videochat_gossipmesh_test_assert($third['seen_window'] === ['owner-1:2', 'owner-1:3'], 'seen window must stay bounded');

$targets = videochat_gossipmesh_select_forward_targets(['owner-1', 'user-2', 'mod-5'], 'owner-1', 42, 3, 2);
videochat_gossipmesh_test_assert(count($targets) === 2, 'forward target count mismatch');
videochat_gossipmesh_test_assert(!in_array('owner-1', $targets, true), 'publisher must not be a forward target');
videochat_gossipmesh_test_assert(
    videochat_gossipmesh_select_forward_targets(['user-2', 'mod-5'], 'owner-1', 42, 0, 2) === [],
    'expired TTL must not forward'
);

$envelope = [
    'envelope_contract' => VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT,
    'protected_frame' => rtrim(strtr(base64_encode('KPMF contract frame'), '+/', '-_'), '='),
];
$route = videochat_gossipmesh_plan_message_route($plan, [], 'owner-1', 50, 3, $envelope, []);
videochat_gossipmesh_test_assert($route['ok'] === true, 'valid protected envelope should route');
videochat_gossipmesh_test_assert($route['next_ttl'] === 2, 'route should decrement TTL');
videochat_gossipmesh_test_assert(count($route['direct_targets']) + count($route['relay_targets']) <= $plan['forward_count'], 'route fanout must be bounded');
videochat_gossipmesh_test_assert($route['seen_window'] === ['owner-1:50'], 'route should update duplicate window');

$duplicateRoute = videochat_gossipmesh_plan_message_route($plan, $route['seen_window'], 'owner-1', 50, 3, $envelope, []);
videochat_gossipmesh_test_assert($duplicateRoute['ok'] === false, 'duplicate route should fail');
videochat_gossipmesh_test_assert($duplicateRoute['duplicate'] === true, 'duplicate route should be classified');
videochat_gossipmesh_test_assert($duplicateRoute['error'] === 'duplicate_frame', 'duplicate error code mismatch');

$expiredRoute = videochat_gossipmesh_plan_message_route($plan, [], 'owner-1', 51, 0, $envelope, []);
videochat_gossipmesh_test_assert($expiredRoute['ok'] === true, 'expired TTL route should be accepted for local delivery');
videochat_gossipmesh_test_assert($expiredRoute['direct_targets'] === [] && $expiredRoute['relay_targets'] === [], 'expired TTL route must not forward');

$badEnvelope = videochat_gossipmesh_plan_message_route($plan, [], 'owner-1', 52, 3, [
    'envelope_contract' => VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT,
    'protected_frame' => 'KPMF',
    'data' => [1, 2, 3],
], []);
videochat_gossipmesh_test_assert($badEnvelope['ok'] === false, 'legacy plaintext data must fail');
videochat_gossipmesh_test_assert($badEnvelope['error'] === 'legacy_plaintext_data_forbidden', 'legacy plaintext data error mismatch');

foreach (['plaintext_frame', 'sdp', 'ice_candidate', 'socket', 'ip'] as $forbiddenField) {
    $weakEnvelope = videochat_gossipmesh_plan_message_route($plan, [], 'owner-1', 520 + strlen($forbiddenField), 3, [
        'envelope_contract' => VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT,
        'protected_frame' => 'KPMF',
        $forbiddenField => 'experiment-weakened-field',
    ], []);
    videochat_gossipmesh_test_assert($weakEnvelope['ok'] === false, 'weakened envelope field must fail: ' . $forbiddenField);
    videochat_gossipmesh_test_assert($weakEnvelope['error'] === 'forbidden_plaintext_or_secret_field', 'weakened envelope error mismatch: ' . $forbiddenField);
}

$missingEnvelope = videochat_gossipmesh_plan_message_route($plan, [], 'owner-1', 53, 3, [
    'protected_frame' => 'KPMF',
], []);
videochat_gossipmesh_test_assert($missingEnvelope['ok'] === false, 'missing envelope contract must fail');
videochat_gossipmesh_test_assert($missingEnvelope['error'] === 'missing_protected_envelope_contract', 'missing envelope error mismatch');

$routeTargetProbe = videochat_gossipmesh_select_forward_targets($plan['topology']['owner-1'], 'owner-1', 54, 3, $plan['forward_count']);
videochat_gossipmesh_test_assert(count($routeTargetProbe) > 0, 'route probe needs at least one target for relay test');
$failedTarget = $routeTargetProbe[0];
$relayRoute = videochat_gossipmesh_plan_message_route($plan, [], 'owner-1', 54, 3, $envelope, [$failedTarget => true]);
videochat_gossipmesh_test_assert($relayRoute['ok'] === true, 'failed direct target should use relay fallback when available');
videochat_gossipmesh_test_assert(count($relayRoute['relay_targets']) === 1, 'relay fallback target count mismatch');
videochat_gossipmesh_test_assert($relayRoute['relay_targets'][0]['target_id'] === $failedTarget, 'relay fallback target id mismatch');
videochat_gossipmesh_test_assert($relayRoute['relay_targets'][0]['relay_id'] !== $failedTarget, 'relay must not be failed target');
videochat_gossipmesh_test_assert($relayRoute['relay_targets'][0]['relay_id'] !== 'owner-1', 'relay must not be publisher');

$relayUnavailable = videochat_gossipmesh_plan_message_route(
    [...$plan, 'relay_candidates' => [$failedTarget, 'owner-1']],
    [],
    'owner-1',
    55,
    3,
    $envelope,
    [$failedTarget => true]
);
videochat_gossipmesh_test_assert($relayUnavailable['ok'] === false, 'missing relay candidate should fail');
videochat_gossipmesh_test_assert($relayUnavailable['error'] === 'relay_unavailable', 'relay unavailable error mismatch');

$unknownPublisher = videochat_gossipmesh_plan_message_route($plan, [], 'ghost', 56, 3, $envelope, []);
videochat_gossipmesh_test_assert($unknownPublisher['ok'] === false, 'unknown publisher should fail');
videochat_gossipmesh_test_assert($unknownPublisher['error'] === 'publisher_not_in_topology', 'unknown publisher error mismatch');

$repairFrame = json_encode([
    'type' => 'gossip/topology-repair/request',
    'lane' => 'ops',
    'payload' => [
        'kind' => 'gossip_topology_repair_request',
        'room_id' => 'room-alpha',
        'call_id' => 'call-alpha',
        'peer_id' => '10',
        'lost_peer_id' => '20',
        'lost_neighbor_peer_id' => '20',
        'reason' => 'carrier_lost',
        'data_lane_mode' => 'native',
        'link_health' => [
            'state' => 'failed',
            'transport' => 'rtc_datachannel',
            'rtt_ms' => 38,
            'loss_percent' => 100,
        ],
    ],
], JSON_UNESCAPED_SLASHES);
$repairCommand = videochat_gossipmesh_decode_topology_repair_request((string) $repairFrame);
videochat_gossipmesh_test_assert((bool) ($repairCommand['ok'] ?? false), 'topology repair request should decode on ops lane');
videochat_gossipmesh_test_assert((string) ($repairCommand['peer_id'] ?? '') === '10', 'repair peer_id decode mismatch');
videochat_gossipmesh_test_assert((string) ($repairCommand['lost_peer_id'] ?? '') === '20', 'repair lost peer decode mismatch');

$telemetryFrame = json_encode([
    'type' => 'gossip/telemetry/snapshot',
    'lane' => 'ops',
    'payload' => [
        'kind' => 'gossip_telemetry_snapshot',
        'room_id' => 'room-alpha',
        'call_id' => 'call-alpha',
        'peer_id' => '10',
        'transport_kind' => 'rtc_datachannel',
        'data_lane_mode' => 'active',
        'rollout_strategy' => 'sfu_first_explicit',
        'neighbor_count' => 4,
        'topology_epoch' => 123456,
        'counters' => [
            'sent' => 7,
            'received' => 5,
            'forwarded' => 3,
            'dropped' => 2,
            'duplicates' => 1,
            'server_fanout_avoided' => 12,
            'peer_outbound_fanout' => 9,
            'rtc_datachannel_sends' => 8,
            'topology_repairs_requested' => 1,
            'unknown_counter_must_be_dropped' => 999,
        ],
    ],
], JSON_UNESCAPED_SLASHES);
$telemetryCommand = videochat_gossipmesh_decode_telemetry_snapshot((string) $telemetryFrame);
videochat_gossipmesh_test_assert((bool) ($telemetryCommand['ok'] ?? false), 'telemetry snapshot should decode on ops lane');
videochat_gossipmesh_test_assert((string) ($telemetryCommand['transport_kind'] ?? '') === 'rtc_datachannel', 'telemetry transport label mismatch');
videochat_gossipmesh_test_assert((string) ($telemetryCommand['rollout_strategy'] ?? '') === 'sfu_first_explicit', 'telemetry rollout strategy must remain explicit SFU-first');
videochat_gossipmesh_test_assert((int) (($telemetryCommand['counters'] ?? [])['sent'] ?? -1) === 7, 'telemetry counters must include sent');
videochat_gossipmesh_test_assert(!array_key_exists('unknown_counter_must_be_dropped', (array) ($telemetryCommand['counters'] ?? [])), 'telemetry counters must drop unknown counters');

$telemetryState = [];
$aggregate = videochat_gossipmesh_aggregate_telemetry_snapshot($telemetryState, $telemetryCommand, 123456789);
videochat_gossipmesh_test_assert((bool) ($aggregate['ok'] ?? false), 'telemetry snapshot should aggregate');
videochat_gossipmesh_test_assert((int) (($aggregate['totals'] ?? [])['sent'] ?? -1) === 7, 'telemetry aggregate sent total mismatch');
videochat_gossipmesh_test_assert((int) (($aggregate['transports'] ?? [])['rtc_datachannel'] ?? -1) === 1, 'telemetry aggregate transport label mismatch');
videochat_gossipmesh_test_assert((string) (($aggregate['rollout_gate'] ?? [])['kind'] ?? '') === 'gossip_rollout_gate_state', 'telemetry aggregate must expose rollout gate state');
videochat_gossipmesh_test_assert(array_key_exists('duplicate_rate', (array) ($aggregate['rollout_gate'] ?? [])), 'telemetry rollout gate must expose duplicate rate');
videochat_gossipmesh_test_assert(array_key_exists('ttl_exhaustion_rate', (array) ($aggregate['rollout_gate'] ?? [])), 'telemetry rollout gate must expose TTL exhaustion rate');
videochat_gossipmesh_test_assert(array_key_exists('late_drop_rate', (array) ($aggregate['rollout_gate'] ?? [])), 'telemetry rollout gate must expose late drop rate');
videochat_gossipmesh_test_assert(array_key_exists('repair_rate', (array) ($aggregate['rollout_gate'] ?? [])), 'telemetry rollout gate must expose repair rate');
$storedTelemetryJson = json_encode($telemetryState['gossipmesh_telemetry']['room-alpha'] ?? [], JSON_UNESCAPED_SLASHES) ?: '';
foreach (['protected_frame', 'data_base64', 'sdp', 'ice_candidate', 'raw_media_key', 'unknown_counter_must_be_dropped'] as $unsafeTelemetryToken) {
    videochat_gossipmesh_test_assert(strpos($storedTelemetryJson, $unsafeTelemetryToken) === false, 'telemetry aggregate must not store unsafe token: ' . $unsafeTelemetryToken);
}

$badTelemetryLane = videochat_gossipmesh_decode_telemetry_snapshot(json_encode([
    'type' => 'gossip/telemetry/snapshot',
    'lane' => 'data',
    'payload' => [
        'room_id' => 'room-alpha',
        'call_id' => 'call-alpha',
        'peer_id' => '10',
    ],
], JSON_UNESCAPED_SLASHES));
videochat_gossipmesh_test_assert(!(bool) ($badTelemetryLane['ok'] ?? true), 'telemetry snapshot must reject data lane');
videochat_gossipmesh_test_assert((string) ($badTelemetryLane['error'] ?? '') === 'invalid_lane', 'telemetry invalid lane error mismatch');

foreach (['sdp', 'candidate', 'protected_frame', 'data_base64', 'token'] as $unsafeTelemetryField) {
    $unsafeTelemetry = videochat_gossipmesh_decode_telemetry_snapshot(json_encode([
        'type' => 'gossip/telemetry/snapshot',
        'lane' => 'ops',
        'payload' => [
            'room_id' => 'room-alpha',
            'call_id' => 'call-alpha',
            'peer_id' => '10',
            $unsafeTelemetryField => 'unsafe',
            'counters' => ['sent' => 1],
        ],
    ], JSON_UNESCAPED_SLASHES));
    videochat_gossipmesh_test_assert(!(bool) ($unsafeTelemetry['ok'] ?? true), 'telemetry must reject unsafe field: ' . $unsafeTelemetryField);
    videochat_gossipmesh_test_assert((string) ($unsafeTelemetry['error'] ?? '') === 'forbidden_media_or_signaling_field', 'unsafe telemetry error mismatch: ' . $unsafeTelemetryField);
}

$healthObjectKey = videochat_gossipmesh_topology_health_object_key('room-alpha', 'call-alpha', '10', '20');
videochat_gossipmesh_test_assert(str_starts_with($healthObjectKey, VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_OBJECT_PREFIX), 'topology health object key prefix mismatch');
videochat_gossipmesh_test_assert(strlen($healthObjectKey) <= 127, 'topology health object key must fit King object_store object id bounds');
$healthObservation = videochat_gossipmesh_sanitize_topology_health_observation($repairCommand, 1_000_000);
videochat_gossipmesh_test_assert((string) ($healthObservation['object_key'] ?? '') === $healthObjectKey, 'topology health observation object key mismatch');
videochat_gossipmesh_test_assert((string) ($healthObservation['kind'] ?? '') === VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_KIND, 'topology health observation kind mismatch');
videochat_gossipmesh_test_assert((int) ($healthObservation['schema_version'] ?? 0) === VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_SCHEMA_VERSION, 'topology health schema version mismatch');
videochat_gossipmesh_test_assert((int) ($healthObservation['failed_until_unix_ms'] ?? 0) === 1_120_000, 'topology health cooldown window mismatch');
videochat_gossipmesh_test_assert((string) ($healthObservation['pair_key'] ?? '') === videochat_gossipmesh_link_pair_key('10', '20'), 'topology health failed pair key mismatch');
videochat_gossipmesh_test_assert(strpos(json_encode($healthObservation, JSON_UNESCAPED_SLASHES) ?: '', 'socket') === false, 'topology health observation must not include socket fields');
videochat_gossipmesh_test_assert(strpos(json_encode($healthObservation, JSON_UNESCAPED_SLASHES) ?: '', 'sdp') === false, 'topology health observation must not include SDP fields');
videochat_gossipmesh_test_assert(strpos(json_encode($healthObservation, JSON_UNESCAPED_SLASHES) ?: '', 'ice') === false, 'topology health observation must not include ICE fields');
videochat_gossipmesh_test_assert(strpos(json_encode($healthObservation, JSON_UNESCAPED_SLASHES) ?: '', 'protected_frame') === false, 'topology health observation must not include media frame fields');
$freshFailedPairs = videochat_gossipmesh_recent_failed_pair_map([$healthObservation], 1_000_001);
$expiredFailedPairs = videochat_gossipmesh_recent_failed_pair_map([$healthObservation], 1_120_001);
videochat_gossipmesh_test_assert(($freshFailedPairs[videochat_gossipmesh_link_pair_key('10', '20')] ?? false) === true, 'fresh topology health observation must produce avoided failed pair');
videochat_gossipmesh_test_assert($expiredFailedPairs === [], 'expired topology health observation must leave cooldown map');

$staleTargetObservation = videochat_gossipmesh_sanitize_topology_health_observation([
    ...$repairCommand,
    'lost_peer_id' => '20',
    'reason' => 'target_not_in_room',
], 1_000_000);
$decodedStaleTargetObservation = videochat_gossipmesh_decode_topology_health_observation(
    json_encode($staleTargetObservation, JSON_UNESCAPED_SLASHES) ?: '',
    'room-alpha',
    'call-alpha',
    1_000_001,
    (string) ($staleTargetObservation['object_key'] ?? '')
);
videochat_gossipmesh_test_assert((string) (($decodedStaleTargetObservation ?? [])['reason'] ?? '') === 'target_not_in_room', 'readback decode must preserve stale target repair reason');
$staleTargetAvoidPairs = videochat_gossipmesh_recent_failed_pair_map([(array) $decodedStaleTargetObservation], 1_000_001);
videochat_gossipmesh_test_assert(($staleTargetAvoidPairs[videochat_gossipmesh_excluded_peer_pair_key('20')] ?? false) === true, 'target_not_in_room observations must mark the stale target for exclusion');
$staleTargetRepairPlan = videochat_gossipmesh_plan_topology('call-alpha', 'room-alpha', [
    ['participant_id' => '10', 'user_id' => '10', 'invite_state' => 'allowed', 'relay_score' => 80],
    ['participant_id' => '20', 'user_id' => '20', 'invite_state' => 'allowed', 'relay_score' => 100],
    ['participant_id' => '30', 'user_id' => '30', 'invite_state' => 'allowed', 'relay_score' => 70],
    ['participant_id' => '40', 'user_id' => '40', 'invite_state' => 'allowed', 'relay_score' => 60],
], [
    'seed' => 'stale-target-repair',
    'max_neighbors' => 3,
    'forward_count' => 3,
    'avoid_pairs' => $staleTargetAvoidPairs,
]);
$staleTargetRepairMemberIds = array_map(static fn(array $member): string => $member['id'], $staleTargetRepairPlan['members']);
videochat_gossipmesh_test_assert(!in_array('20', $staleTargetRepairMemberIds, true), 'target_not_in_room stale target must be removed from repair members');
videochat_gossipmesh_test_assert(!array_key_exists('20', $staleTargetRepairPlan['topology']), 'target_not_in_room stale target must not receive topology assignments');
foreach ($staleTargetRepairPlan['topology'] as $neighbors) {
    videochat_gossipmesh_test_assert(!in_array('20', $neighbors, true), 'target_not_in_room stale target must never be assigned as a neighbor');
}
videochat_gossipmesh_test_assert(!in_array('20', $staleTargetRepairPlan['relay_candidates'], true), 'target_not_in_room stale target must not be a relay candidate');

$avoidPlan = videochat_gossipmesh_plan_topology('call-alpha', 'room-alpha', [
    ['participant_id' => '10', 'user_id' => '10', 'invite_state' => 'allowed'],
    ['participant_id' => '20', 'user_id' => '20', 'invite_state' => 'allowed'],
    ['participant_id' => '30', 'user_id' => '30', 'invite_state' => 'allowed'],
    ['participant_id' => '40', 'user_id' => '40', 'invite_state' => 'allowed'],
], [
    'seed' => 'avoid-failed-pair',
    'max_neighbors' => 3,
    'forward_count' => 3,
    'avoid_pairs' => $freshFailedPairs,
]);
videochat_gossipmesh_test_assert(!in_array('20', (array) ($avoidPlan['topology']['10'] ?? []), true), 'replacement topology must avoid fresh failed pair from reporter side');
videochat_gossipmesh_test_assert(!in_array('10', (array) ($avoidPlan['topology']['20'] ?? []), true), 'replacement topology must avoid fresh failed pair from lost-neighbor side');

$GLOBALS['videochat_gossipmesh_topology_health_store_list'] = null;
$GLOBALS['videochat_gossipmesh_topology_health_store_get'] = null;
videochat_gossipmesh_test_assert(
    videochat_gossipmesh_load_recent_topology_health_observations('room-alpha', 'call-alpha', 1_000_001) === [],
    'missing object_store readback must be inert'
);

$recentReadbackObservation = videochat_gossipmesh_sanitize_topology_health_observation([
    ...$repairCommand,
    'peer_id' => '30',
    'lost_peer_id' => '40',
], 1_000_000);
$staleReadbackObservation = videochat_gossipmesh_sanitize_topology_health_observation([
    ...$repairCommand,
    'peer_id' => '10',
    'lost_peer_id' => '30',
], 500_000);
$unsafeReadbackObservation = [
    ...$recentReadbackObservation,
    'object_key' => videochat_gossipmesh_topology_health_object_key('room-alpha', 'call-alpha', '20', '40'),
    'peer_id' => '20',
    'lost_peer_id' => '40',
    'pair_key' => videochat_gossipmesh_link_pair_key('20', '40'),
    'sdp' => 'must-not-read',
];
$wrongRoomReadbackObservation = [
    ...$recentReadbackObservation,
    'room_id' => 'other-room',
];
$wrongSchemaReadbackObservation = [
    ...$recentReadbackObservation,
    'schema_version' => VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_SCHEMA_VERSION + 1,
];
$wrongKindReadbackObservation = [
    ...$recentReadbackObservation,
    'kind' => 'not_topology_health',
];
$readbackStore = [
    (string) ($recentReadbackObservation['object_key'] ?? '') => json_encode($recentReadbackObservation, JSON_UNESCAPED_SLASHES),
    (string) ($staleReadbackObservation['object_key'] ?? '') => json_encode($staleReadbackObservation, JSON_UNESCAPED_SLASHES),
    (string) ($unsafeReadbackObservation['object_key'] ?? '') => json_encode($unsafeReadbackObservation, JSON_UNESCAPED_SLASHES),
    'vcgmh_malformed_contract' => '{"contract":',
    'vcgmh_wrong_room_contract' => json_encode($wrongRoomReadbackObservation, JSON_UNESCAPED_SLASHES),
    'vcgmh_wrong_schema_contract' => json_encode($wrongSchemaReadbackObservation, JSON_UNESCAPED_SLASHES),
    'vcgmh_wrong_kind_contract' => json_encode($wrongKindReadbackObservation, JSON_UNESCAPED_SLASHES),
    'unrelated_object' => json_encode($recentReadbackObservation, JSON_UNESCAPED_SLASHES),
];
$GLOBALS['videochat_gossipmesh_topology_health_store_list'] = static function () use (&$readbackStore): array {
    return array_map(
        static fn(string $objectKey): array => ['object_id' => $objectKey, 'modified_unix_ms' => 1_000_000],
        array_keys($readbackStore)
    );
};
$GLOBALS['videochat_gossipmesh_topology_health_store_get'] = static function (string $objectKey) use (&$readbackStore): string|false {
    return $readbackStore[$objectKey] ?? false;
};
$loadedReadbackObservations = videochat_gossipmesh_load_recent_topology_health_observations('room-alpha', 'call-alpha', 1_000_001);
videochat_gossipmesh_test_assert(count($loadedReadbackObservations) === 1, 'readback must ignore stale, malformed, wrong-context, wrong-schema, and unsafe records');
videochat_gossipmesh_test_assert((string) ($loadedReadbackObservations[0]['pair_key'] ?? '') === videochat_gossipmesh_link_pair_key('30', '40'), 'readback must preserve the recent valid failed pair');
$readbackAvoidPairs = videochat_gossipmesh_recent_failed_pair_map($loadedReadbackObservations, 1_000_001);
videochat_gossipmesh_test_assert(($readbackAvoidPairs[videochat_gossipmesh_link_pair_key('30', '40')] ?? false) === true, 'readback failed pair must feed avoidance map');
$readbackAvoidPlan = videochat_gossipmesh_plan_topology('call-alpha', 'room-alpha', [
    ['participant_id' => '10', 'user_id' => '10', 'invite_state' => 'allowed'],
    ['participant_id' => '20', 'user_id' => '20', 'invite_state' => 'allowed'],
    ['participant_id' => '30', 'user_id' => '30', 'invite_state' => 'allowed'],
    ['participant_id' => '40', 'user_id' => '40', 'invite_state' => 'allowed'],
], [
    'seed' => 'readback-avoid-failed-pair',
    'max_neighbors' => 3,
    'forward_count' => 3,
    'avoid_pairs' => $readbackAvoidPairs,
]);
videochat_gossipmesh_test_assert(!in_array('40', (array) ($readbackAvoidPlan['topology']['30'] ?? []), true), 'topology creation must avoid recent readback pair from reporter side');
videochat_gossipmesh_test_assert(!in_array('30', (array) ($readbackAvoidPlan['topology']['40'] ?? []), true), 'topology creation must avoid recent readback pair from lost-neighbor side');
$expiredReadbackObservations = videochat_gossipmesh_load_recent_topology_health_observations('room-alpha', 'call-alpha', 1_120_001);
videochat_gossipmesh_test_assert($expiredReadbackObservations === [], 'readback observations must expire after failed_until_unix_ms');
foreach (['sdp', 'ice_candidate', 'socket', 'protected_frame', 'token'] as $unsafeReadbackToken) {
    videochat_gossipmesh_test_assert(strpos(json_encode($loadedReadbackObservations, JSON_UNESCAPED_SLASHES) ?: '', $unsafeReadbackToken) === false, 'readback must not accept unsafe token: ' . $unsafeReadbackToken);
}
unset($GLOBALS['videochat_gossipmesh_topology_health_store_list'], $GLOBALS['videochat_gossipmesh_topology_health_store_get']);

$badLaneRepair = videochat_gossipmesh_decode_topology_repair_request(json_encode([
    'type' => 'gossip/topology-repair/request',
    'lane' => 'data',
    'payload' => [
        'room_id' => 'room-alpha',
        'call_id' => 'call-alpha',
        'peer_id' => '10',
    ],
], JSON_UNESCAPED_SLASHES));
videochat_gossipmesh_test_assert(!(bool) ($badLaneRepair['ok'] ?? true), 'topology repair must reject data lane requests');
videochat_gossipmesh_test_assert((string) ($badLaneRepair['error'] ?? '') === 'invalid_lane', 'topology repair invalid lane error mismatch');

foreach (['', '10'] as $badLostNeighborId) {
    $badLostNeighborRepair = videochat_gossipmesh_decode_topology_repair_request(json_encode([
        'type' => 'gossip/topology-repair/request',
        'lane' => 'ops',
        'payload' => [
            'room_id' => 'room-alpha',
            'call_id' => 'call-alpha',
            'peer_id' => '10',
            'lost_neighbor_peer_id' => $badLostNeighborId,
        ],
    ], JSON_UNESCAPED_SLASHES));
    videochat_gossipmesh_test_assert(!(bool) ($badLostNeighborRepair['ok'] ?? true), 'topology repair must reject missing/self lost neighbor');
    videochat_gossipmesh_test_assert((string) ($badLostNeighborRepair['error'] ?? '') === 'invalid_lost_neighbor', 'topology repair invalid lost neighbor error mismatch');
}

foreach (['sdp', 'candidate', 'protected_frame', 'sender_key', 'data'] as $unsafeField) {
    $unsafeRepair = videochat_gossipmesh_decode_topology_repair_request(json_encode([
        'type' => 'gossip/topology-repair/request',
        'lane' => 'ops',
        'payload' => [
            'room_id' => 'room-alpha',
            'call_id' => 'call-alpha',
            'peer_id' => '10',
            $unsafeField => 'unsafe',
        ],
    ], JSON_UNESCAPED_SLASHES));
    videochat_gossipmesh_test_assert(!(bool) ($unsafeRepair['ok'] ?? true), 'topology repair must reject unsafe field: ' . $unsafeField);
    videochat_gossipmesh_test_assert((string) ($unsafeRepair['error'] ?? '') === 'forbidden_media_or_signaling_field', 'unsafe repair error mismatch: ' . $unsafeField);
}
$unsafeWrapperRepair = videochat_gossipmesh_decode_topology_repair_request(json_encode([
    'type' => 'gossip/topology-repair/request',
    'lane' => 'ops',
    'sdp' => 'unsafe-wrapper-field',
    'payload' => [
        'room_id' => 'room-alpha',
        'call_id' => 'call-alpha',
        'peer_id' => '10',
    ],
], JSON_UNESCAPED_SLASHES));
videochat_gossipmesh_test_assert(!(bool) ($unsafeWrapperRepair['ok'] ?? true), 'topology repair must reject unsafe wrapper fields');
videochat_gossipmesh_test_assert((string) ($unsafeWrapperRepair['error'] ?? '') === 'forbidden_media_or_signaling_field', 'unsafe wrapper repair error mismatch');

$hintPayload = videochat_gossipmesh_call_topology_payload($plan, 'owner-1', 'carrier_lost', 123456);
videochat_gossipmesh_test_assert((string) ($hintPayload['type'] ?? '') === 'call/gossip-topology', 'repair must emit call gossip topology wrapper');
videochat_gossipmesh_test_assert((string) (($hintPayload['payload'] ?? [])['type'] ?? '') === 'topology_hint', 'repair wrapper payload must be topology_hint');
videochat_gossipmesh_test_assert((string) (($hintPayload['payload'] ?? [])['lane'] ?? '') === 'ops', 'repair topology hint must stay on ops lane');
videochat_gossipmesh_test_assert(count((array) (($hintPayload['payload'] ?? [])['neighbors'] ?? [])) <= VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS, 'repair topology hint neighbors must be bounded');
videochat_gossipmesh_test_assert(strpos(json_encode($hintPayload, JSON_UNESCAPED_SLASHES) ?: '', 'protected_frame') === false, 'repair topology hint must not include protected media frames');

$presenceState = videochat_presence_state_init();
$socketOwner = 'socket-owner';
$socketPeer = 'socket-peer';
$socketModerator = 'socket-moderator';
$ownerConnection = [
    'connection_id' => 'conn-10',
    'session_id' => 'session-10',
    'socket' => $socketOwner,
    'room_id' => 'room-alpha',
    'user_id' => 10,
    'display_name' => 'Owner',
    'role' => 'user',
    'active_call_id' => 'call-alpha',
    'requested_call_id' => 'call-alpha',
    'call_role' => 'owner',
    'connected_at' => '2026-05-04T00:00:00Z',
];
$peerConnection = [
    ...$ownerConnection,
    'connection_id' => 'conn-20',
    'session_id' => 'session-20',
    'socket' => $socketPeer,
    'user_id' => 20,
    'display_name' => 'Peer',
    'call_role' => 'participant',
];
$moderatorConnection = [
    ...$ownerConnection,
    'connection_id' => 'conn-50',
    'session_id' => 'session-50',
    'socket' => $socketModerator,
    'user_id' => 50,
    'display_name' => 'Moderator',
    'call_role' => 'moderator',
];
$presenceState['connections']['conn-10'] = $ownerConnection;
$presenceState['connections']['conn-20'] = $peerConnection;
$presenceState['connections']['conn-50'] = $moderatorConnection;
$presenceState['rooms']['room-alpha']['conn-10'] = $socketOwner;
$presenceState['rooms']['room-alpha']['conn-20'] = $socketPeer;
$presenceState['rooms']['room-alpha']['conn-50'] = $socketModerator;
$openEmptyDatabase = static function (): PDO {
    return new PDO('sqlite::memory:');
};

$GLOBALS['gossipmesh_sent_frames'] = [];
$GLOBALS['gossipmesh_topology_health_store'] = [];
$websocketReadbackObservation = videochat_gossipmesh_sanitize_topology_health_observation([
    ...$repairCommand,
    'peer_id' => '10',
    'lost_peer_id' => '50',
], (int) floor(microtime(true) * 1000));
$GLOBALS['gossipmesh_topology_health_store'][(string) ($websocketReadbackObservation['object_key'] ?? '')] = [
    'json' => json_encode($websocketReadbackObservation, JSON_UNESCAPED_SLASHES) ?: '',
    'content_type' => 'application/json',
];
$GLOBALS['videochat_gossipmesh_topology_health_store_put'] = static function (string $objectKey, string $json, string $contentType): bool {
    $GLOBALS['gossipmesh_topology_health_store'][$objectKey] = [
        'json' => $json,
        'content_type' => $contentType,
    ];

    return true;
};
$GLOBALS['videochat_gossipmesh_topology_health_store_list'] = static function (): array {
    return array_map(
        static fn(string $objectKey): array => ['object_id' => $objectKey, 'modified_unix_ms' => (int) floor(microtime(true) * 1000)],
        array_keys($GLOBALS['gossipmesh_topology_health_store'])
    );
};
$GLOBALS['videochat_gossipmesh_topology_health_store_get'] = static function (string $objectKey): string|false {
    return (string) (($GLOBALS['gossipmesh_topology_health_store'][$objectKey] ?? [])['json'] ?? '') ?: false;
};
$repairResult = videochat_realtime_handle_gossipmesh_topology_repair_command(
    $repairCommand,
    $socketOwner,
    $presenceState,
    $ownerConnection,
    $openEmptyDatabase
);
videochat_gossipmesh_test_assert((bool) ($repairResult['handled'] ?? false), 'websocket topology repair command should be handled');
videochat_gossipmesh_test_assert(array_key_exists($healthObjectKey, $GLOBALS['gossipmesh_topology_health_store']), 'websocket topology repair must persist link health observation');
$storedHealthJson = (string) ($GLOBALS['gossipmesh_topology_health_store'][$healthObjectKey]['json'] ?? '');
$storedHealthPayload = json_decode($storedHealthJson, true);
videochat_gossipmesh_test_assert(is_array($storedHealthPayload), 'persisted topology health observation must be JSON');
videochat_gossipmesh_test_assert((string) ($storedHealthPayload['content_type'] ?? '') === '', 'persisted topology health payload must not inline object metadata');
videochat_gossipmesh_test_assert((string) (($GLOBALS['gossipmesh_topology_health_store'][$healthObjectKey] ?? [])['content_type'] ?? '') === 'application/json', 'topology health object_store content type mismatch');
videochat_gossipmesh_test_assert((string) ($storedHealthPayload['contract'] ?? '') === VIDEOCHAT_GOSSIPMESH_CONTRACT, 'persisted topology health contract mismatch');
videochat_gossipmesh_test_assert((string) ($storedHealthPayload['room_id'] ?? '') === 'room-alpha', 'persisted topology health room id mismatch');
videochat_gossipmesh_test_assert((string) ($storedHealthPayload['call_id'] ?? '') === 'call-alpha', 'persisted topology health call id mismatch');
videochat_gossipmesh_test_assert((string) ($storedHealthPayload['peer_id'] ?? '') === '10', 'persisted topology health peer id mismatch');
videochat_gossipmesh_test_assert((string) ($storedHealthPayload['lost_peer_id'] ?? '') === '20', 'persisted topology health lost peer id mismatch');
videochat_gossipmesh_test_assert((int) ($storedHealthPayload['failure_cooldown_ms'] ?? 0) === VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_COOLDOWN_MS, 'persisted topology health cooldown mismatch');
videochat_gossipmesh_test_assert(strpos($storedHealthJson, 'socket') === false, 'persisted topology health must not include socket fields');
videochat_gossipmesh_test_assert(strpos($storedHealthJson, 'sdp') === false, 'persisted topology health must not include SDP fields');
videochat_gossipmesh_test_assert(strpos($storedHealthJson, 'ice') === false, 'persisted topology health must not include ICE fields');
videochat_gossipmesh_test_assert(strpos($storedHealthJson, 'protected_frame') === false, 'persisted topology health must not include protected media frames');
videochat_gossipmesh_test_assert(count($GLOBALS['gossipmesh_sent_frames']) === 1, 'websocket topology repair should emit exactly one frame');
$repairPayload = (array) ($GLOBALS['gossipmesh_sent_frames'][0]['payload'] ?? []);
videochat_gossipmesh_test_assert((string) ($repairPayload['type'] ?? '') === 'call/gossip-topology', 'websocket repair payload type mismatch');
videochat_gossipmesh_test_assert((string) (($repairPayload['payload'] ?? [])['peer_id'] ?? '') === '10', 'websocket repair must target authenticated peer');
videochat_gossipmesh_test_assert((string) (($repairPayload['payload'] ?? [])['lane'] ?? '') === 'ops', 'websocket repair payload lane mismatch');
videochat_gossipmesh_test_assert(count((array) (($repairPayload['payload'] ?? [])['neighbors'] ?? [])) <= VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS, 'websocket repair neighbors must be bounded');
videochat_gossipmesh_test_assert(!in_array('20', array_map(static fn(array $neighbor): string => (string) ($neighbor['peer_id'] ?? ''), (array) (($repairPayload['payload'] ?? [])['neighbors'] ?? [])), true), 'websocket replacement topology must avoid the fresh failed pair');
videochat_gossipmesh_test_assert(!in_array('50', array_map(static fn(array $neighbor): string => (string) ($neighbor['peer_id'] ?? ''), (array) (($repairPayload['payload'] ?? [])['neighbors'] ?? [])), true), 'websocket replacement topology must avoid recent object_store readback failed pair');
videochat_gossipmesh_test_assert(strpos(json_encode($repairPayload, JSON_UNESCAPED_SLASHES) ?: '', 'sdp') === false, 'websocket repair must not distribute signaling payloads');
videochat_gossipmesh_test_assert(strpos(json_encode($repairPayload, JSON_UNESCAPED_SLASHES) ?: '', 'protected_frame') === false, 'websocket repair must not distribute media frames');

$GLOBALS['gossipmesh_sent_frames'] = [];
$telemetryResult = videochat_realtime_handle_gossipmesh_telemetry_snapshot_command(
    $telemetryCommand,
    $socketOwner,
    $presenceState,
    $ownerConnection
);
videochat_gossipmesh_test_assert((bool) ($telemetryResult['handled'] ?? false), 'websocket telemetry snapshot command should be handled');
videochat_gossipmesh_test_assert(count($GLOBALS['gossipmesh_sent_frames']) === 1, 'websocket telemetry should emit exactly one ack');
$telemetryAck = (array) ($GLOBALS['gossipmesh_sent_frames'][0]['payload'] ?? []);
videochat_gossipmesh_test_assert((string) ($telemetryAck['type'] ?? '') === 'gossip/telemetry/ack', 'websocket telemetry ack type mismatch');
videochat_gossipmesh_test_assert((string) ($telemetryAck['lane'] ?? '') === 'ops', 'websocket telemetry ack lane mismatch');
videochat_gossipmesh_test_assert((string) (($telemetryAck['rollout_gate'] ?? [])['decision'] ?? '') === 'sfu_first_explicit', 'websocket telemetry ack must keep conservative rollout gate decision until thresholds pass');
videochat_gossipmesh_test_assert(array_key_exists('rtc_ready', (array) ($telemetryAck['rollout_gate'] ?? [])), 'websocket telemetry ack must expose RTC readiness');
videochat_gossipmesh_test_assert((int) (($presenceState['gossipmesh_telemetry']['room-alpha']['totals'] ?? [])['sent'] ?? -1) === 7, 'websocket telemetry aggregate total mismatch');
videochat_gossipmesh_test_assert((string) (($presenceState['gossipmesh_telemetry']['room-alpha']['peers']['10'] ?? [])['rollout_strategy'] ?? '') === 'sfu_first_explicit', 'websocket telemetry aggregate must keep SFU-first rollout label');
$websocketTelemetryJson = json_encode($presenceState['gossipmesh_telemetry']['room-alpha'] ?? [], JSON_UNESCAPED_SLASHES) ?: '';
videochat_gossipmesh_test_assert(strpos($websocketTelemetryJson, 'protected_frame') === false, 'websocket telemetry aggregate must not include protected media frames');
videochat_gossipmesh_test_assert(strpos($websocketTelemetryJson, 'sdp') === false, 'websocket telemetry aggregate must not include SDP');

$GLOBALS['gossipmesh_sent_frames'] = [];
$forgedPeerCommand = [
    ...$repairCommand,
    'peer_id' => '20',
];
videochat_realtime_handle_gossipmesh_topology_repair_command(
    $forgedPeerCommand,
    $socketOwner,
    $presenceState,
    $ownerConnection,
    $openEmptyDatabase
);
$forgedError = (array) ($GLOBALS['gossipmesh_sent_frames'][0]['payload'] ?? []);
videochat_gossipmesh_test_assert((string) ($forgedError['type'] ?? '') === 'system/error', 'forged peer repair must emit an error');
videochat_gossipmesh_test_assert((string) (($forgedError['details'] ?? [])['error'] ?? '') === 'unauthenticated_peer', 'forged peer repair error mismatch');

$GLOBALS['gossipmesh_sent_frames'] = [];
$contextMismatchCommand = [
    ...$repairCommand,
    'room_id' => 'other-room',
];
videochat_realtime_handle_gossipmesh_topology_repair_command(
    $contextMismatchCommand,
    $socketOwner,
    $presenceState,
    $ownerConnection,
    $openEmptyDatabase
);
$contextError = (array) ($GLOBALS['gossipmesh_sent_frames'][0]['payload'] ?? []);
videochat_gossipmesh_test_assert((string) (($contextError['details'] ?? [])['error'] ?? '') === 'context_mismatch', 'context mismatch repair error mismatch');

try {
    videochat_gossipmesh_plan_topology('', 'room-alpha', $members);
    videochat_gossipmesh_test_assert(false, 'missing call_id must throw');
} catch (InvalidArgumentException) {
}

echo "OK\n";
