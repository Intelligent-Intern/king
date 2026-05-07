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

$frames = [];
$sender = static function (mixed $socket, array $payload) use (&$frames): bool {
    $frames[] = [
        'socket' => $socket,
        'payload' => $payload,
    ];

    return true;
};

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
