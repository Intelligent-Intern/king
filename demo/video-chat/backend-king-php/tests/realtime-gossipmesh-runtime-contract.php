<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/realtime_gossipmesh.php';

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
videochat_gossipmesh_test_assert($plan['rejected_members'] === 2, 'pending and secret-bearing members must be rejected');
videochat_gossipmesh_test_assert($plan['ttl'] === 3, 'small room TTL estimate mismatch');

$knownIds = array_map(static fn(array $member): string => $member['id'], $plan['members']);
sort($knownIds);
videochat_gossipmesh_test_assert($knownIds === ['mod-5', 'owner-1', 'user-2'], 'eligible member ids mismatch');

foreach ($plan['topology'] as $memberId => $neighbors) {
    videochat_gossipmesh_test_assert(in_array($memberId, $knownIds, true), 'topology contains unknown member id');
    videochat_gossipmesh_test_assert(count($neighbors) <= 2, 'neighbor count must be bounded');
    foreach ($neighbors as $neighborId) {
        videochat_gossipmesh_test_assert($neighborId !== $memberId, 'topology must not self-connect');
        videochat_gossipmesh_test_assert(in_array($neighborId, $knownIds, true), 'topology contains unknown neighbor');
    }
}

videochat_gossipmesh_test_assert($plan['relay_candidates'][0] === 'owner-1', 'relay candidates should rank by relay score');
videochat_gossipmesh_test_assert(!in_array('queued-3', $plan['relay_candidates'], true), 'queued users must not be relay candidates');

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

try {
    videochat_gossipmesh_plan_topology('', 'room-alpha', $members);
    videochat_gossipmesh_test_assert(false, 'missing call_id must throw');
} catch (InvalidArgumentException) {
}

echo "OK\n";
