--TEST--
King GossipMesh intake separates reusable topology ideas from experiment-only behavior
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
    'Reusable versus experiment-only split:',
    'Reusable topology ideas: bounded neighbor count, bootstrap-peer sampling, duplicate suppression keyed by publisher plus sequence, TTL/fanout limiting, deterministic forward selection for a frame, neighbor-health statistics, relay-candidate ranking, relay fallback metadata, churn cleanup cadence, and topology stats export.',
    'Reusable signaling ideas: admitted-participant topology snapshots, targeted offer/answer/ICE command shapes, neighbor-exchange deltas, relay request/assignment metadata, peer-left deltas, and request-new-peers commands.',
    'only by the backend after session, room, call, participant, and admission checks pass',
    'Reusable envelope ideas: the optional IIBIN-style binary transport direction from `9f7f544` is compatible only as a versioned, backend-validated envelope.',
    'JSON compatibility, direct decoder construction in browser hot paths, and console-warning fallback are not production semantics.',
    'Experiment-only behavior: direct browser-to-browser media or frame transport, browser-owned peer IDs, browser-selected topology, browser-triggered room joins, unbounded public STUN defaults, client-side relay authority, process-local peer maps, random peer-connect probability as control policy, raw `console.*` debug paths, and any fallback that silently downgrades protected payloads to JSON/plaintext.',
    '`documentation/gossipmesh.md` describes research architecture and must not be published as product documentation until the implementation is server-authoritative, admission-bound, protected-envelope-aware, and contract-tested.',
    'implementation commits may port a reusable idea only together with a contract proving the corresponding experiment-only behavior is absent from the active path',
];

foreach ($provenanceNeedles as $needle) {
    require_contains('documentation/experiment-intake-provenance.md', $needle);
}

require_contains('SPRINT.md', '- [x] Separate reusable topology/signaling ideas from experiment-only behavior.');
require_contains('READYNESS_TRACKER.md', 'Q-14 reusable/experiment-only split');

echo "OK\n";
?>
--EXPECT--
OK
