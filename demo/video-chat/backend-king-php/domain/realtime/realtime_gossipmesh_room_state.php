<?php

declare(strict_types=1);

require_once __DIR__ . '/realtime_gossipmesh.php';

/**
 * @param array<int, array<string, mixed>> $participants
 * @return array<string, mixed>
 */
function videochat_gossipmesh_room_state_payload(
    string $callId,
    string $roomId,
    array $participants,
    string $peerId,
    string $reason = '',
    ?int $epochMs = null,
    array $options = []
): array {
    $safeCallId = videochat_gossipmesh_safe_id($callId);
    $safeRoomId = videochat_gossipmesh_safe_id($roomId);
    $safePeerId = videochat_gossipmesh_safe_id($peerId);
    if ($safeCallId === '' || $safeRoomId === '' || $safePeerId === '') {
        return [];
    }

    $planOptions = [
        'seed' => (string) ($options['seed'] ?? ($reason === '' ? 'room_state' : $reason)),
        'max_neighbors' => VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS,
        'forward_count' => VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT,
    ];
    if (is_array($options['avoid_pairs'] ?? null)) {
        $planOptions['avoid_pairs'] = $options['avoid_pairs'];
    }
    $topologyPlan = videochat_gossipmesh_plan_topology(
        $safeCallId,
        $safeRoomId,
        videochat_gossipmesh_members_from_room_participants($participants),
        $planOptions
    );
    $hint = videochat_gossipmesh_topology_hint_payload(
        $topologyPlan,
        $safePeerId,
        $reason === '' ? 'room_state' : $reason,
        $epochMs
    );

    $admittedPeers = [];
    foreach ($topologyPlan['members'] as $member) {
        if (!is_array($member)) {
            continue;
        }
        $memberId = videochat_gossipmesh_safe_id($member['id'] ?? '');
        if ($memberId === '') {
            continue;
        }
        $admittedPeers[] = [
            'peer_id' => $memberId,
            'user_id' => videochat_gossipmesh_safe_id($member['user_id'] ?? $memberId),
            'display_name' => (string) ($member['display_name'] ?? ('User ' . $memberId)),
            'capabilities' => [
                'media_carrier' => 'gossip_primary_candidate',
                'data_transports' => ['rtc_datachannel'],
                'media_envelope' => VIDEOCHAT_GOSSIPMESH_DATA_ENVELOPE_CONTRACT,
                'codec' => VIDEOCHAT_GOSSIPMESH_DATA_CODEC,
                'sfu_fallback' => true,
            ],
        ];
    }

    $feature = trim((string) ($options['topology_feature'] ?? 'room_state'));
    if ($feature === '') {
        $feature = 'room_state';
    }
    $payload = [
        ...$hint,
        'kind' => 'topology_hint',
        'topology_feature' => $feature,
        'admitted_peers' => $admittedPeers,
        'capabilities' => [
            'control_plane_authority' => 'server',
            'media_carriers' => ['gossip_primary', 'sfu_fallback'],
            'data_transports' => ['rtc_datachannel'],
            'media_envelope' => VIDEOCHAT_GOSSIPMESH_DATA_ENVELOPE_CONTRACT,
            'codec' => VIDEOCHAT_GOSSIPMESH_DATA_CODEC,
            'bounded_neighbors' => true,
        ],
        'transport_candidates' => [
            [
                'transport' => 'rtc_datachannel',
                'purpose' => 'bounded_media_gossip',
                'authority' => 'server_assigned_neighbor',
                'bounded' => true,
            ],
            [
                'transport' => 'websocket_sfu_control',
                'purpose' => 'fallback_relay_recording',
                'authority' => 'server',
                'optional' => true,
            ],
        ],
        'assigned_neighbors' => $hint['neighbors'],
        'relay_candidates' => $topologyPlan['relay_candidates'],
        'ttl' => $topologyPlan['ttl'],
        'forward_count' => $topologyPlan['forward_count'],
        'rejected_members' => $topologyPlan['rejected_members'],
    ];

    if (is_array($options['repair'] ?? null)) {
        $repair = videochat_gossipmesh_room_state_repair_for_peer(
            $safePeerId,
            $options['repair'],
            is_array($payload['assigned_neighbors'] ?? null) ? $payload['assigned_neighbors'] : []
        );
        if ($repair !== []) {
            $payload['repair'] = $repair;
        }
    }

    return $payload;
}

/**
 * @param array<string, mixed> $repair
 * @param array<int, array<string, mixed>> $assignedNeighbors
 * @return array<string, mixed>
 */
function videochat_gossipmesh_room_state_repair_for_peer(string $peerId, array $repair, array $assignedNeighbors): array
{
    $safePeerId = videochat_gossipmesh_safe_id($peerId);
    $requestedByPeerId = videochat_gossipmesh_safe_id($repair['requested_by_peer_id'] ?? '');
    $lostPeerId = videochat_gossipmesh_safe_id($repair['lost_peer_id'] ?? ($repair['lost_neighbor_peer_id'] ?? ''));
    if ($safePeerId === '' || $requestedByPeerId === '' || $lostPeerId === '') {
        return [];
    }

    $edges = [];
    $retiredPeerIds = [];
    $inputEdges = is_array($repair['retired_edges'] ?? null) ? $repair['retired_edges'] : [];
    if ($inputEdges === []) {
        $inputEdges[] = [
            'peer_id' => $requestedByPeerId,
            'neighbor_peer_id' => $lostPeerId,
        ];
    }
    foreach ($inputEdges as $edge) {
        if (!is_array($edge)) {
            continue;
        }
        $leftPeerId = videochat_gossipmesh_safe_id($edge['peer_id'] ?? '');
        $rightPeerId = videochat_gossipmesh_safe_id($edge['neighbor_peer_id'] ?? ($edge['lost_peer_id'] ?? ''));
        if ($leftPeerId === '' || $rightPeerId === '' || $leftPeerId === $rightPeerId) {
            continue;
        }
        $edges[] = [
            'peer_id' => $leftPeerId,
            'neighbor_peer_id' => $rightPeerId,
        ];
        if ($leftPeerId === $safePeerId) {
            $retiredPeerIds[$rightPeerId] = true;
        }
        if ($rightPeerId === $safePeerId) {
            $retiredPeerIds[$leftPeerId] = true;
        }
    }

    $replacementPeerIds = [];
    foreach ($assignedNeighbors as $neighbor) {
        if (!is_array($neighbor)) {
            continue;
        }
        $neighborPeerId = videochat_gossipmesh_safe_id($neighbor['peer_id'] ?? '');
        if ($neighborPeerId !== '' && $neighborPeerId !== $safePeerId) {
            $replacementPeerIds[$neighborPeerId] = true;
        }
    }

    $reason = trim((string) ($repair['reason'] ?? 'topology_repair'));
    if ($reason === '') {
        $reason = 'topology_repair';
    }

    return [
        'authoritative' => true,
        'requested_by_peer_id' => $requestedByPeerId,
        'lost_peer_id' => $lostPeerId,
        'lost_neighbor_peer_id' => $lostPeerId,
        'reason' => substr($reason, 0, VIDEOCHAT_GOSSIPMESH_TOPOLOGY_REPAIR_MAX_REASON_BYTES),
        'retired_peer_ids' => array_values(array_map(static fn(mixed $value): string => (string) $value, array_keys($retiredPeerIds))),
        'retired_edges' => $edges,
        'replacement_peer_ids' => array_values(array_map(static fn(mixed $value): string => (string) $value, array_keys($replacementPeerIds))),
    ];
}

/**
 * @param array<int, array<string, mixed>> $participants
 * @return array<string, array<string, mixed>>
 */
function videochat_gossipmesh_room_state_payloads_by_peer(
    string $callId,
    string $roomId,
    array $participants,
    string $reason = '',
    ?int $epochMs = null,
    array $options = []
): array {
    $safeCallId = videochat_gossipmesh_safe_id($callId);
    $safeRoomId = videochat_gossipmesh_safe_id($roomId);
    if ($safeCallId === '' || $safeRoomId === '') {
        return [];
    }

    $payloads = [];
    $topologyEpoch = $epochMs ?? (int) floor(microtime(true) * 1000);
    foreach ($participants as $participant) {
        $user = is_array($participant['user'] ?? null) ? $participant['user'] : [];
        $peerId = videochat_gossipmesh_safe_id($user['id'] ?? ($participant['user_id'] ?? ''));
        if ($peerId === '') {
            continue;
        }
        $payload = videochat_gossipmesh_room_state_payload(
            $safeCallId,
            $safeRoomId,
            $participants,
            $peerId,
            $reason,
            $topologyEpoch,
            $options
        );
        if ($payload !== []) {
            $payloads[$peerId] = $payload;
        }
    }

    return $payloads;
}
