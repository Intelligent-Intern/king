--TEST--
King WLVC SFU keeps origin, call-id, snake_case, and room-binding compatibility
--FILE--
<?php
$root = dirname(__DIR__, 2);

function read_source(string $path): string
{
    global $root;
    $absolutePath = $root . '/' . $path;
    if (!is_file($absolutePath)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    $source = file_get_contents($absolutePath);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $source;
}

function require_contains(string $path, string $needle): void
{
    if (!str_contains(read_source($path), $needle)) {
        throw new RuntimeException($path . ' must contain ' . $needle);
    }
}

function require_order(string $path, string $before, string $after): void
{
    $source = read_source($path);
    $beforeOffset = strpos($source, $before);
    $afterOffset = strpos($source, $after);
    if ($beforeOffset === false || $afterOffset === false || $beforeOffset >= $afterOffset) {
        throw new RuntimeException($path . ' must keep ' . $before . ' before ' . $after);
    }
}

$sfuClient = 'demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts';
$sfuMessageHandler = 'demo/video-chat/frontend-vue/src/lib/sfu/sfuMessageHandler.ts';
$inboundFrameAssembler = 'demo/video-chat/frontend-vue/src/lib/sfu/inboundFrameAssembler.ts';
$backendOrigin = 'demo/video-chat/frontend-vue/src/support/backendOrigin.ts';
$gateway = 'demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php';
$store = 'demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php';
$runtimeContract = 'demo/video-chat/backend-king-php/tests/realtime-sfu-contract.php';
$frontendContract = 'demo/video-chat/frontend-vue/tests/contract/sfu-origin-room-binding-contract.mjs';
$provenance = 'documentation/experiment-intake-provenance.md';

$clientNeedles = [
    'resolveBackendSfuOriginCandidates',
    'setBackendSfuOrigin',
    "return buildWebSocketUrl(origin, '/sfu', query)",
    'const candidates = resolveBackendSfuOriginCandidates()',
    "setBackendSfuOrigin(candidates[index] || '')",
    'this.connectWithCandidates(candidates, index + 1, query, roomId, generation)',
    'room: roomId,',
    'room_id: roomId,',
    "if (/^[A-Za-z0-9._-]{1,200}$/.test(normalizedCallId)) {",
    "query.set('call_id', normalizedCallId)",
    "this.send({ type: 'sfu/join', room_id: roomId, role: 'publisher' })",
    "this.send({ type: 'sfu/publish', track_id: t.id, kind: t.kind, label: t.label })",
    'const normalizedPublisherId = stringField(publisherId)',
    'this.trackSubscribedPublisher(normalizedPublisherId)',
    "this.send({ type: 'sfu/subscribe', publisher_id: normalizedPublisherId })",
    "this.send({ type: 'sfu/subscribe', publisher_id: publisherId, reason: 'publisher_frame_stall_recovery' })",
    "this.send({ type: 'sfu/unpublish', track_id: trackId })",
    'publisher_id: frame.publisherId',
    'publisher_user_id: frame.publisherUserId ||',
    'track_id: frame.trackId',
    'frame_type: frame.type',
    'payload.protected_frame = frame.protectedFrame',
    'payload.protection_mode = frame.protectionMode ||',
    "import { SfuInboundFrameAssembler, stringField } from './inboundFrameAssembler'",
    'decodeSfuBinaryFrameEnvelope(ev.data)',
    'this.markPublisherFrameReceived(msg)',
];
foreach ($clientNeedles as $needle) {
    require_contains($sfuClient, $needle);
}

$messageHandlerNeedles = [
    'roomId:          stringField(msg.roomId, msg.room_id)',
    'publisherId:     stringField(msg.publisherId, msg.publisher_id)',
    'publisherUserId: stringField(msg.publisherUserId, msg.publisher_user_id)',
    'publisherName:   stringField(msg.publisherName, msg.publisher_name)',
    'stringField(msg.trackId, msg.track_id)',
    'const protectedFrame = stringField(msg.protectedFrame, msg.protected_frame)',
    'stringField(msg.protectionMode, msg.protection_mode)',
    'stringField(msg.frameType, msg.frame_type)',
];
foreach ($messageHandlerNeedles as $needle) {
    require_contains($sfuMessageHandler, $needle);
}

require_contains($inboundFrameAssembler, 'export function stringField(...values: any[]): string');

$originNeedles = [
    'export function resolveBackendSfuOriginCandidates()',
    'const primarySfuOrigin = resolveBackendSfuOrigin();',
    'pushUniqueCandidate(candidates, primarySfuOrigin);',
    'appendLoopbackHostVariant(candidates, primarySfuOrigin);',
    'if (hasExplicitBackendSfuConfig()) {',
    'const websocketOrigin = resolveBackendWebSocketOrigin();',
    'const backendOrigin = resolveBackendOrigin();',
];
foreach ($originNeedles as $needle) {
    require_contains($backendOrigin, $needle);
}

$gatewayNeedles = [
    'videochat_realtime_validate_websocket_handshake($request, \'/sfu\')',
    '$authenticateRequest($request, \'websocket\')',
    'videochat_authorize_role_for_path((array) ($websocketAuth[\'user\'] ?? []), $path, \'/sfu\')',
    'videochat_sfu_resolve_bound_room($queryParams)',
    'videochat_realtime_normalize_call_id(',
    "is_string(\$queryParams['call_id'] ?? null)",
    "is_string(\$queryParams['callId'] ?? null)",
    'videochat_realtime_presence_has_room_membership(',
    'videochat_realtime_user_has_sfu_room_admission(',
    '\'sfu_room_admission_required\'',
    'king_server_upgrade_to_websocket($session, $streamId)',
    'videochat_sfu_decode_client_frame($msgJson, $roomId)',
];
foreach ($gatewayNeedles as $needle) {
    require_contains($gateway, $needle);
}
require_order($gateway, 'videochat_sfu_resolve_bound_room($queryParams)', 'videochat_realtime_user_has_sfu_room_admission(');
require_order($gateway, 'if (!$isAdmittedInRoom && !$hasPersistentAdmission) {', 'king_server_upgrade_to_websocket($session, $streamId)');
require_order($gateway, 'king_server_upgrade_to_websocket($session, $streamId)', 'videochat_sfu_decode_client_frame($msgJson, $roomId)');

$storeNeedles = [
    'function videochat_sfu_resolve_bound_room(array $queryParams): array',
    '$rawRoomId = is_string($queryParams[\'room_id\'] ?? null)',
    '$legacyRoom = is_string($queryParams[\'room\'] ?? null)',
    '\'room_query_mismatch\'',
    'function videochat_sfu_decode_client_frame(string $frame, string $boundRoomId): array',
    '$rawCommandRoom = $decoded[\'room_id\'] ?? ($decoded[\'roomId\'] ?? ($decoded[\'room\'] ?? null));',
    '\'sfu_room_mismatch\'',
    '$payload[\'room_id\'] = $normalizedBoundRoomId;',
    '$protectionMode = strtolower(trim((string) ($decoded[\'protection_mode\'] ?? ($decoded[\'protectionMode\'] ?? \'transport_only\'))));',
    '$protectedFrameRaw = $decoded[\'protected_frame\'] ?? ($decoded[\'protectedFrame\'] ?? null);',
];
foreach ($storeNeedles as $needle) {
    require_contains($store, $needle);
}

$runtimeNeedles = [
    'uri\' => \'/sfu?session=sess_sfu_valid&room_id=room-alpha&room=room-alpha&call_id=call-alpha\'',
    'SFU missing room_id should fail closed',
    'SFU room query mismatch must fail',
    'SFU join with matching room_id should pass',
    'SFU join with legacy roomId should stay compatible',
    'SFU join room mismatch must fail',
    'SFU publish room mismatch must fail',
    'protected binary SFU frame envelope should decode',
    'JSON SFU media frame must be rejected in binary-required mode',
    'JSON SFU media chunks must be rejected in binary-required mode',
    'SFU live frame relay must preserve protected frame and codec/runtime metadata',
    'SFU live frame relay should skip publishers that are local to the subscriber worker',
];
foreach ($runtimeNeedles as $needle) {
    require_contains($runtimeContract, $needle);
}

require_contains($frontendContract, '[sfu-origin-room-binding-contract] PASS');
require_contains('demo/video-chat/frontend-vue/package.json', '"test:contract:sfu":');
require_contains('demo/video-chat/frontend-vue/package.json', 'node tests/contract/sfu-origin-room-binding-contract.mjs');

$provenanceNeedles = [
    'SFU compatibility decision:',
    'The client resolves SFU websocket candidates through `resolveBackendSfuOriginCandidates()`, connects through `buildWebSocketUrl(origin, \'/sfu\', query)`, and records the working origin with `setBackendSfuOrigin(...)`.',
    'The client binds `room`, `room_id`, and validated `call_id` query parameters before opening `/sfu`, sends outbound SFU commands with snake_case fields, and remains compatible with camelCase or snake_case server events.',
    'The backend `/sfu` gateway validates handshake, websocket auth, RBAC, room binding, `call_id`/`callId`, and room admission before `king_server_upgrade_to_websocket(...)`.',
    'Client SFU command frames are decoded against the already-bound room, accepting legacy `room`/`roomId` only when they match and failing closed with `sfu_room_mismatch` on cross-room commands.',
];
foreach ($provenanceNeedles as $needle) {
    require_contains($provenance, $needle);
}

require_contains('READYNESS_TRACKER.md', 'Q-15 WLVC SFU compatibility decision');
require_contains('READYNESS_TRACKER.md', 'Added frontend contract `sfu-origin-room-binding-contract.mjs` and PHPT `735-wlvc-sfu-compatibility-contract.phpt`.');

echo "OK\n";
?>
--EXPECT--
OK
