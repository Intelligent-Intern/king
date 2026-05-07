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
    ?int $epochMs = null
): array {
    $safeCallId = videochat_gossipmesh_safe_id($callId);
    $safeRoomId = videochat_gossipmesh_safe_id($roomId);
    $safePeerId = videochat_gossipmesh_safe_id($peerId);
    if ($safeCallId === '' || $safeRoomId === '' || $safePeerId === '') {
        return [];
    }

    $topologyPlan = videochat_gossipmesh_plan_topology(
        $safeCallId,
        $safeRoomId,
        videochat_gossipmesh_members_from_room_participants($participants),
        [
            'seed' => $reason === '' ? 'room_state' : $reason,
            'max_neighbors' => VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS,
            'forward_count' => VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT,
        ]
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

    return [
        ...$hint,
        'kind' => 'topology_hint',
        'topology_feature' => 'room_state',
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
    ?int $epochMs = null
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
        $payload = videochat_gossipmesh_room_state_payload($safeCallId, $safeRoomId, $participants, $peerId, $reason, $topologyEpoch);
        if ($payload !== []) {
            $payloads[$peerId] = $payload;
        }
    }

    return $payloads;
}
