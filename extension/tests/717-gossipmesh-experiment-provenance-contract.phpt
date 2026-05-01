--TEST--
King GossipMesh experiment intake preserves contributor credit through porting
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
    '## Q-14 GossipMesh/SFU Research Sources',
    'd92dfddd09710f80c2599bab4dbb5f59c3f34f1c',
    'dca5e9815eaf90900d8bda2de7b9850f969f48e2',
    'b338a87e505a0ed40eb32bacc47d099581d5e029',
    '9f7f544ba3dbc8159ca57335ae819d978b904406',
    'Alice-and-Bob `<sasha@MacBook-Pro-2.local>`',
    'extension/src/gossip_mesh/gossip_mesh.c',
    'extension/src/gossip_mesh/sfu_signaling.php',
    'demo/video-chat/frontend-vue/src/lib/sfu/gossip_mesh_client.js',
    'documentation/gossipmesh.md',
    'extension/tests/999-gossipmesh-test.phpt',
    'Prefer `git cherry-pick -x` only when a source commit can be applied without artifacts or weaker behavior.',
    'include the relevant source commit hash in the commit body',
    'Co-authored-by',
    'Do not import `.DS_Store`, `tmp_*`, debug PHPTs, generated test results, generated build churn, or submodule gitlinks',
    'explicit room/call binding, DB-backed admission, no process-local room identity, and no client-invented call state',
    'Treat direct P2P/DataChannel behavior as research',
    'A compatible server-authoritative runtime slice has been ported as `demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php`.',
    'Raw experiment transport, browser authority, process-local room state, generated artifacts, and debug scaffolding remain excluded.',
];

foreach ($provenanceNeedles as $needle) {
    require_contains('documentation/experiment-intake-provenance.md', $needle);
}

require_contains('READYNESS_TRACKER.md', 'Q-14 contributor-credit baseline');

echo "OK\n";
?>
--EXPECT--
OK
