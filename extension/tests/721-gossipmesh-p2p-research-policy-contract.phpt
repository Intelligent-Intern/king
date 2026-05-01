--TEST--
King GossipMesh intake keeps direct P2P transport research-only until backend-authoritative respec
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
    'Direct P2P transport policy:',
    'Direct P2P/DataChannel transport from the experiment branch remains research only.',
    'not an active runtime path and must not be surfaced as production documentation, default config, deployment wiring, or UI capability',
    're-specified as `webrtc_native` under the current backend-authoritative model before implementation',
    'server-issued peer identity, server-issued topology, explicit `call_id` and `room_id` binding, persisted participant/admission state, owner/moderator/admin authority checks, session revocation handling, and room-scoped event routing',
    'Browser peers may never invent their own call, room, peer, relay, or neighbor authority.',
    'policy decisions and participant visibility come from the backend',
    'P2P media/control frames must use the existing protected-media contracts when policy requires protection',
    '`call_id`, `room_id`, `participant_set_hash`, `runtime_path`, media suite, epoch, sender key id, and downgrade behavior must remain bound to the frame/header and key transcript',
    'Transport security from DTLS/SRTP, WSS, or WebRTC DataChannel is not enough to claim protected media.',
    'Without the app-level protected media envelope and downgrade tests, the only honest state is `transport_only`.',
    'cross-room isolation, admission revocation, participant-set churn rekey, replay/duplicate handling, relay fallback authorization, and no plaintext fallback in required mode',
];

foreach ($provenanceNeedles as $needle) {
    require_contains('documentation/experiment-intake-provenance.md', $needle);
}

$mediaContractNeedles = [
    '"runtime_path"',
    '"webrtc_native"',
    '"wlvc_sfu"',
    '"call_id"',
    '"room_id"',
    '"participant_set_hash"',
    '"downgrade_behavior": "downgrade_attempt"',
    '"forbidden_claims_without_media_e2ee_active"',
];
foreach ($mediaContractNeedles as $needle) {
    require_contains('demo/video-chat/contracts/v1/e2ee-session.contract.json', $needle);
}

$protectedFrameNeedles = [
    '"plaintext_never_crosses_sfu": true',
    '"required_mode_plaintext_fallback_allowed": false',
    '"downgrade-required-to-plaintext"',
];
foreach ($protectedFrameNeedles as $needle) {
    require_contains('demo/video-chat/contracts/v1/protected-media-frame.contract.json', $needle);
}

require_contains('READYNESS_TRACKER.md', 'Q-14 direct P2P research policy');

echo "OK\n";
?>
--EXPECT--
OK
