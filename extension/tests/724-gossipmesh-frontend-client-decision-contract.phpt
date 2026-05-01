--TEST--
King GossipMesh intake folds compatible browser work into the current SFU client instead of porting the experiment client
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
    'Frontend client integration decision:',
    '`demo/video-chat/frontend-vue/src/lib/sfu/gossip_mesh_client.js` is not ported as a standalone browser runtime.',
    'replaced by the current `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts` integration point',
    'folded into that existing SFU client after the backend publishes server-authoritative topology and routing contracts',
    'Browser code may consume server-issued topology snapshots, relay hints, and forward instructions only after the current `/sfu` admission gate has bound `session`, `call_id`, `room_id`, participant state, and protected-media policy.',
    'must not generate peer identity, create room state, select topology, open direct DataChannels, pick relay authority, add public STUN/TURN defaults, or downgrade protected frames to JSON/plaintext',
    'preserve existing backend-origin failover, `room_id` and `call_id` query binding, `protected_frame` carriage, `protection_mode` honesty, and current `sfu/frame` parsing semantics',
    'Direct P2P/WebRTC-native behavior remains research until it is specified as a separate backend-authoritative `webrtc_native` contract',
];
foreach ($provenanceNeedles as $needle) {
    require_contains('documentation/experiment-intake-provenance.md', $needle);
}

$tracked = tracked_files();
$forbiddenClientPaths = [
    'demo/video-chat/frontend-vue/src/lib/sfu/gossip_mesh_client.js',
    'extension/src/gossip_mesh/gossip_mesh_client.js',
];
foreach ($forbiddenClientPaths as $path) {
    if (in_array($path, $tracked, true)) {
        throw new RuntimeException('Forbidden standalone experiment client is tracked: ' . $path);
    }
}

$sfuClientPath = 'demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts';
$sfuMessageHandlerPath = 'demo/video-chat/frontend-vue/src/lib/sfu/sfuMessageHandler.ts';
$sfuClientNeedles = [
    'export class SFUClient',
    'resolveBackendSfuOriginCandidates',
    'setBackendSfuOrigin',
    'buildWebSocketUrl',
    "query.set('call_id', normalizedCallId)",
    'room_id: roomId',
    "this.send({ type: 'sfu/join', room_id: roomId, role: 'publisher' })",
    'protected_frame',
    'protection_mode',
    'handleSfuClientMessage',
];
foreach ($sfuClientNeedles as $needle) {
    require_contains($sfuClientPath, $needle);
}

$sfuMessageHandlerNeedles = [
    "case 'sfu/frame':",
    'protectedFrame: protectedFrame || null',
    'protectionMode:',
    "'transport_only'",
];
foreach ($sfuMessageHandlerNeedles as $needle) {
    require_contains($sfuMessageHandlerPath, $needle);
}

$forbiddenSfuClientNeedles = [
    'class GossipMeshClient',
    'generatePeerId',
    'createDataChannel',
    'RTCPeerConnection',
    'stun:stun.l.google.com',
    'turnUrl',
    'relay/request',
    'Math.random() <',
    'JSON.stringify({',
];
foreach ($forbiddenSfuClientNeedles as $needle) {
    require_not_contains($sfuClientPath, $needle);
}

require_contains('READYNESS_TRACKER.md', 'Q-14 frontend GossipMesh client decision');

echo "OK\n";
?>
--EXPECT--
OK
