--TEST--
King GossipMesh intake ports only compatible runtime pieces and excludes experiment artifacts
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

$runtimePath = 'demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php';
$runtimeNeedles = [
    'VIDEOCHAT_GOSSIPMESH_CONTRACT',
    'VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT',
    'VIDEOCHAT_GOSSIPMESH_RUNTIME_PATH',
    'videochat_gossipmesh_plan_topology',
    'videochat_gossipmesh_accept_frame_once',
    'videochat_gossipmesh_select_forward_targets',
    'videochat_gossipmesh_forbidden_payload_fields',
    "'authority' => 'server'",
    "'runtime_path' => VIDEOCHAT_GOSSIPMESH_RUNTIME_PATH",
    "'envelope_contract' => VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT",
];
foreach ($runtimeNeedles as $needle) {
    require_contains($runtimePath, $needle);
}

$forbiddenRuntimeNeedles = [
    'class GossipMesh',
    'spl_object_id',
    'new WebSocket',
    'RTCPeerConnection',
    'stun:',
    'turnUrl',
    'console.',
    'mt_srand',
    'JSON.stringify',
    'king_websocket_send',
];
foreach ($forbiddenRuntimeNeedles as $needle) {
    require_not_contains($runtimePath, $needle);
}

$tracked = tracked_files();
$forbiddenExact = [
    '.DS_Store',
    'extension/src/gossip_mesh/gossip_mesh.c',
    'extension/src/gossip_mesh/gossip_mesh.h',
    'extension/src/gossip_mesh/gossip_mesh.php',
    'extension/src/gossip_mesh/gossip_mesh_client.js',
    'extension/src/gossip_mesh/sfu_signaling.php',
    'extension/tests/999-gossipmesh-test.phpt',
];
foreach ($forbiddenExact as $path) {
    if (in_array($path, $tracked, true)) {
        throw new RuntimeException('Forbidden experiment artifact is tracked: ' . $path);
    }
}

foreach ($tracked as $path) {
    $basename = basename($path);
    if ($basename === '.DS_Store') {
        throw new RuntimeException('Forbidden .DS_Store artifact is tracked: ' . $path);
    }
    if (preg_match('/(^|\/)tmp_[^\/]*$/', $path) === 1) {
        throw new RuntimeException('Forbidden tmp_* artifact is tracked: ' . $path);
    }
    if (preg_match('/^extension\/tests\/debug_.*\.phpt$/', $path) === 1) {
        throw new RuntimeException('Forbidden debug PHPT is tracked: ' . $path);
    }
    if (str_contains($path, 'gossipmesh') && preg_match('/\.(diff|exp|log|out)$/', $path) === 1) {
        throw new RuntimeException('Forbidden generated GossipMesh test artifact is tracked: ' . $path);
    }
}

$gitlinkOutput = [];
$gitlinkStatus = 0;
exec('cd ' . escapeshellarg($root) . ' && git ls-files -s', $gitlinkOutput, $gitlinkStatus);
if ($gitlinkStatus !== 0) {
    throw new RuntimeException('Could not inspect gitlink modes');
}
foreach ($gitlinkOutput as $line) {
    if (str_starts_with($line, '160000 ')) {
        throw new RuntimeException('Forbidden submodule gitlink is tracked: ' . $line);
    }
}

$provenanceNeedles = [
    'Compatible runtime port:',
    'The compatible runtime port is `demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php`.',
    'It ports the experiment ideas for bounded topology planning, TTL estimation, duplicate suppression, deterministic forward target selection, relay candidate ranking, and stats-safe member normalization.',
    'It deliberately does not port the experiment global `GossipMesh` class, C `gossip_mesh_t` surface, browser `GossipMeshClient`, `sfu_signaling.php`, direct P2P transport, process-local rooms, raw sockets, ICE/STUN/TURN defaults, JSON/plaintext fallback, or debug console behavior.',
    'The port accepts only server-provided admitted members and returns bounded arrays with `call_id`, `room_id`, `runtime_path`, `envelope_contract`, topology, relay candidates, and rejected-member counts.',
    'Artifact exclusions remain mandatory: `.DS_Store`, `tmp_*`, debug PHPTs, generated test results, generated build churn, and submodule gitlinks must not be imported.',
];
foreach ($provenanceNeedles as $needle) {
    require_contains('documentation/experiment-intake-provenance.md', $needle);
}

require_contains('demo/video-chat/backend-king-php/http/module_realtime.php', "require_once __DIR__ . '/../domain/realtime/realtime_gossipmesh.php';");
require_contains('documentation/gossipmesh.md', 'GossipMesh is the video-chat topology and routing helper for the current King runtime path.');
require_contains('documentation/gossipmesh.md', 'The production contract is backend-authoritative:');
require_contains('READYNESS_TRACKER.md', 'Q-14 compatible GossipMesh runtime port');

echo "OK\n";
?>
--EXPECT--
OK
