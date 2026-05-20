<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/realtime_gossipmesh.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/realtime/realtime_connection_contract.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';
require_once __DIR__ . '/../domain/realtime/realtime_room_snapshot.php';

function videochat_gossipmesh_room_state_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-gossipmesh-room-state-topology-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_gossipmesh_room_state_connection(int $userId, string $name, string $connectionId, string $callRole = 'participant'): array
{
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $name,
            'role' => 'user',
        ],
        'session-' . $userId,
        $connectionId,
        'socket-' . $userId,
        'contract-room'
    );
    $connection['active_call_id'] = 'contract-call';
    $connection['requested_call_id'] = 'contract-call';
    $connection['call_role'] = $callRole;
    $connection['effective_call_role'] = $callRole;

    return $connection;
}

function videochat_gossipmesh_room_state_last_frame(array $frames, string $socket, string $type): array
{
    for ($index = count($frames) - 1; $index >= 0; $index--) {
        $frame = $frames[$index] ?? [];
        if (($frame['socket'] ?? '') === $socket && (string) (($frame['payload'] ?? [])['type'] ?? '') === $type) {
            return is_array($frame['payload'] ?? null) ? $frame['payload'] : [];
        }
    }

    return [];
}

function videochat_gossipmesh_room_state_assert_symmetric_expander(array $plan): void
{
    $topology = is_array($plan['topology'] ?? null) ? $plan['topology'] : [];
    foreach ($topology as $memberId => $neighbors) {
        videochat_gossipmesh_room_state_assert(count((array) $neighbors) >= VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT, 'expander topology must keep minimum fanout for ' . $memberId);
        videochat_gossipmesh_room_state_assert(count((array) $neighbors) <= VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS, 'expander topology must respect hard fanout cap for ' . $memberId);
        foreach ((array) $neighbors as $neighborId) {
            videochat_gossipmesh_room_state_assert((string) $neighborId !== (string) $memberId, 'expander topology must not self-connect ' . $memberId);
            videochat_gossipmesh_room_state_assert(in_array((string) $memberId, (array) ($topology[(string) $neighborId] ?? []), true), 'expander topology edge must be symmetric for ' . $memberId . ' -> ' . $neighborId);
        }
    }
}

$frames = [];
$sender = static function (mixed $socket, array $payload) use (&$frames): bool {
    $frames[] = [
        'socket' => $socket,
        'payload' => $payload,
    ];

    return true;
};

$expanderMembers = [];
for ($index = 1; $index <= 7; $index++) {
    $expanderMembers[] = [
        'participant_id' => 'expander-' . $index,
        'user_id' => (string) (700 + $index),
        'display_name' => 'Expander ' . $index,
        'invite_state' => 'allowed',
    ];
}
$expanderPlan = videochat_gossipmesh_plan_topology('contract-call', 'contract-room', $expanderMembers, [
    'seed' => 'symmetric-expander-contract',
    'max_neighbors' => VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS,
    'forward_count' => VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS,
]);
videochat_gossipmesh_room_state_assert_symmetric_expander($expanderPlan);

$reasonParticipants = [];
for ($userId = 201; $userId <= 206; $userId++) {
    $reasonParticipants[] = [
        'user_id' => $userId,
        'display_name' => 'Reason Peer ' . $userId,
        'invite_state' => 'allowed',
    ];
}
$joinedTopology = videochat_gossipmesh_room_state_payload('contract-call', 'contract-room', $reasonParticipants, '201', 'participant_joined', 1_777_000_000_000);
$leftTopology = videochat_gossipmesh_room_state_payload('contract-call', 'contract-room', $reasonParticipants, '201', 'participant_left', 1_777_000_000_000);
videochat_gossipmesh_room_state_assert(($joinedTopology['assigned_neighbors'] ?? []) === ($leftTopology['assigned_neighbors'] ?? []), 'room-state topology seed must not change with snapshot reason');
videochat_gossipmesh_room_state_assert(($joinedTopology['relay_candidates'] ?? []) === ($leftTopology['relay_candidates'] ?? []), 'room-state relay candidates must not change with snapshot reason');
videochat_gossipmesh_room_state_assert((string) ($joinedTopology['reconnect_reason'] ?? '') === 'participant_joined', 'joined room-state reason should stay observable');
videochat_gossipmesh_room_state_assert((string) ($leftTopology['reconnect_reason'] ?? '') === 'participant_left', 'left room-state reason should stay observable');

$hintPlan = videochat_gossipmesh_plan_topology(
    'contract-call',
    'contract-room',
    videochat_gossipmesh_members_from_room_participants($reasonParticipants),
    ['seed' => 'room_lifecycle']
);
$hint = videochat_gossipmesh_topology_hint_payload($hintPlan, '201', 'contract_hint', 1_777_000_000_000);
$hintAdmittedPeerIds = array_map(static fn(array $peer): string => (string) ($peer['peer_id'] ?? ''), (array) ($hint['admitted_peers'] ?? []));
sort($hintAdmittedPeerIds);
videochat_gossipmesh_room_state_assert($hintAdmittedPeerIds === ['202', '203', '204', '205', '206'], 'topology hint must expose admitted peers except the scoped peer');
videochat_gossipmesh_room_state_assert((string) (($hint['admitted_peers'][0] ?? [])['transport'] ?? '') === 'rtc_datachannel', 'topology hint admitted peers must include RTC transport metadata');
videochat_gossipmesh_room_state_assert((array) (($hint['admitted_peers'][0] ?? [])['data_transports'] ?? []) === ['rtc_datachannel'], 'topology hint admitted peers must include data transport metadata');

$state = videochat_presence_state_init();
$owner = videochat_gossipmesh_room_state_connection(101, 'Owner', 'conn-owner', 'owner');
$peerA = videochat_gossipmesh_room_state_connection(102, 'Peer A', 'conn-peer-a');
$peerB = videochat_gossipmesh_room_state_connection(103, 'Peer B', 'conn-peer-b');

$ownerJoin = videochat_presence_join_room($state, $owner, 'contract-room', $sender);
$owner = (array) ($ownerJoin['connection'] ?? $owner);
$peerAJoin = videochat_presence_join_room($state, $peerA, 'contract-room', $sender);
$peerA = (array) ($peerAJoin['connection'] ?? $peerA);
$peerBJoin = videochat_presence_join_room($state, $peerB, 'contract-room', $sender);
$peerB = (array) ($peerBJoin['connection'] ?? $peerB);

$openDatabase = static function (): PDO {
    throw new RuntimeException('database intentionally unavailable for local topology contract');
};

$snapshot = videochat_realtime_room_snapshot_payload($state, $owner, $openDatabase, 'contract_snapshot');
$topology = is_array($snapshot['gossip_topology'] ?? null) ? $snapshot['gossip_topology'] : [];
videochat_gossipmesh_room_state_assert((string) ($topology['type'] ?? '') === 'topology_hint', 'room snapshot must carry a directly usable topology_hint');
videochat_gossipmesh_room_state_assert((string) ($topology['contract'] ?? '') === VIDEOCHAT_GOSSIPMESH_CONTRACT, 'snapshot topology must expose the GossipMesh contract');
videochat_gossipmesh_room_state_assert((string) ($topology['room_id'] ?? '') === 'contract-room', 'snapshot topology room_id mismatch');
videochat_gossipmesh_room_state_assert((string) ($topology['call_id'] ?? '') === 'contract-call', 'snapshot topology call_id mismatch');
videochat_gossipmesh_room_state_assert((string) ($topology['peer_id'] ?? '') === '101', 'snapshot topology must be scoped to the viewer peer');
videochat_gossipmesh_room_state_assert((int) ($topology['topology_epoch'] ?? 0) > 0, 'snapshot topology must include an epoch');
videochat_gossipmesh_room_state_assert(count($topology['admitted_peers'] ?? []) === 3, 'snapshot topology must include admitted peers');
videochat_gossipmesh_room_state_assert(count($topology['assigned_neighbors'] ?? []) === 2, 'snapshot topology must include bounded assigned neighbors for the viewer');
videochat_gossipmesh_room_state_assert(($topology['capabilities']['bounded_neighbors'] ?? false) === true, 'snapshot topology must advertise bounded-neighbor capability');
videochat_gossipmesh_room_state_assert(($topology['transport_candidates'][0]['transport'] ?? '') === 'rtc_datachannel', 'snapshot topology must include RTC data-channel transport candidates');
videochat_gossipmesh_room_state_assert(($topology['transport_candidates'][1]['purpose'] ?? '') === 'fallback_relay_recording', 'snapshot topology must keep SFU fallback/relay/recording as optional transport metadata');

$joinEvent = videochat_gossipmesh_room_state_last_frame($frames, 'socket-101', 'room/joined');
$joinHints = is_array($joinEvent['gossip_topology_by_peer_id'] ?? null) ? $joinEvent['gossip_topology_by_peer_id'] : [];
videochat_gossipmesh_room_state_assert(isset($joinHints['101'], $joinHints['102']), 'room/joined churn event must carry per-peer topology hints');
videochat_gossipmesh_room_state_assert((string) (($joinHints['101'] ?? [])['peer_id'] ?? '') === '101', 'room/joined topology map must include the receiver peer assignment');

videochat_presence_remove_connection($state, 'conn-peer-a', $sender);
$leaveEvent = videochat_gossipmesh_room_state_last_frame($frames, 'socket-101', 'room/left');
$leaveHints = is_array($leaveEvent['gossip_topology_by_peer_id'] ?? null) ? $leaveEvent['gossip_topology_by_peer_id'] : [];
videochat_gossipmesh_room_state_assert(isset($leaveHints['101'], $leaveHints['103']), 'room/left churn event must carry replacement per-peer topology hints');
videochat_gossipmesh_room_state_assert(!isset($leaveHints['102']), 'room/left topology hints must retire the departed peer');

echo "[realtime-gossipmesh-room-state-topology-contract] PASS\n";
