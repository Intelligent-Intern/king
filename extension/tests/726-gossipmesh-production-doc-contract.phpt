--TEST--
King GossipMesh production documentation exists only after matching runtime contracts
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

$doc = 'documentation/gossipmesh.md';
$docNeedles = [
    '# GossipMesh Runtime',
    'GossipMesh is the video-chat topology and routing helper for the current King runtime path.',
    'The production contract is backend-authoritative:',
    'The current runtime path is `wlvc_sfu`.',
    '`demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php`',
    '`demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.php`',
    '`726-gossipmesh-production-doc-contract.phpt`',
    '`videochat_gossipmesh_plan_topology()` accepts server-provided admitted members',
    '`videochat_gossipmesh_plan_message_route()` validates a protected transport envelope',
    '`envelope_contract` must be',
    '`king-video-chat-protected-media-transport-envelope`',
    '`protected_frame` must be present, bounded, and base64url-shaped.',
    'Relay/SFU code may inspect bounded public metadata only.',
    'The current contract fails closed:',
    'Missing `call_id` or `room_id` fails topology planning.',
    'Unknown publishers fail route planning.',
    'Legacy plaintext `data` fails routing.',
    'Duplicate frames are rejected and classified as duplicates.',
    'TTL `0` accepts local delivery but produces no forward targets.',
    'If no relay candidate is available, route planning fails with',
    'What Is Not Active',
    'Browser-created peer identity.',
    'Direct browser peer-to-peer media forwarding.',
    'Public STUN/TURN defaults baked into the client.',
    'JSON/plaintext downgrade for protected frames.',
    'The experiment `gossip_mesh_client.js` is not ported.',
    '`demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts`',
    '`documentation/experiment-intake-provenance.md`',
];
foreach ($docNeedles as $needle) {
    require_contains($doc, $needle);
}

$forbiddenDocNeedles = [
    'RTCPeerConnection',
    'createDataChannel',
    'stun:stun.l.google.com',
    'spl_object_id',
    'process-local room maps as authority.',
    'module.exports = GossipMeshClient',
    'king_websocket_send',
];
foreach ($forbiddenDocNeedles as $needle) {
    require_not_contains($doc, $needle);
}

$runtimeNeedles = [
    'videochat_gossipmesh_plan_topology',
    'videochat_gossipmesh_plan_message_route',
    'VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT',
    'VIDEOCHAT_GOSSIPMESH_RUNTIME_PATH',
    'relay_unavailable',
    'legacy_plaintext_data_forbidden',
    'duplicate_frame',
];
foreach ($runtimeNeedles as $needle) {
    require_contains('demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php', $needle);
}

require_contains('documentation/README.md', '[GossipMesh Runtime](./gossipmesh.md)');
require_contains('documentation/experiment-intake-provenance.md', 'Production documentation:');
require_contains('documentation/experiment-intake-provenance.md', '`documentation/gossipmesh.md` is now allowed because the production runtime contract exists and is covered by tests.');
require_contains('SPRINT.md', '- [x] Add `documentation/gossipmesh.md` only after the production contract matches the implementation.');
require_contains('READYNESS_TRACKER.md', 'Q-14 GossipMesh production docs');

echo "OK\n";
?>
--EXPECT--
OK
