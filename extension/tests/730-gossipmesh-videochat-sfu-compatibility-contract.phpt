--TEST--
King video-chat SFU remains compatible with current room, admission, and security contracts
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

function require_not_contains(string $path, string $needle): void
{
    if (str_contains(source($path), $needle)) {
        throw new RuntimeException($path . ' must not contain ' . $needle);
    }
}

function require_order(string $path, string $before, string $after): void
{
    $source = source($path);
    $beforeOffset = strpos($source, $before);
    $afterOffset = strpos($source, $after);
    if ($beforeOffset === false || $afterOffset === false || $beforeOffset >= $afterOffset) {
        throw new RuntimeException($path . ' must keep ' . $before . ' before ' . $after);
    }
}

$gateway = 'demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php';
$store = 'demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php';
$context = 'demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php';
$runtimeContract = 'demo/video-chat/backend-king-php/tests/realtime-sfu-contract.php';
$provenance = 'documentation/experiment-intake-provenance.md';
$runtimeDoc = 'documentation/gossipmesh.md';

$gatewayNeedles = [
    'function videochat_handle_sfu_routes(',
    'videochat_realtime_validate_websocket_handshake($request, \'/sfu\')',
    '$authenticateRequest($request, \'websocket\')',
    'videochat_authorize_role_for_path((array) ($websocketAuth[\'user\'] ?? []), $path, \'/sfu\')',
    'videochat_sfu_resolve_bound_room($queryParams)',
    '\'sfu_room_binding_invalid\'',
    'videochat_realtime_normalize_call_id(',
    'videochat_realtime_presence_has_room_membership(',
    'videochat_realtime_user_has_sfu_room_admission(',
    '\'sfu_room_admission_required\'',
    'king_server_upgrade_to_websocket($session, $streamId)',
    'videochat_sfu_decode_client_frame($msgJson, $roomId)',
    '\'sfu_room_mismatch\'',
    'videochat_sfu_bootstrap($sfuDatabase)',
    'videochat_sfu_upsert_publisher($sfuDatabase, $roomId, $clientId, $userIdString, $userName)',
    'videochat_sfu_poll_broker(',
    'videochat_sfu_insert_frame(',
    'videochat_sfu_remove_publisher(',
];
foreach ($gatewayNeedles as $needle) {
    require_contains($gateway, $needle);
}

require_order($gateway, 'videochat_realtime_validate_websocket_handshake($request, \'/sfu\')', '$authenticateRequest($request, \'websocket\')');
require_order($gateway, '$authenticateRequest($request, \'websocket\')', 'videochat_authorize_role_for_path((array) ($websocketAuth[\'user\'] ?? []), $path, \'/sfu\')');
require_order($gateway, 'videochat_authorize_role_for_path((array) ($websocketAuth[\'user\'] ?? []), $path, \'/sfu\')', 'videochat_sfu_resolve_bound_room($queryParams)');
require_order($gateway, 'videochat_sfu_resolve_bound_room($queryParams)', 'videochat_realtime_user_has_sfu_room_admission(');
require_order($gateway, 'if (!$isAdmittedInRoom && !$hasPersistentAdmission) {', 'king_server_upgrade_to_websocket($session, $streamId)');
require_order($gateway, 'king_server_upgrade_to_websocket($session, $streamId)', 'videochat_sfu_decode_client_frame($msgJson, $roomId)');

$storeNeedles = [
    'function videochat_sfu_resolve_bound_room(array $queryParams): array',
    '\'missing_room_id\'',
    '\'invalid_room_id\'',
    '\'room_query_mismatch\'',
    'function videochat_sfu_decode_client_frame(string $frame, string $boundRoomId): array',
    '\'sfu_room_mismatch\'',
    '\'protected_frame_data_conflict\'',
    '\'protected_frame_required\'',
    '\'protected_frame_too_large\'',
    '$payload[\'room_id\'] = $normalizedBoundRoomId;',
    'function videochat_sfu_decode_stored_frame_payload(',
    'function videochat_sfu_fetch_frames_since(',
];
foreach ($storeNeedles as $needle) {
    require_contains($store, $needle);
}

$contextNeedles = [
    'function videochat_realtime_user_has_sfu_room_admission(',
    'videochat_normalize_role_slug($role) === \'admin\'',
    'videochat_realtime_call_role_context_for_room_user(',
    'videochat_realtime_call_context_allows_admission_bypass($context)',
    'FROM calls',
    'LEFT JOIN call_participants cp',
    'calls.room_id = :room_id',
    'calls.status IN (\'active\', \'scheduled\')',
    'calls.owner_user_id = :user_id',
    'cp.user_id IS NOT NULL',
    'allowed',
    'accepted',
    'owner',
    'moderator',
];
foreach ($contextNeedles as $needle) {
    require_contains($context, $needle);
}

$runtimeContractNeedles = [
    'SFU handshake invalid method should fail before auth',
    'SFU auth callback must not run for invalid handshake',
    'SFU missing session should fail auth',
    'SFU missing room_id should fail closed',
    'SFU room query mismatch must fail',
    'SFU join room mismatch must fail',
    'SFU publish room mismatch must fail',
    'protected SFU frame envelope should decode',
    'SFU must reject protected frame plus plaintext data',
    'SFU must reject plaintext fallback in required mode',
    'SFU frame relay must exclude self and cross-room frames',
    'stored protected SFU payload must not expose legacy data array',
    'SFU reconnect should recover publishers from store',
];
foreach ($runtimeContractNeedles as $needle) {
    require_contains($runtimeContract, $needle);
}

$provenanceNeedles = [
    'Video-chat SFU compatibility disposition:',
    'The active video-chat SFU remains compatible with current room, admission, and security contracts after Q-14.',
    'Compatibility is proven by the existing `/sfu` gateway order: handshake validation, websocket session auth, RBAC, room/call binding, current room membership or DB-backed admission, then WebSocket upgrade.',
    'SFU message handling keeps bound-room decoding, protected-frame downgrade rejection, cross-room isolation, and room-scoped broker persistence.',
    'GossipMesh may only provide post-admission topology/routing hints and does not replace the video-chat SFU gateway.',
];
foreach ($provenanceNeedles as $needle) {
    require_contains($provenance, $needle);
}

$runtimeDocNeedles = [
    '## Video-Chat SFU Compatibility',
    'The video-chat SFU gateway remains compatible with the existing room,',
    'admission, and security model.',
    'The compatibility guard is',
    '`730-gossipmesh-videochat-sfu-compatibility-contract.phpt`.',
];
foreach ($runtimeDocNeedles as $needle) {
    require_contains($runtimeDoc, $needle);
}

$forbiddenGatewayNeedles = [
    'extension/src/gossip_mesh/sfu_signaling.php',
    'class GossipMesh',
    'RTCPeerConnection',
    'createDataChannel',
    'stun:stun.l.google.com',
    'turnUrl',
    'Math.random() <',
];
foreach ($forbiddenGatewayNeedles as $needle) {
    require_not_contains($gateway, $needle);
}

require_contains('SPRINT.md', '- [x] Video-chat SFU remains compatible with current room/admission/security contracts.');
require_contains('READYNESS_TRACKER.md', 'Q-14 video-chat SFU compatibility closure');

echo "OK\n";
?>
--EXPECT--
OK
