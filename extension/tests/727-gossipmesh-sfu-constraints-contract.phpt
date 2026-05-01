--TEST--
King GossipMesh preserves stronger current SFU room and admission constraints
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
    'videochat_realtime_validate_websocket_handshake($request, \'/sfu\')',
    '$authenticateRequest($request, \'websocket\')',
    'videochat_authorize_role_for_path((array) ($websocketAuth[\'user\'] ?? []), $path, \'/sfu\')',
    'videochat_sfu_resolve_bound_room($queryParams)',
    'videochat_realtime_normalize_call_id(',
    'videochat_realtime_presence_has_room_membership(',
    'videochat_realtime_user_has_sfu_room_admission(',
    'if (!$isAdmittedInRoom && !$hasPersistentAdmission) {',
    '\'sfu_room_admission_required\'',
    'king_server_upgrade_to_websocket',
    'videochat_sfu_decode_client_frame($msgJson, $roomId)',
    'videochat_sfu_bootstrap($sfuDatabase)',
    'videochat_sfu_upsert_publisher($sfuDatabase, $roomId, $clientId, $userIdString, $userName)',
    'videochat_sfu_live_frame_relay_publish(',
    'videochat_sfu_live_frame_relay_poll(',
    'videochat_sfu_poll_broker(',
];
foreach ($gatewayNeedles as $needle) {
    require_contains($gateway, $needle);
}

require_order($gateway, 'videochat_sfu_resolve_bound_room($queryParams)', 'king_server_upgrade_to_websocket');
require_order($gateway, 'videochat_realtime_user_has_sfu_room_admission(', 'king_server_upgrade_to_websocket');
require_order($gateway, 'if (!$isAdmittedInRoom && !$hasPersistentAdmission) {', 'king_server_upgrade_to_websocket');

$storeNeedles = [
    'function videochat_sfu_resolve_bound_room(array $queryParams): array',
    '\'missing_room_id\'',
    '\'room_query_mismatch\'',
    'function videochat_sfu_decode_client_frame(string $frame, string $boundRoomId): array',
    '$rawCommandRoom = $decoded[\'room_id\'] ?? ($decoded[\'roomId\'] ?? ($decoded[\'room\'] ?? null));',
    '\'sfu_room_mismatch\'',
    '$payload[\'room_id\'] = $normalizedBoundRoomId;',
];
foreach ($storeNeedles as $needle) {
    require_contains($store, $needle);
}

$contextNeedles = [
    'function videochat_realtime_user_has_sfu_room_admission(',
    'if (videochat_normalize_role_slug($role) === \'admin\') {',
    'videochat_realtime_call_role_context_for_room_user(',
    'videochat_realtime_call_context_allows_admission_bypass($context)',
    'SELECT',
    'FROM calls',
    'LEFT JOIN call_participants cp',
    'calls.owner_user_id = :user_id',
    'cp.user_id IS NOT NULL',
    'invite_state',
    'joined_at',
    'left_at',
    'can_moderate',
    'allowed',
    'accepted',
    'owner',
    'moderator',
];
foreach ($contextNeedles as $needle) {
    require_contains($context, $needle);
}

$runtimeContractNeedles = [
    'SFU missing room_id should fail closed',
    'SFU room query mismatch must fail',
    'SFU join room mismatch must fail',
    'SFU publish room mismatch must fail',
    'protected binary SFU frame envelope should decode',
    'SFU live frame relay must preserve protected frame and codec/runtime metadata',
    'SFU live frame relay should skip publishers that are local to the subscriber worker',
];
foreach ($runtimeContractNeedles as $needle) {
    require_contains($runtimeContract, $needle);
}

$provenanceNeedles = [
    'SFU constraint preservation:',
    'The active `/sfu` gateway remains the only production entry point for SFU media signaling.',
    'It binds every SFU socket to a validated `room_id` and optional normalized `call_id` before WebSocket upgrade.',
    'Admission is current room membership or DB-backed admission through `videochat_realtime_user_has_sfu_room_admission()`.',
    'Process-local `$sfuClients` and `$sfuRooms` are live socket indexes only after admission.',
    'Client SFU frames are decoded against the already-bound room through `videochat_sfu_decode_client_frame($msgJson, $roomId)`.',
    'The experiment may add topology hints after admission, but it must not create room identity, call identity, participant state, or admission state from client input.',
];
foreach ($provenanceNeedles as $needle) {
    require_contains($provenance, $needle);
}

$runtimeDocNeedles = [
    '## SFU Constraints',
    'The current `/sfu` gateway remains the production media-signaling entry point.',
    'Process-local `$sfuClients` and `$sfuRooms` are live socket indexes only.',
    'are not room identity, call identity, participant state, or admission state.',
];
foreach ($runtimeDocNeedles as $needle) {
    require_contains($runtimeDoc, $needle);
}

require_contains('READYNESS_TRACKER.md', 'Q-14 SFU constraint preservation');

echo "OK\n";
?>
--EXPECT--
OK
