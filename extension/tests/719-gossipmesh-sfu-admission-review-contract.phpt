--TEST--
King GossipMesh SFU signaling review preserves current video-chat admission gates
--FILE--
<?php
$root = dirname(__DIR__, 2);

function source(string $path): string
{
    global $root;
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $source;
}

function require_contains(string $path, string $needle): void
{
    if (!str_contains(source($path), $needle)) {
        throw new RuntimeException($path . ' must contain ' . $needle);
    }
}

$provenanceNeedles = [
    'SFU signaling admission review:',
    'cannot replace the active video-chat `/sfu` gateway',
    'creates rooms from arbitrary client input',
    'derives `peer_id` from `spl_object_id($websocket)`',
    'stores rooms and peers in process arrays',
    'does not validate `call_id`, call-access session binding, `call_participants.invite_state`, `joined_at`/`left_at`, owner/moderator/admin authority, or DB-backed admission before room entry',
    'videochat_handle_sfu_routes()',
    'videochat_realtime_user_has_sfu_room_admission()',
    'requires a valid WebSocket handshake, session auth, RBAC, a bound `room_id`, optional `call_id`, current room membership or persistent admission',
    'fail-closed `sfu_room_admission_required` behavior',
    'videochat_sfu_decode_client_frame()',
    'must not reintroduce plaintext fallback, client-invented room changes, or cross-room peer discovery',
    'server-side bootstrap-peer selection, neighbor-exchange snapshots, relay-candidate selection, relay-fallback metadata, churn cleanup cadence, and max-peer bounds',
    'after the current admission gate',
    'server-authoritative call/room/SFU store',
    'route all SFU/control messages through authorized backend events rather than process-local peer maps',
];

foreach ($provenanceNeedles as $needle) {
    require_contains('documentation/experiment-intake-provenance.md', $needle);
}

$currentGatewayNeedles = [
    'videochat_realtime_validate_websocket_handshake($request, \'/sfu\')',
    '$authenticateRequest($request, \'websocket\')',
    'videochat_authorize_role_for_path((array) ($websocketAuth[\'user\'] ?? []), $path, \'/sfu\')',
    'videochat_sfu_resolve_bound_room($queryParams)',
    'videochat_realtime_presence_has_room_membership(',
    'videochat_realtime_user_has_sfu_room_admission(',
    'sfu_room_admission_required',
    'videochat_sfu_decode_client_frame($msgJson, $roomId)',
];

foreach ($currentGatewayNeedles as $needle) {
    require_contains('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php', $needle);
}

require_contains('READYNESS_TRACKER.md', 'Q-14 SFU signaling admission review');

echo "OK\n";
?>
--EXPECT--
OK
