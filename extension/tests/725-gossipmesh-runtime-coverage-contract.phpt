--TEST--
King GossipMesh runtime contracts cover routing, membership, envelope, duplicate, TTL, relay, and failures
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

$runtime = 'demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php';
$runtimeNeedles = [
    'VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT',
    'VIDEOCHAT_GOSSIPMESH_MAX_PROTECTED_FRAME_BYTES',
    'videochat_gossipmesh_validate_transport_envelope',
    'videochat_gossipmesh_plan_message_route',
    'legacy_plaintext_data_forbidden',
    'missing_protected_envelope_contract',
    'missing_protected_frame',
    'malformed_protected_frame',
    'duplicate_frame',
    'publisher_not_in_topology',
    'relay_unavailable',
    'forbidden_plaintext_or_secret_field',
    'protected_frame_too_large',
    'videochat_gossipmesh_accept_frame_once',
    'videochat_gossipmesh_select_forward_targets',
];
foreach ($runtimeNeedles as $needle) {
    require_contains($runtime, $needle);
}

$forbiddenRuntimeNeedles = [
    'RTCPeerConnection',
    'createDataChannel',
    'stun:',
    'Math.random',
    'JSON.stringify',
    'king_websocket_send',
    'spl_object_id',
];
foreach ($forbiddenRuntimeNeedles as $needle) {
    require_not_contains($runtime, $needle);
}

$contract = 'demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.php';
$contractNeedles = [
    'only admitted members without forbidden payload fields are eligible',
    'pending and secret-bearing members must be rejected',
    'topology planning must be deterministic',
    'neighbor count must be bounded',
    'relay candidates should rank by relay score',
    'duplicate frame should be rejected',
    'seen window must stay bounded',
    'expired TTL must not forward',
    'valid protected envelope should route',
    'route should decrement TTL',
    'route fanout must be bounded',
    'route should update duplicate window',
    'duplicate route should fail',
    'legacy plaintext data must fail',
    'missing envelope contract must fail',
    'failed direct target should use relay fallback when available',
    'missing relay candidate should fail',
    'unknown publisher should fail',
];
foreach ($contractNeedles as $needle) {
    require_contains($contract, $needle);
}

$provenanceNeedles = [
    'Runtime contract-test coverage:',
    '`realtime-gossipmesh-runtime-contract.php` covers admitted-member filtering, rejected-member accounting, deterministic topology, bounded neighbor fanout, relay candidate ranking, duplicate suppression, TTL expiry, protected-envelope validation, route planning, relay fallback, and failure cases',
    '`725-gossipmesh-runtime-coverage-contract.phpt` pins that the runtime exposes only backend-owned helpers for protected-envelope routing',
];
foreach ($provenanceNeedles as $needle) {
    require_contains('documentation/experiment-intake-provenance.md', $needle);
}

require_contains('SPRINT.md', '- [x] Add contract tests for GossipMesh message routing, membership, IIBIN envelope use, duplicate suppression, TTL handling, relay fallback, and failure behavior.');
require_contains('READYNESS_TRACKER.md', 'Q-14 GossipMesh runtime contract coverage');

echo "OK\n";
?>
--EXPECT--
OK
