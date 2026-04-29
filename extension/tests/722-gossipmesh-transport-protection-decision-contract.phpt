--TEST--
King GossipMesh intake requires app-level protected envelopes beyond transport protection
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
    'Transport protection decision:',
    'Transport-level protection from WebRTC DataChannel, DTLS, SRTP, WSS, or TLS is not sufficient for intended media/control payloads',
    'codec frames, audio/video units, sender keys, participant state, topology control, room policy, or relay instructions',
    'may only support the honest `transport_only` state and hop-by-hop transport confidentiality',
    'does not provide application-level participant binding, replay binding, epoch binding, sender-key binding, downgrade proof, relay visibility limits, or stable authorization semantics across SFU, relay, gossip, and storage paths',
    'App-level protected envelopes are required whenever room policy is `required`, whenever payloads cross SFU, relay, or gossip peers, whenever payloads can be recorded, stored, or forwarded, or whenever UI, telemetry, API, or docs claim protected media or E2EE.',
    'Required envelope claims must remain bound to `call_id`, `room_id`, `participant_set_hash`, `runtime_path`, `kex_suite`, `media_suite`, `epoch`, `sender_key_id`, `sequence`, AAD length, and ciphertext length',
    'Plaintext or JSON fallback is allowed only in `transport_only` under `preferred` or `disabled` policy where UI and telemetry expose `transport_only`',
    'forbidden in `required` mode and forbidden when a `protected_frame` field is present',
    'Any GossipMesh or P2P port must use `king-video-chat-protected-media-transport-envelope` or an equivalent versioned IIBIN envelope.',
    'must never see raw media keys, shared secrets, or plaintext media',
];

foreach ($provenanceNeedles as $needle) {
    require_contains('documentation/experiment-intake-provenance.md', $needle);
}

$sessionContractNeedles = [
    '"transport_only"',
    '"may_render_e2ee_claim": false',
    '"allowed_transport_wording"',
    '"forbidden_claims_without_media_e2ee_active"',
    '"downgrade_behavior": "downgrade_attempt"',
    '"sender_key_aad_bound_to"',
    '"call_id"',
    '"room_id"',
    '"participant_set_hash"',
];
foreach ($sessionContractNeedles as $needle) {
    require_contains('demo/video-chat/contracts/v1/e2ee-session.contract.json', $needle);
}

$protectedFrameNeedles = [
    '"plaintext_never_crosses_sfu": true',
    '"required_mode_plaintext_fallback_allowed": false',
    '"downgrade-required-to-plaintext"',
    '"forbidden_to_sfu"',
    '"associated_data"',
    '"required_fields"',
    '"ciphertext_length"',
];
foreach ($protectedFrameNeedles as $needle) {
    require_contains('demo/video-chat/contracts/v1/protected-media-frame.contract.json', $needle);
}

$transportEnvelopeNeedles = [
    '"protected_frame"',
    '"plaintext_data_field_allowed_when_present": false',
    '"protected_or_required_mode_legacy_data_array_allowed": false',
    '"required_mode_plaintext_fallback_allowed": false',
    '"sfu_parse_purpose": "bounds and relay-visible header validation only; never decryption"',
];
foreach ($transportEnvelopeNeedles as $needle) {
    require_contains('demo/video-chat/contracts/v1/protected-media-transport-envelope.contract.json', $needle);
}

require_contains('READYNESS_TRACKER.md', 'Q-14 transport protection decision');

echo "OK\n";
?>
--EXPECT--
OK
