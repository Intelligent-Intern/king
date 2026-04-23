--TEST--
King GossipMesh rejects experiment behavior that weakens room, admission, or security guarantees
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

function tracked_files(): array
{
    global $root;
    $output = [];
    $status = 0;
    exec('cd ' . escapeshellarg($root) . ' && git ls-files', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Could not list tracked files');
    }

    return $output;
}

$provenanceNeedles = [
    'Weakening behavior rejection:',
    'Rejected experiment behavior is forbidden in active `/sfu` and GossipMesh paths unless it is re-specified under the current backend-authoritative contract and covered by tests.',
    'The active path must not accept client-created room, call, peer, participant, admission, relay, or topology authority.',
    'It must not accept direct P2P media forwarding, process-local admission authority, JSON/plaintext downgrade for protected frames, unbounded public STUN/TURN defaults, raw sockets/network endpoints, or debug-generated control behavior.',
    'Reusable topology ideas may enter only as server-issued hints after session auth, RBAC, room/call binding, participant/admission checks, and protected-envelope validation.',
    'Any future native WebRTC or P2P mode must be a separate backend-authoritative runtime contract with revocation, participant churn, rekey, relay authorization, downgrade, and cross-room-isolation tests.',
];
foreach ($provenanceNeedles as $needle) {
    require_contains('documentation/experiment-intake-provenance.md', $needle);
}

$docNeedles = [
    '## Rejected Weakening Behavior',
    'The following experiment behaviors remain forbidden in the active runtime:',
    'Client-created call, room, peer, participant, admission, relay, or topology authority.',
    'Direct P2P media forwarding without a separate backend-authoritative runtime contract.',
    'Process-local admission or room identity as source of truth.',
    'JSON/plaintext fallback for protected frames or protected-media claims.',
    'Unbounded public STUN/TURN defaults or raw network endpoints in topology payloads.',
    'Debug console behavior as control-plane behavior.',
];
foreach ($docNeedles as $needle) {
    require_contains('documentation/gossipmesh.md', $needle);
}

$runtimeNeedles = [
    'videochat_gossipmesh_forbidden_payload_fields',
    '\'raw_media_key\'',
    '\'plaintext_frame\'',
    '\'socket\'',
    '\'ip\'',
    '\'port\'',
    '\'sdp\'',
    '\'ice_candidate\'',
    '\'iceServers\'',
    'videochat_gossipmesh_is_admitted_member',
    '[\'pending\', \'queued\', \'invited\']',
    'videochat_gossipmesh_validate_transport_envelope',
    '\'legacy_plaintext_data_forbidden\'',
    '\'forbidden_plaintext_or_secret_field\'',
    '\'missing_protected_envelope_contract\'',
];
foreach ($runtimeNeedles as $needle) {
    require_contains('demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php', $needle);
}

$runtimeContractNeedles = [
    'weakened member plan must keep only clean admitted members',
    'weakened member plan must reject SDP, ICE, and socket fields',
    'weakened envelope field must fail',
    'forbidden_plaintext_or_secret_field',
];
foreach ($runtimeContractNeedles as $needle) {
    require_contains('demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.php', $needle);
}

$sfuClientPath = 'demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts';
$forbiddenSfuClientNeedles = [
    'class GossipMeshClient',
    'generatePeerId',
    'createDataChannel',
    'stun:stun.l.google.com',
    'turnUrl',
    'relay/request',
    'Math.random() <',
    'JSON.stringify({',
];
foreach ($forbiddenSfuClientNeedles as $needle) {
    require_not_contains($sfuClientPath, $needle);
}

$runtimePath = 'demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php';
$forbiddenRuntimeNeedles = [
    'class GossipMesh',
    'spl_object_id',
    'new WebSocket',
    'RTCPeerConnection',
    'stun:',
    'turnUrl',
    'console.',
    'king_websocket_send',
];
foreach ($forbiddenRuntimeNeedles as $needle) {
    require_not_contains($runtimePath, $needle);
}

$forbiddenTracked = [
    'demo/video-chat/frontend-vue/src/lib/sfu/gossip_mesh_client.js',
    'extension/src/gossip_mesh/gossip_mesh_client.js',
    'extension/src/gossip_mesh/sfu_signaling.php',
    'extension/src/gossip_mesh/gossip_mesh.php',
    'extension/tests/999-gossipmesh-test.phpt',
];
$tracked = tracked_files();
foreach ($forbiddenTracked as $path) {
    if (in_array($path, $tracked, true)) {
        throw new RuntimeException('Forbidden weakened experiment path is tracked: ' . $path);
    }
}

require_contains('SPRINT.md', '- [x] Reject any experiment behavior that weakens current room/admission/security guarantees.');
require_contains('READYNESS_TRACKER.md', 'Q-14 weakened experiment behavior rejection');

echo "OK\n";
?>
--EXPECT--
OK
