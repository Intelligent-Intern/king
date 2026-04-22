<?php

declare(strict_types=1);

function videochat_media_security_fail(string $message): never
{
    fwrite(STDERR, "[media-security-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_media_security_assert(bool $condition, string $message): void
{
    if (!$condition) {
        videochat_media_security_fail($message);
    }
}

/**
 * @return array<string, mixed>
 */
function videochat_media_security_json(string $path, string $label): array
{
    $raw = file_get_contents($path);
    videochat_media_security_assert(is_string($raw) && trim($raw) !== '', "{$label} must be readable");

    try {
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        videochat_media_security_fail("{$label} JSON decode failed: " . $error->getMessage());
    }

    videochat_media_security_assert(is_array($decoded), "{$label} must decode to an object");
    return $decoded;
}

/**
 * @param array<int, mixed> $values
 * @return array<int, string>
 */
function videochat_media_security_string_ids(array $values): array
{
    $ids = [];
    foreach ($values as $value) {
        if (is_string($value)) {
            $ids[] = $value;
            continue;
        }
        if (is_array($value) && is_string($value['id'] ?? null)) {
            $ids[] = (string) $value['id'];
        }
    }
    return $ids;
}

/**
 * @param array<int, string> $haystack
 * @param array<int, string> $needles
 */
function videochat_media_security_assert_contains_all(array $haystack, array $needles, string $label): void
{
    foreach ($needles as $needle) {
        videochat_media_security_assert(in_array($needle, $haystack, true), "{$label} missing {$needle}");
    }
}

try {
    $root = realpath(__DIR__ . '/../..');
    videochat_media_security_assert(is_string($root) && $root !== '', 'video-chat root must resolve');

    $session = videochat_media_security_json($root . '/contracts/v1/e2ee-session.contract.json', 'e2ee-session contract');
    $kex = videochat_media_security_json($root . '/contracts/v1/media-kex.contract.json', 'media-kex contract');
    $frame = videochat_media_security_json($root . '/contracts/v1/protected-media-frame.contract.json', 'protected-media-frame contract');
    $transport = videochat_media_security_json($root . '/contracts/v1/protected-media-transport-envelope.contract.json', 'protected-media-transport-envelope contract');

    videochat_media_security_assert(($session['contract_name'] ?? null) === 'king-video-chat-e2ee-session', 'session contract_name mismatch');
    videochat_media_security_assert(($kex['contract_name'] ?? null) === 'king-video-chat-media-kex', 'kex contract_name mismatch');
    videochat_media_security_assert(($frame['contract_name'] ?? null) === 'king-video-chat-protected-media-frame', 'frame contract_name mismatch');
    videochat_media_security_assert(($transport['contract_name'] ?? null) === 'king-video-chat-protected-media-transport-envelope', 'transport contract_name mismatch');
    videochat_media_security_assert(preg_match('/^v1\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/', (string) ($session['contract_version'] ?? '')) === 1, 'session contract_version must be v1 semver');
    videochat_media_security_assert(preg_match('/^v1\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/', (string) ($kex['contract_version'] ?? '')) === 1, 'kex contract_version must be v1 semver');
    videochat_media_security_assert(preg_match('/^v1\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/', (string) ($frame['contract_version'] ?? '')) === 1, 'frame contract_version must be v1 semver');
    videochat_media_security_assert(preg_match('/^v1\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/', (string) ($transport['contract_version'] ?? '')) === 1, 'transport contract_version must be v1 semver');
    videochat_media_security_assert(($session['kex_contract'] ?? null) === 'king-video-chat-media-kex', 'session must pin KEX contract');

    $requiredStates = ['transport_only', 'protected_not_ready', 'media_e2ee_active', 'blocked_capability', 'rekeying', 'decrypt_error'];
    $stateIds = videochat_media_security_string_ids((array) ($session['security_states'] ?? []));
    videochat_media_security_assert($stateIds === $requiredStates, 'security_states must be exact and ordered');

    foreach ((array) ($session['security_states'] ?? []) as $state) {
        videochat_media_security_assert(is_array($state), 'each security state must be object');
        $id = (string) ($state['id'] ?? '');
        $mayRender = $state['may_render_e2ee_claim'] ?? null;
        videochat_media_security_assert(is_bool($mayRender), "{$id}.may_render_e2ee_claim must be bool");
        videochat_media_security_assert(($id === 'media_e2ee_active') === $mayRender, "only media_e2ee_active may render media-E2EE claims");
    }

    $runtimePaths = [];
    foreach ((array) ($session['runtime_paths'] ?? []) as $runtimePath) {
        videochat_media_security_assert(is_array($runtimePath), 'runtime_path entry must be object');
        $runtimePaths[] = (string) ($runtimePath['id'] ?? '');
        videochat_media_security_assert(($runtimePath['media_protection_contract'] ?? null) === 'king-video-chat-protected-media-frame', 'runtime paths must share protected-media contract');
        videochat_media_security_assert(($runtimePath['raw_media_key_visibility'] ?? null) === 'client_only', 'raw media keys must stay client-only');
    }
    sort($runtimePaths);
    videochat_media_security_assert($runtimePaths === ['webrtc_native', 'wlvc_sfu'], 'runtime paths must be native WebRTC and WLVC/SFU');

    $policy = (array) (($session['capability_negotiation'] ?? [])['policy_modes'] ?? []);
    videochat_media_security_assert($policy === ['required', 'preferred', 'disabled'], 'capability policy modes must be required, preferred, disabled');
    videochat_media_security_assert((array) (($session['capability_negotiation'] ?? [])['kex_policy_modes'] ?? []) === ['classical_required', 'hybrid_preferred', 'hybrid_required'], 'KEX policy modes mismatch');
    videochat_media_security_assert((string) (($session['capability_negotiation'] ?? [])['default_kex_suite'] ?? '') === 'x25519_hkdf_sha256_v1', 'default KEX suite mismatch');
    videochat_media_security_assert((string) (($session['capability_negotiation'] ?? [])['hybrid_kex_suite'] ?? '') === 'hybrid_x25519_mlkem768_hkdf_sha256_v1', 'hybrid KEX suite mismatch');
    videochat_media_security_assert(($session['capability_negotiation']['hybrid_requires_explicit_policy'] ?? false) === true, 'hybrid KEX must require explicit policy');
    foreach ((array) (($session['capability_negotiation'] ?? [])['deterministic_policy'] ?? []) as $row) {
        videochat_media_security_assert(is_array($row), 'policy row must be object');
        if (($row['mode'] ?? null) === 'required') {
            videochat_media_security_assert(($row['plaintext_fallback_allowed'] ?? true) === false, 'required policy must forbid plaintext fallback');
            videochat_media_security_assert(($row['when_any_participant_lacks_support'] ?? null) === 'blocked_capability', 'required policy must fail closed on missing capability');
        }
    }

    $keyEstablishment = (array) ($session['key_establishment'] ?? []);
    videochat_media_security_assert(($keyEstablishment['contract'] ?? null) === 'king-video-chat-media-kex', 'key_establishment must reference media-kex');
    videochat_media_security_assert(($keyEstablishment['transcript_hash'] ?? null) === 'sha256_base64url', 'KEX transcript hash mismatch');
    videochat_media_security_assert(($keyEstablishment['downgrade_behavior'] ?? null) === 'downgrade_attempt', 'KEX downgrade behavior mismatch');
    videochat_media_security_assert_contains_all((array) ($keyEstablishment['selected_suite_pinned_in'] ?? []), ['hello_transcript', 'sender_key', 'protected_media_frame_header'], 'selected_suite_pinned_in');
    videochat_media_security_assert_contains_all((array) ($keyEstablishment['sender_key_aad_bound_to'] ?? []), ['call_id', 'room_id', 'participant_set_hash', 'kex_transcript_hash', 'kex_suite', 'media_suite', 'epoch', 'sender_key_id'], 'sender_key_aad_bound_to');

    $keyStates = (array) (($session['participant_key_state'] ?? [])['states'] ?? []);
    videochat_media_security_assert_contains_all($keyStates, ['unknown', 'capability_ready', 'keying', 'active', 'rekeying', 'removed', 'failed'], 'participant_key_state.states');
    $epoch = (array) (($session['participant_key_state'] ?? [])['epoch_semantics'] ?? []);
    videochat_media_security_assert((int) ($epoch['epoch_min'] ?? 0) === 1, 'epoch_min must be 1');
    videochat_media_security_assert(($epoch['stale_epoch_behavior'] ?? null) === 'reject_with_wrong_epoch', 'stale epoch must reject');

    $negativeTests = (array) ($session['negative_tests'] ?? []);
    videochat_media_security_assert_contains_all($negativeTests, ['unsupported_capability', 'mixed_room_policy', 'invalid_control_state', 'downgrade_attempt', 'malformed_protected_frame'], 'negative_tests');
    $errorCodes = (array) ($session['error_codes'] ?? []);
    videochat_media_security_assert_contains_all($errorCodes, ['unsupported_capability', 'mixed_room_policy', 'invalid_control_state', 'downgrade_attempt', 'malformed_protected_frame', 'wrong_epoch', 'wrong_key_id', 'replay_detected', 'decrypt_failed', 'stale_post_removal_key'], 'error_codes');

    videochat_media_security_assert(($frame['magic_ascii'] ?? null) === 'KPMF', 'protected frame magic must be KPMF');
    videochat_media_security_assert(($frame['wire_encoding'] ?? null) === 'typed_binary_envelope_v1', 'protected frame wire encoding must be typed binary envelope');
    videochat_media_security_assert(($frame['kex_contract'] ?? null) === 'king-video-chat-media-kex', 'protected frame must pin KEX contract');
    videochat_media_security_assert(($frame['transport_envelope_contract'] ?? null) === 'king-video-chat-protected-media-transport-envelope', 'protected frame must pin transport envelope contract');
    videochat_media_security_assert((int) (($frame['header'] ?? [])['min_length_bytes'] ?? 0) >= 80, 'protected frame header must be bounded and explicit');

    $publicFields = [];
    foreach ((array) (($frame['header'] ?? [])['public_fields'] ?? []) as $field) {
        if (is_array($field) && is_string($field['name'] ?? null)) {
            $publicFields[] = (string) $field['name'];
        }
    }
    videochat_media_security_assert_contains_all($publicFields, ['magic', 'version', 'runtime_path', 'track_kind', 'frame_kind', 'kex_suite', 'media_suite', 'epoch', 'sender_key_id', 'sequence', 'nonce', 'aad_length', 'ciphertext_length', 'tag_length'], 'protected frame public fields');

    $relayForbidden = (array) (($frame['relay_visibility'] ?? [])['forbidden_to_sfu'] ?? []);
    videochat_media_security_assert_contains_all($relayForbidden, ['raw_media_key', 'private_key', 'shared_secret', 'plaintext_frame', 'decoded_audio', 'decoded_video'], 'relay forbidden fields');
    $compatibility = (array) ($frame['compatibility'] ?? []);
    videochat_media_security_assert(($compatibility['required_mode_plaintext_fallback_allowed'] ?? true) === false, 'required mode plaintext fallback must be forbidden');
    videochat_media_security_assert(($compatibility['mixed_room_required_mode_behavior'] ?? null) === 'blocked_capability', 'mixed required room behavior must fail closed');

    $frameErrors = [];
    foreach ((array) ($frame['parse_failures'] ?? []) as $failure) {
        if (is_array($failure) && is_string($failure['error_code'] ?? null)) {
            $frameErrors[] = (string) $failure['error_code'];
        }
    }
    videochat_media_security_assert_contains_all($frameErrors, ['malformed_protected_frame', 'unsupported_capability', 'wrong_epoch', 'wrong_key_id', 'replay_detected', 'decrypt_failed'], 'protected frame error codes');

    $kexSelection = (array) ($kex['suite_selection'] ?? []);
    videochat_media_security_assert(($kexSelection['default_suite'] ?? null) === 'x25519_hkdf_sha256_v1', 'KEX default suite mismatch');
    videochat_media_security_assert((array) ($kexSelection['policy_modes'] ?? []) === ['classical_required', 'hybrid_preferred', 'hybrid_required'], 'KEX policy modes must be exact and ordered');
    videochat_media_security_assert(($kexSelection['hybrid_requires_explicit_policy'] ?? false) === true, 'hybrid suite must require explicit KEX policy');
    videochat_media_security_assert(($kexSelection['required_policy_missing_suite_behavior'] ?? null) === 'blocked_capability', 'hybrid_required missing suite behavior mismatch');
    videochat_media_security_assert(($kexSelection['downgrade_behavior'] ?? null) === 'downgrade_attempt', 'KEX downgrade behavior must fail closed');

    $suiteIds = [];
    $suiteFamilies = [];
    foreach ((array) ($kex['suites'] ?? []) as $suite) {
        if (is_array($suite) && is_string($suite['id'] ?? null)) {
            $suiteIds[] = (string) $suite['id'];
            $suiteFamilies[(string) $suite['id']] = (string) ($suite['family'] ?? '');
            if (($suite['id'] ?? null) === 'hybrid_x25519_mlkem768_hkdf_sha256_v1') {
                videochat_media_security_assert(($suite['requires_external_provider'] ?? false) === true, 'hybrid suite must require external provider');
                videochat_media_security_assert(($suite['enabled_by_default'] ?? true) === false, 'hybrid suite must not be enabled by default');
            }
        }
    }
    videochat_media_security_assert_contains_all($suiteIds, ['x25519_hkdf_sha256_v1', 'hybrid_x25519_mlkem768_hkdf_sha256_v1'], 'KEX suites');
    videochat_media_security_assert(($suiteFamilies['x25519_hkdf_sha256_v1'] ?? '') === 'classical', 'classical KEX family mismatch');
    videochat_media_security_assert(($suiteFamilies['hybrid_x25519_mlkem768_hkdf_sha256_v1'] ?? '') === 'hybrid_classical_pq', 'hybrid KEX family mismatch');

    $kexNegotiation = (array) ($kex['capability_negotiation'] ?? []);
    videochat_media_security_assert_contains_all((array) ($kexNegotiation['hello_fields'] ?? []), ['supported_kex_suites', 'preferred_kex_suites', 'kex_policy', 'public_key', 'hybrid_public_key'], 'KEX hello fields');
    videochat_media_security_assert_contains_all((array) ($kexNegotiation['sender_key_fields'] ?? []), ['kex_suite', 'kex_transcript_hash', 'participant_set_hash', 'rekey_reason'], 'KEX sender-key fields');
    videochat_media_security_assert(($kexNegotiation['mixed_suite_behavior'] ?? null) === 'downgrade_attempt', 'mixed KEX suite behavior mismatch');

    $transcript = (array) ($kex['transcript_binding'] ?? []);
    videochat_media_security_assert(($transcript['hash'] ?? null) === 'sha256_base64url', 'KEX transcript binding hash mismatch');
    videochat_media_security_assert_contains_all((array) ($transcript['bound_fields'] ?? []), ['call_id', 'room_id', 'participant_set_hash', 'participant_user_ids', 'device_ids', 'x25519_public_keys', 'hybrid_public_keys', 'selected_kex_suite', 'media_suite', 'kex_policy', 'epoch', 'sender_key_id'], 'KEX transcript bound fields');
    videochat_media_security_assert(($transcript['sender_key_aad_must_include_transcript_hash'] ?? false) === true, 'sender-key AAD must include transcript hash');
    videochat_media_security_assert(($transcript['sender_key_aad_must_include_participant_set_hash'] ?? false) === true, 'sender-key AAD must include participant set hash');

    $kexTelemetry = (array) (($kex['telemetry'] ?? [])['public_fields'] ?? []);
    videochat_media_security_assert_contains_all($kexTelemetry, ['security_state', 'runtime_path', 'policy_mode', 'kex_policy', 'kex_suite', 'kex_family', 'media_suite', 'epoch', 'rekey_reason'], 'KEX telemetry public fields');
    $kexTelemetryForbidden = (array) (($kex['telemetry'] ?? [])['forbidden_fields'] ?? []);
    videochat_media_security_assert_contains_all($kexTelemetryForbidden, ['raw_media_key', 'private_key', 'shared_secret', 'plaintext_frame', 'sdp_body', 'ice_credentials', 'mlkem_private_key'], 'KEX telemetry forbidden fields');

    $kexScope = (array) ($kex['post_quantum_scope'] ?? []);
    videochat_media_security_assert(($kexScope['readme_claim_allowed_before_green_downgrade_tests'] ?? true) === false, 'PQ wording must stay out of README/security claims before downgrade tests');
    videochat_media_security_assert_contains_all((array) ($kexScope['not_claimed'] ?? []), ['metadata secrecy', 'topology secrecy', 'signaling secrecy', 'traffic analysis resistance', 'post-quantum protection without negotiated hybrid suite'], 'PQ not-claimed scope');

    videochat_media_security_assert_contains_all((array) ($kex['negative_tests'] ?? []), ['hybrid_required_without_provider_blocks', 'sender_key_suite_downgrade_rejected', 'protected_frame_suite_downgrade_rejected', 'transcript_hash_mismatch_rejected', 'participant_churn_forces_new_epoch'], 'KEX negative tests');

    $transportLayers = (array) ($transport['layers'] ?? []);
    videochat_media_security_assert(is_string($transportLayers['codec_frame'] ?? null) && (string) $transportLayers['codec_frame'] !== '', 'transport layer must separate codec frame');
    videochat_media_security_assert(($transportLayers['protected_media_frame'] ?? null) === 'king-video-chat-protected-media-frame', 'transport layer must reference protected media frame');
    videochat_media_security_assert(($transportLayers['transport_envelope'] ?? null) === 'king-video-chat-protected-media-transport-envelope', 'transport layer must reference itself');

    $jsonCarriage = (array) ($transport['json_carriage'] ?? []);
    videochat_media_security_assert(($jsonCarriage['field'] ?? null) === 'protected_frame', 'protected transport JSON field must be protected_frame');
    videochat_media_security_assert(($jsonCarriage['encoding'] ?? null) === 'base64url', 'protected transport JSON field must be base64url');
    videochat_media_security_assert(($jsonCarriage['plaintext_data_field_allowed_when_present'] ?? true) === false, 'protected transport must forbid data beside protected_frame');
    videochat_media_security_assert(($jsonCarriage['legacy_data_field_allowed_only_in_state'] ?? null) === 'transport_only', 'legacy data field must be transport_only only');
    videochat_media_security_assert(($jsonCarriage['required_mode_plaintext_fallback_allowed'] ?? true) === false, 'transport required mode must forbid plaintext fallback');

    $binaryLayout = (array) ($transport['binary_layout'] ?? []);
    videochat_media_security_assert(($binaryLayout['magic_ascii'] ?? null) === 'KPMF', 'transport binary magic must be KPMF');
    videochat_media_security_assert((int) ($binaryLayout['version'] ?? 0) === 1, 'transport binary version must be 1');
    videochat_media_security_assert((int) ($binaryLayout['prefix_bytes'] ?? 0) === 8, 'transport binary prefix must be eight bytes');
    videochat_media_security_assert(($binaryLayout['header_length_encoding'] ?? null) === 'u32_be', 'transport header length must be u32_be');

    $bounds = (array) ($transport['bounds'] ?? []);
    videochat_media_security_assert((int) ($bounds['min_total_bytes'] ?? 0) >= 80, 'transport min total must be bounded');
    videochat_media_security_assert((int) ($bounds['max_header_bytes'] ?? 0) === 4096, 'transport header max must be 4096');
    videochat_media_security_assert((int) ($bounds['max_ciphertext_bytes'] ?? 0) === 16777216, 'transport ciphertext max must be 16MiB');
    videochat_media_security_assert((int) ($bounds['max_total_bytes'] ?? 0) <= 16781320, 'transport total max must include only prefix, header, and ciphertext');

    $visibility = (array) ($transport['sfu_visibility'] ?? []);
    $visibleFields = (array) ($visibility['json_fields'] ?? []);
    videochat_media_security_assert_contains_all($visibleFields, ['type', 'publisher_id', 'publisher_user_id', 'track_id', 'timestamp', 'frame_type', 'protection_mode', 'protected_frame'], 'transport visible JSON fields');
    $forbiddenFields = (array) ($visibility['forbidden_json_fields_when_protected'] ?? []);
    videochat_media_security_assert_contains_all($forbiddenFields, ['data', 'protected', 'raw_media_key', 'private_key', 'shared_secret', 'plaintext_frame', 'decoded_audio', 'decoded_video'], 'transport forbidden JSON fields');

    $transportFailures = (array) ($transport['parse_failures'] ?? []);
    videochat_media_security_assert_contains_all($transportFailures, ['missing_protected_frame', 'malformed_protected_frame', 'protected_frame_too_large', 'protected_frame_data_conflict', 'protected_frame_required', 'forbidden_protected_metadata'], 'transport parse failures');
    $transportCompatibility = (array) ($transport['compatibility'] ?? []);
    videochat_media_security_assert(($transportCompatibility['protected_or_required_mode_legacy_data_array_allowed'] ?? true) === false, 'protected transport must reject legacy data arrays');
    videochat_media_security_assert(($transportCompatibility['required_mode_plaintext_fallback_allowed'] ?? true) === false, 'protected transport required mode must reject plaintext fallback');

    $sourcePaths = [
        $root . '/frontend-vue/src',
        $root . '/backend-king-php/domain',
        $root . '/backend-king-php/http',
        $root . '/backend-king-php/public',
        $root . '/backend-king-php/support',
        $root . '/backend-king-php/server.php',
        $root . '/edge',
    ];
    foreach ($sourcePaths as $sourcePath) {
        $files = is_file($sourcePath)
            ? [new SplFileInfo($sourcePath)]
            : iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS)));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) continue;
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, ['php', 'js', 'ts', 'vue', 'css', 'mjs'], true)) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            videochat_media_security_assert(is_string($contents), 'source file must be readable');
            videochat_media_security_assert(preg_match('/\bE2EE\b|end-to-end encrypted media|post-quantum protected media/i', $contents) !== 1, 'runtime source must not claim media E2EE/PQ before media_e2ee_active implementation: ' . str_replace($root . '/', '', $file->getPathname()));
        }
    }

    fwrite(STDOUT, "[media-security-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[media-security-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
