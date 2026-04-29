--TEST--
King GossipMesh Q-14 disposition is a tested runtime capability with rejected experiment behavior documented
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

$provenanceNeedles = [
    'Q-14 disposition:',
    'GossipMesh is accepted only as the tested `wlvc_sfu` runtime helper in `demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php`.',
    'The accepted capability is bounded topology planning, admitted-member filtering, protected-envelope routing, duplicate suppression, TTL/fanout limiting, relay candidate ranking, and relay fallback planning.',
    'The active proof is `demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.php` plus PHPT guards `723` through `729`.',
    'Raw experiment API, C structs, standalone browser client, `sfu_signaling.php`, direct P2P transport, process-local authority, plaintext downgrade, generated artifacts, and debug scaffolding are rejected for the current runtime.',
    'Future WebRTC-native or P2P work must be opened as a separate backend-authoritative runtime contract instead of weakening this disposition.',
];
foreach ($provenanceNeedles as $needle) {
    require_contains('documentation/experiment-intake-provenance.md', $needle);
}

$docNeedles = [
    '## Runtime Status',
    'Q-14 is closed as a tested runtime capability, not as a raw experiment import.',
    'Accepted: the current `wlvc_sfu` helper for backend-authoritative topology',
    'planning, admitted-member filtering, protected-envelope routing, duplicate',
    'suppression, TTL/fanout limiting, relay candidate ranking, and relay fallback',
    'Rejected: raw experiment API, C structs, standalone browser client,',
    '`sfu_signaling.php`, direct P2P transport, process-local authority, plaintext',
    'downgrade, generated artifacts, and debug scaffolding.',
    '`729-gossipmesh-runtime-disposition-contract.phpt`',
];
foreach ($docNeedles as $needle) {
    require_contains('documentation/gossipmesh.md', $needle);
}

$runtimeNeedles = [
    'const VIDEOCHAT_GOSSIPMESH_CONTRACT = \'king-video-chat-gossipmesh-runtime\';',
    'const VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT = \'king-video-chat-protected-media-transport-envelope\';',
    'const VIDEOCHAT_GOSSIPMESH_RUNTIME_PATH = \'wlvc_sfu\';',
    'function videochat_gossipmesh_plan_topology(',
    'function videochat_gossipmesh_plan_message_route(',
    'function videochat_gossipmesh_validate_transport_envelope(',
    '\'authority\' => \'server\'',
    '\'legacy_plaintext_data_forbidden\'',
    '\'relay_unavailable\'',
    '\'duplicate_frame\'',
];
foreach ($runtimeNeedles as $needle) {
    require_contains('demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php', $needle);
}

$runtimeContractNeedles = [
    'topology planning must be deterministic',
    'topology must be server authoritative',
    'runtime path must stay SFU-bound for this port',
    'protected envelope contract must be advertised',
    'only admitted members without forbidden payload fields are eligible',
    'weakened member plan must reject SDP, ICE, and socket fields',
    'legacy plaintext data must fail',
    'duplicate route should fail',
    'failed direct target should use relay fallback when available',
    'missing relay candidate should fail',
];
foreach ($runtimeContractNeedles as $needle) {
    require_contains('demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.php', $needle);
}

$guardNeedles = [
    '723-gossipmesh-compatible-runtime-port-contract.phpt',
    '724-gossipmesh-frontend-client-decision-contract.phpt',
    '725-gossipmesh-runtime-coverage-contract.phpt',
    '726-gossipmesh-production-doc-contract.phpt',
    '727-gossipmesh-sfu-constraints-contract.phpt',
    '728-gossipmesh-weakened-behavior-rejection-contract.phpt',
    '729-gossipmesh-runtime-disposition-contract.phpt',
];
foreach ($guardNeedles as $needle) {
    require_contains('documentation/gossipmesh.md', $needle);
}

$runtimeForbidden = [
    'class GossipMesh',
    'RTCPeerConnection',
    'stun:',
    'turnUrl',
    'createDataChannel',
    'king_websocket_send',
    'console.',
];
foreach ($runtimeForbidden as $needle) {
    require_not_contains('demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php', $needle);
}

require_contains('READYNESS_TRACKER.md', 'Q-14 GossipMesh runtime disposition');

echo "OK\n";
?>
--EXPECT--
OK
