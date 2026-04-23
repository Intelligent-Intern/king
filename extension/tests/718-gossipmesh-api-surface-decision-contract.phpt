--TEST--
King GossipMesh experiment intake records the production API surface decision
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
    'Production API surface decision:',
    'The raw experiment surface is not the production King API.',
    'Do not expose the global PHP `GossipMesh` class',
    'raw C `gossip_mesh_t` pointers',
    'browser `GossipMeshClient` topology control',
    'process-local room ownership as stable API',
    'server-authoritative topology and routing planner',
    'they must not create call state, admission state, room identity, or trust decisions',
    'internal helpers for topology planning, duplicate-window tracking, TTL/fanout selection, relay candidate selection, and stats collection',
    'must not expose raw mutable structs to PHP',
    'namespaced/static `King\\GossipMesh` facade plus procedural `king_gossip_mesh_*` mirrors',
    'topology planning, membership delta application, envelope routing, duplicate suppression, relay fallback selection, and stats export',
    'bounded arrays or typed King objects, not sockets, WebRTC objects, raw peers, or callbacks',
    'Wire payloads must use a versioned IIBIN envelope once implemented.',
    'remains research until it is folded into the current SFU client without weakening room binding',
];

foreach ($provenanceNeedles as $needle) {
    require_contains('documentation/experiment-intake-provenance.md', $needle);
}

require_contains('SPRINT.md', '- [x] Review `extension/src/gossip_mesh/*` and decide the production King API surface.');
require_contains('READYNESS_TRACKER.md', 'Q-14 GossipMesh API surface decision');

echo "OK\n";
?>
--EXPECT--
OK
