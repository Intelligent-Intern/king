<?php

declare(strict_types=1);

const VIDEOCHAT_GOSSIPMESH_CONTRACT = 'king-video-chat-gossipmesh-runtime';
const VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT = 'king-video-chat-protected-media-transport-envelope';
const VIDEOCHAT_GOSSIPMESH_RUNTIME_PATH = 'wlvc_sfu';
const VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS = 5;
const VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT = 3;
const VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS = 4;
const VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT = 4;
const VIDEOCHAT_GOSSIPMESH_SEEN_WINDOW_SIZE = 256;
const VIDEOCHAT_GOSSIPMESH_MAX_RELAY_CANDIDATES = 10;
const VIDEOCHAT_GOSSIPMESH_MAX_PROTECTED_FRAME_BYTES = 16_781_320;
const VIDEOCHAT_GOSSIPMESH_TOPOLOGY_REPAIR_TYPE = 'gossip/topology-repair/request';
const VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HINT_TYPE = 'topology_hint';
const VIDEOCHAT_GOSSIPMESH_CALL_TOPOLOGY_TYPE = 'call/gossip-topology';
const VIDEOCHAT_GOSSIPMESH_DATA_ENVELOPE_CONTRACT = 'king-video-chat-gossipmesh-iibin-media-envelope';
const VIDEOCHAT_GOSSIPMESH_DATA_CODEC = 'iibin';
const VIDEOCHAT_GOSSIPMESH_TOPOLOGY_REPAIR_MAX_REASON_BYTES = 160;
const VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_SCHEMA_VERSION = 1;
const VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_KIND = 'gossip_topology_link_health';
const VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_OBJECT_PREFIX = 'vcgmh_';
const VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_COOLDOWN_MS = 120_000;
const VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_READBACK_LIMIT = 64;
const VIDEOCHAT_GOSSIPMESH_TELEMETRY_SNAPSHOT_TYPE = 'gossip/telemetry/snapshot';
const VIDEOCHAT_GOSSIPMESH_TELEMETRY_MAX_COUNTER_VALUE = 1_000_000_000;

/**
 * @return array<int, string>
 */
function videochat_gossipmesh_forbidden_payload_fields(): array
{
    return [
        'raw_media_key',
        'private_key',
        'shared_secret',
        'plaintext_frame',
        'decoded_audio',
        'decoded_video',
        'websocket',
        'socket',
        'ip',
        'port',
        'sdp',
        'ice_candidate',
        'iceServers',
    ];
}

/**
 * @return array<int, string>
 */
function videochat_gossipmesh_topology_repair_forbidden_fields(): array
{
    return array_values(array_unique([
        ...videochat_gossipmesh_forbidden_payload_fields(),
        'candidate',
        'sdpMid',
        'sdpMLineIndex',
        'data',
        'chunks',
        'chunk',
        'bytes',
        'payload_bytes',
        'protected_frame',
        'media_frame',
        'encoded_frame',
        'audio_frame',
        'video_frame',
        'sender_key',
        'envelope_contract',
    ]));
}

/**
 * @return array<int, string>
 */
function videochat_gossipmesh_telemetry_forbidden_fields(): array
{
    return array_values(array_unique([
        ...videochat_gossipmesh_topology_repair_forbidden_fields(),
        'payload',
        'payload_base64',
        'data_base64',
        'protected',
        'protectedFrame',
        'protected_frame',
        'frame',
        'frames',
        'offer',
        'answer',
        'candidates',
        'authorization',
        'cookie',
        'token',
    ]));
}

/**
 * @return array<int, string>
 */
function videochat_gossipmesh_telemetry_counter_names(): array
{
    return [
        'sent',
        'received',
        'forwarded',
        'dropped',
        'duplicates',
        'ttl_exhausted',
        'late_drops',
        'stale_generation_drops',
        'server_fanout_avoided',
        'peer_outbound_fanout',
        'rtc_datachannel_sends',
        'in_memory_harness_sends',
        'topology_repairs_requested',
    ];
}

/**
 * @return array<int, string>
 */
function videochat_gossipmesh_telemetry_transport_labels(): array
{
    return ['rtc_datachannel', 'in_memory_harness', 'server_fanout', 'unknown'];
}

function videochat_gossipmesh_payload_contains_forbidden_field(mixed $payload, array $forbiddenFields): bool
{
    if (!is_array($payload)) {
        return false;
    }

    foreach ($payload as $key => $value) {
        if (is_string($key) && in_array($key, $forbiddenFields, true)) {
            return true;
        }
        if (is_array($value) && videochat_gossipmesh_payload_contains_forbidden_field($value, $forbiddenFields)) {
            return true;
        }
    }

    return false;
}

function videochat_gossipmesh_safe_label(mixed $value, array $allowed, string $default): string
{
    $label = strtolower(trim((string) $value));
    return in_array($label, $allowed, true) ? $label : $default;
}

/**
 * @return array<string, int>
 */
function videochat_gossipmesh_sanitize_telemetry_counters(mixed $value): array
{
    $input = is_array($value) ? $value : [];
    $counters = [];
    foreach (videochat_gossipmesh_telemetry_counter_names() as $counterName) {
        $rawValue = $input[$counterName] ?? 0;
        $counterValue = is_int($rawValue) || (is_string($rawValue) && preg_match('/^\d+$/', $rawValue) === 1)
            ? (int) $rawValue
            : (is_float($rawValue) ? (int) floor($rawValue) : 0);
        $counters[$counterName] = max(0, min(VIDEOCHAT_GOSSIPMESH_TELEMETRY_MAX_COUNTER_VALUE, $counterValue));
    }

    return $counters;
}

function videochat_gossipmesh_clamp_int(mixed $value, int $default, int $min, int $max): int
{
    if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
        return $default;
    }

    return max($min, min($max, (int) $value));
}

function videochat_gossipmesh_safe_id(mixed $value): string
{
    $id = trim((string) $value);
    if ($id === '' || strlen($id) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $id) !== 1) {
        return '';
    }

    return $id;
}

function videochat_gossipmesh_safe_object_key(mixed $value): string
{
    $id = trim((string) $value);
    if ($id === '' || strlen($id) > 127 || preg_match('/^[A-Za-z0-9._:-]+$/', $id) !== 1) {
        return '';
    }

    return $id;
}

function videochat_gossipmesh_member_has_left(array $member): bool
{
    foreach (['left_at', 'removed_at'] as $field) {
        if (trim((string) ($member[$field] ?? '')) !== '') {
            return true;
        }
    }

    $leftStates = ['left', 'removed', 'kicked', 'banned', 'revoked', 'denied', 'expired', 'cancelled', 'canceled'];
    foreach (['invite_state', 'state', 'membership_state'] as $field) {
        $state = strtolower(trim((string) ($member[$field] ?? '')));
        if (in_array($state, $leftStates, true)) {
            return true;
        }
    }

    return false;
}

function videochat_gossipmesh_is_admitted_member(array $member): bool
{
    if (videochat_gossipmesh_member_has_left($member)) {
        return false;
    }

    if (($member['admitted'] ?? false) === true) {
        return true;
    }

    $state = strtolower(trim((string) ($member['invite_state'] ?? $member['state'] ?? '')));
    if (in_array($state, ['allowed', 'moderator', 'owner', 'admin', 'joined'], true)) {
        return true;
    }

    return trim((string) ($member['joined_at'] ?? '')) !== '' && !in_array($state, ['pending', 'queued', 'invited'], true);
}

/**
 * @return array<string, mixed>
 */
function videochat_gossipmesh_decode_topology_repair_request(string $frame): array
{
    try {
        $decoded = json_decode($frame, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['ok' => false, 'type' => '', 'error' => 'invalid_json'];
    }

    if (!is_array($decoded)) {
        return ['ok' => false, 'type' => '', 'error' => 'invalid_command'];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type !== VIDEOCHAT_GOSSIPMESH_TOPOLOGY_REPAIR_TYPE) {
        return ['ok' => false, 'type' => $type, 'error' => 'unsupported_type'];
    }

    $lane = strtolower(trim((string) ($decoded['lane'] ?? '')));
    if ($lane !== 'ops') {
        return ['ok' => false, 'type' => $type, 'error' => 'invalid_lane'];
    }

    $forbiddenFields = videochat_gossipmesh_topology_repair_forbidden_fields();
    $wrapperProbe = $decoded;
    unset($wrapperProbe['payload']);
    if (videochat_gossipmesh_payload_contains_forbidden_field($wrapperProbe, $forbiddenFields)) {
        return ['ok' => false, 'type' => $type, 'error' => 'forbidden_media_or_signaling_field'];
    }

    $payload = $decoded['payload'] ?? null;
    if (!is_array($payload)) {
        return ['ok' => false, 'type' => $type, 'error' => 'invalid_payload'];
    }

    if (videochat_gossipmesh_payload_contains_forbidden_field($payload, $forbiddenFields)) {
        return ['ok' => false, 'type' => $type, 'error' => 'forbidden_media_or_signaling_field'];
    }

    $kind = strtolower(trim((string) ($payload['kind'] ?? '')));
    if ($kind !== '' && $kind !== 'gossip_topology_repair_request') {
        return ['ok' => false, 'type' => $type, 'error' => 'invalid_payload_kind'];
    }

    $roomId = videochat_gossipmesh_safe_id($payload['room_id'] ?? '');
    $callId = videochat_gossipmesh_safe_id($payload['call_id'] ?? '');
    $peerId = videochat_gossipmesh_safe_id($payload['peer_id'] ?? '');
    $lostPeerId = videochat_gossipmesh_safe_id($payload['lost_peer_id'] ?? ($payload['lost_neighbor_peer_id'] ?? ''));
    $reason = trim((string) ($payload['reason'] ?? ''));
    if (strlen($reason) > VIDEOCHAT_GOSSIPMESH_TOPOLOGY_REPAIR_MAX_REASON_BYTES) {
        $reason = substr($reason, 0, VIDEOCHAT_GOSSIPMESH_TOPOLOGY_REPAIR_MAX_REASON_BYTES);
    }

    if ($roomId === '' || $callId === '' || $peerId === '') {
        return ['ok' => false, 'type' => $type, 'error' => 'missing_context'];
    }
    if ($lostPeerId === '' || $lostPeerId === $peerId) {
        return ['ok' => false, 'type' => $type, 'error' => 'invalid_lost_neighbor'];
    }

    return [
        'ok' => true,
        'type' => $type,
        'lane' => 'ops',
        'room_id' => $roomId,
        'call_id' => $callId,
        'peer_id' => $peerId,
        'lost_peer_id' => $lostPeerId,
        'reason' => $reason,
        'payload' => $payload,
        'error' => '',
    ];
}

function videochat_gossipmesh_topology_health_object_key(string $roomId, string $callId, string $peerId, string $lostPeerId): string
{
    $roomId = videochat_gossipmesh_safe_id($roomId);
    $callId = videochat_gossipmesh_safe_id($callId);
    $peerId = videochat_gossipmesh_safe_id($peerId);
    $lostPeerId = videochat_gossipmesh_safe_id($lostPeerId);
    if ($roomId === '' || $callId === '' || $peerId === '' || $lostPeerId === '') {
        return '';
    }

    return VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_OBJECT_PREFIX
        . substr(hash('sha256', $roomId), 0, 12) . '_'
        . substr(hash('sha256', $callId), 0, 12) . '_'
        . substr(hash('sha256', $peerId), 0, 12) . '_'
        . substr(hash('sha256', $lostPeerId), 0, 12);
}

function videochat_gossipmesh_link_pair_key(string $leftPeerId, string $rightPeerId): string
{
    $leftPeerId = videochat_gossipmesh_safe_id($leftPeerId);
    $rightPeerId = videochat_gossipmesh_safe_id($rightPeerId);
    if ($leftPeerId === '' || $rightPeerId === '' || $leftPeerId === $rightPeerId) {
        return '';
    }

    $peers = [$leftPeerId, $rightPeerId];
    sort($peers, SORT_STRING);
    return $peers[0] . '|' . $peers[1];
}

/**
 * @return array<string, mixed>
 */
function videochat_gossipmesh_sanitize_topology_health_observation(array $repairCommand, ?int $nowMs = null): array
{
    $roomId = videochat_gossipmesh_safe_id($repairCommand['room_id'] ?? '');
    $callId = videochat_gossipmesh_safe_id($repairCommand['call_id'] ?? '');
    $peerId = videochat_gossipmesh_safe_id($repairCommand['peer_id'] ?? '');
    $lostPeerId = videochat_gossipmesh_safe_id($repairCommand['lost_peer_id'] ?? '');
    $pairKey = videochat_gossipmesh_link_pair_key($peerId, $lostPeerId);
    $objectKey = videochat_gossipmesh_topology_health_object_key($roomId, $callId, $peerId, $lostPeerId);
    $nowMs = $nowMs ?? (int) floor(microtime(true) * 1000);
    $cooldownMs = VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_COOLDOWN_MS;
    $reason = trim((string) ($repairCommand['reason'] ?? 'topology_repair'));
    if ($reason === '') {
        $reason = 'topology_repair';
    }
    if (strlen($reason) > VIDEOCHAT_GOSSIPMESH_TOPOLOGY_REPAIR_MAX_REASON_BYTES) {
        $reason = substr($reason, 0, VIDEOCHAT_GOSSIPMESH_TOPOLOGY_REPAIR_MAX_REASON_BYTES);
    }

    $payload = is_array($repairCommand['payload'] ?? null) ? $repairCommand['payload'] : [];
    $linkHealth = is_array($payload['link_health'] ?? null) ? $payload['link_health'] : [];
    $sanitizedHealth = [];
    foreach ([
        'state',
        'transport',
        'data_lane_mode',
        'failure_stage',
    ] as $field) {
        $value = $linkHealth[$field] ?? ($payload[$field] ?? null);
        $value = trim((string) $value);
        if ($value !== '') {
            $sanitizedHealth[$field] = substr($value, 0, 80);
        }
    }
    foreach ([
        'consecutive_failures',
        'retry_count',
        'rtt_ms',
        'jitter_ms',
        'loss_percent',
    ] as $field) {
        $value = $linkHealth[$field] ?? ($payload[$field] ?? null);
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1)) {
            $sanitizedHealth[$field] = max(0, min(1_000_000, (float) $value));
        }
    }

    return [
        'contract' => VIDEOCHAT_GOSSIPMESH_CONTRACT,
        'kind' => VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_KIND,
        'schema_version' => VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_SCHEMA_VERSION,
        'object_key' => $objectKey,
        'room_id' => $roomId,
        'call_id' => $callId,
        'peer_id' => $peerId,
        'lost_peer_id' => $lostPeerId,
        'pair_key' => $pairKey,
        'reason' => $reason,
        'observed_unix_ms' => $nowMs,
        'observed_time' => gmdate('c', (int) floor($nowMs / 1000)),
        'failure_cooldown_ms' => $cooldownMs,
        'failed_until_unix_ms' => $nowMs + $cooldownMs,
        'link_health' => $sanitizedHealth,
    ];
}

function videochat_gossipmesh_topology_health_store_put(string $objectKey, string $json): bool
{
    $override = $GLOBALS['videochat_gossipmesh_topology_health_store_put'] ?? null;
    if (is_callable($override)) {
        return $override($objectKey, $json, 'application/json') === true;
    }

    if (!function_exists('king_object_store_put')) {
        return false;
    }

    try {
        return king_object_store_put($objectKey, $json, [
            'content_type' => 'application/json',
            'cache_class' => 'private',
        ]) === true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_gossipmesh_topology_health_store_list(): array
{
    $override = $GLOBALS['videochat_gossipmesh_topology_health_store_list'] ?? null;
    if (is_callable($override)) {
        try {
            $entries = $override();
            return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
        } catch (Throwable) {
            return [];
        }
    }

    if (!function_exists('king_object_store_list')) {
        return [];
    }

    try {
        return array_values(array_filter(king_object_store_list(), 'is_array'));
    } catch (Throwable) {
        return [];
    }
}

function videochat_gossipmesh_topology_health_store_get(string $objectKey): string|false
{
    $objectKey = videochat_gossipmesh_safe_object_key($objectKey);
    if ($objectKey === '' || !str_starts_with($objectKey, VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_OBJECT_PREFIX)) {
        return false;
    }

    $override = $GLOBALS['videochat_gossipmesh_topology_health_store_get'] ?? null;
    if (is_callable($override)) {
        try {
            $payload = $override($objectKey);
            return is_string($payload) ? $payload : false;
        } catch (Throwable) {
            return false;
        }
    }

    if (!function_exists('king_object_store_get')) {
        return false;
    }

    try {
        $payload = king_object_store_get($objectKey);
        return is_string($payload) ? $payload : false;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @return array<string, mixed>|null
 */
function videochat_gossipmesh_decode_topology_health_observation(
    mixed $payload,
    string $expectedRoomId,
    string $expectedCallId,
    ?int $nowMs = null,
    string $expectedObjectKey = ''
): ?array {
    $expectedRoomId = videochat_gossipmesh_safe_id($expectedRoomId);
    $expectedCallId = videochat_gossipmesh_safe_id($expectedCallId);
    if ($expectedRoomId === '' || $expectedCallId === '') {
        return null;
    }

    if (is_string($payload)) {
        try {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
    if (!is_array($payload)) {
        return null;
    }

    if (videochat_gossipmesh_payload_contains_forbidden_field($payload, videochat_gossipmesh_telemetry_forbidden_fields())) {
        return null;
    }

    if ((string) ($payload['contract'] ?? '') !== VIDEOCHAT_GOSSIPMESH_CONTRACT) {
        return null;
    }
    if ((string) ($payload['kind'] ?? '') !== VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_KIND) {
        return null;
    }
    if ((int) ($payload['schema_version'] ?? 0) !== VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_SCHEMA_VERSION) {
        return null;
    }

    $roomId = videochat_gossipmesh_safe_id($payload['room_id'] ?? '');
    $callId = videochat_gossipmesh_safe_id($payload['call_id'] ?? '');
    $peerId = videochat_gossipmesh_safe_id($payload['peer_id'] ?? '');
    $lostPeerId = videochat_gossipmesh_safe_id($payload['lost_peer_id'] ?? '');
    if ($roomId !== $expectedRoomId || $callId !== $expectedCallId || $peerId === '' || $lostPeerId === '' || $peerId === $lostPeerId) {
        return null;
    }

    $pairKey = videochat_gossipmesh_link_pair_key($peerId, $lostPeerId);
    $objectKey = videochat_gossipmesh_topology_health_object_key($roomId, $callId, $peerId, $lostPeerId);
    $expectedObjectKey = videochat_gossipmesh_safe_object_key($expectedObjectKey);
    if ($pairKey === '' || $objectKey === '') {
        return null;
    }
    if ($expectedObjectKey !== '' && $expectedObjectKey !== $objectKey) {
        return null;
    }
    if ((string) ($payload['pair_key'] ?? '') !== '' && (string) ($payload['pair_key'] ?? '') !== $pairKey) {
        return null;
    }
    if ((string) ($payload['object_key'] ?? '') !== '' && (string) ($payload['object_key'] ?? '') !== $objectKey) {
        return null;
    }

    $nowMs = $nowMs ?? (int) floor(microtime(true) * 1000);
    $observedMs = videochat_gossipmesh_clamp_int($payload['observed_unix_ms'] ?? 0, 0, 0, PHP_INT_MAX);
    $failedUntilMs = videochat_gossipmesh_clamp_int($payload['failed_until_unix_ms'] ?? 0, 0, 0, PHP_INT_MAX);
    if ($failedUntilMs <= $nowMs) {
        return null;
    }
    if ($observedMs > 0 && $failedUntilMs < $observedMs) {
        return null;
    }

    return [
        'contract' => VIDEOCHAT_GOSSIPMESH_CONTRACT,
        'kind' => VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_KIND,
        'schema_version' => VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_SCHEMA_VERSION,
        'object_key' => $objectKey,
        'room_id' => $roomId,
        'call_id' => $callId,
        'peer_id' => $peerId,
        'lost_peer_id' => $lostPeerId,
        'pair_key' => $pairKey,
        'reason' => substr(trim((string) ($payload['reason'] ?? '')), 0, VIDEOCHAT_GOSSIPMESH_TOPOLOGY_REPAIR_MAX_REASON_BYTES),
        'observed_unix_ms' => $observedMs,
        'failed_until_unix_ms' => $failedUntilMs,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_gossipmesh_load_recent_topology_health_observations(
    string $roomId,
    string $callId,
    ?int $nowMs = null,
    int $limit = VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_READBACK_LIMIT
): array {
    $roomId = videochat_gossipmesh_safe_id($roomId);
    $callId = videochat_gossipmesh_safe_id($callId);
    if ($roomId === '' || $callId === '') {
        return [];
    }

    $limit = videochat_gossipmesh_clamp_int($limit, VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_READBACK_LIMIT, 1, 256);
    $entries = videochat_gossipmesh_topology_health_store_list();
    usort($entries, static function (array $left, array $right): int {
        $leftMs = (int) ($left['modified_unix_ms'] ?? $left['modified_at_unix_ms'] ?? $left['created_unix_ms'] ?? 0);
        $rightMs = (int) ($right['modified_unix_ms'] ?? $right['modified_at_unix_ms'] ?? $right['created_unix_ms'] ?? 0);
        return $rightMs <=> $leftMs;
    });

    $observations = [];
    $readCount = 0;
    foreach ($entries as $entry) {
        $objectKey = videochat_gossipmesh_safe_object_key($entry['object_id'] ?? $entry['object_key'] ?? '');
        if ($objectKey === '' || !str_starts_with($objectKey, VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HEALTH_OBJECT_PREFIX)) {
            continue;
        }

        $raw = videochat_gossipmesh_topology_health_store_get($objectKey);
        $readCount++;
        if (is_string($raw)) {
            $observation = videochat_gossipmesh_decode_topology_health_observation($raw, $roomId, $callId, $nowMs, $objectKey);
            if ($observation !== null) {
                $observations[] = $observation;
            }
        }
        if ($readCount >= $limit || count($observations) >= $limit) {
            break;
        }
    }

    return $observations;
}

/**
 * @return array{ok: bool, object_key: string, observation: array<string, mixed>, error: string}
 */
function videochat_gossipmesh_record_topology_health_observation(array $repairCommand, ?int $nowMs = null): array
{
    $observation = videochat_gossipmesh_sanitize_topology_health_observation($repairCommand, $nowMs);
    $objectKey = (string) ($observation['object_key'] ?? '');
    if ($objectKey === '' || (string) ($observation['pair_key'] ?? '') === '') {
        return ['ok' => false, 'object_key' => $objectKey, 'observation' => $observation, 'error' => 'invalid_observation'];
    }

    $json = json_encode($observation, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || videochat_gossipmesh_payload_contains_forbidden_field($observation, videochat_gossipmesh_topology_repair_forbidden_fields())) {
        return ['ok' => false, 'object_key' => $objectKey, 'observation' => $observation, 'error' => 'unsafe_observation'];
    }

    $stored = videochat_gossipmesh_topology_health_store_put($objectKey, $json);
    return [
        'ok' => $stored,
        'object_key' => $objectKey,
        'observation' => $observation,
        'error' => $stored ? '' : 'object_store_unavailable',
    ];
}

/**
 * @param array<int, array<string, mixed>> $observations
 * @return array<string, bool>
 */
function videochat_gossipmesh_recent_failed_pair_map(array $observations, ?int $nowMs = null): array
{
    $nowMs = $nowMs ?? (int) floor(microtime(true) * 1000);
    $pairs = [];
    foreach ($observations as $observation) {
        if (!is_array($observation)) {
            continue;
        }
        $failedUntilMs = videochat_gossipmesh_clamp_int($observation['failed_until_unix_ms'] ?? 0, 0, 0, PHP_INT_MAX);
        if ($failedUntilMs <= $nowMs) {
            continue;
        }
        $pairKey = videochat_gossipmesh_link_pair_key(
            (string) ($observation['peer_id'] ?? ''),
            (string) ($observation['lost_peer_id'] ?? '')
        );
        if ($pairKey !== '') {
            $pairs[$pairKey] = true;
        }
        if (videochat_gossipmesh_topology_repair_reason_excludes_lost_peer((string) ($observation['reason'] ?? ''))) {
            $lostPeerId = videochat_gossipmesh_safe_id($observation['lost_peer_id'] ?? '');
            if ($lostPeerId !== '') {
                $pairs[videochat_gossipmesh_excluded_peer_pair_key($lostPeerId)] = true;
            }
        }
    }

    return $pairs;
}

function videochat_gossipmesh_topology_repair_reason_excludes_lost_peer(string $reason): bool
{
    $reason = strtolower(trim($reason));
    return in_array($reason, [
        'target_not_in_room',
        'target_left_room',
        'target_left',
        'participant_left',
        'peer_left',
        'not_in_room',
        'stale_target',
        'stale_peer',
    ], true);
}

function videochat_gossipmesh_excluded_peer_pair_key(string $peerId): string
{
    $peerId = videochat_gossipmesh_safe_id($peerId);
    return $peerId === '' ? '' : $peerId . '|*';
}

/**
 * @param array<string, bool> $avoidPairs
 */
function videochat_gossipmesh_should_avoid_pair(string $leftPeerId, string $rightPeerId, array $avoidPairs): bool
{
    $pairKey = videochat_gossipmesh_link_pair_key($leftPeerId, $rightPeerId);
    if ($pairKey !== '' && ($avoidPairs[$pairKey] ?? false) === true) {
        return true;
    }

    $leftExcludeKey = videochat_gossipmesh_excluded_peer_pair_key($leftPeerId);
    $rightExcludeKey = videochat_gossipmesh_excluded_peer_pair_key($rightPeerId);
    return ($leftExcludeKey !== '' && ($avoidPairs[$leftExcludeKey] ?? false) === true)
        || ($rightExcludeKey !== '' && ($avoidPairs[$rightExcludeKey] ?? false) === true);
}

/**
 * @return array<string, mixed>
 */
function videochat_gossipmesh_decode_telemetry_snapshot(string $frame): array
{
    try {
        $decoded = json_decode($frame, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['ok' => false, 'type' => '', 'error' => 'invalid_json'];
    }

    if (!is_array($decoded)) {
        return ['ok' => false, 'type' => '', 'error' => 'invalid_command'];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type !== VIDEOCHAT_GOSSIPMESH_TELEMETRY_SNAPSHOT_TYPE) {
        return ['ok' => false, 'type' => $type, 'error' => 'unsupported_type'];
    }

    $lane = strtolower(trim((string) ($decoded['lane'] ?? '')));
    if ($lane !== 'ops') {
        return ['ok' => false, 'type' => $type, 'error' => 'invalid_lane'];
    }

    $forbiddenFields = videochat_gossipmesh_telemetry_forbidden_fields();
    $wrapperProbe = $decoded;
    unset($wrapperProbe['payload']);
    if (videochat_gossipmesh_payload_contains_forbidden_field($wrapperProbe, $forbiddenFields)) {
        return ['ok' => false, 'type' => $type, 'error' => 'forbidden_media_or_signaling_field'];
    }

    $payload = $decoded['payload'] ?? null;
    if (!is_array($payload)) {
        return ['ok' => false, 'type' => $type, 'error' => 'invalid_payload'];
    }
    if (videochat_gossipmesh_payload_contains_forbidden_field($payload, $forbiddenFields)) {
        return ['ok' => false, 'type' => $type, 'error' => 'forbidden_media_or_signaling_field'];
    }

    $kind = strtolower(trim((string) ($payload['kind'] ?? '')));
    if ($kind !== '' && $kind !== 'gossip_telemetry_snapshot') {
        return ['ok' => false, 'type' => $type, 'error' => 'invalid_payload_kind'];
    }

    $roomId = videochat_gossipmesh_safe_id($payload['room_id'] ?? '');
    $callId = videochat_gossipmesh_safe_id($payload['call_id'] ?? '');
    $peerId = videochat_gossipmesh_safe_id($payload['peer_id'] ?? '');
    if ($roomId === '' || $callId === '' || $peerId === '') {
        return ['ok' => false, 'type' => $type, 'error' => 'missing_context'];
    }

    $transportKind = videochat_gossipmesh_safe_label(
        $payload['transport_kind'] ?? ($payload['transport'] ?? 'unknown'),
        videochat_gossipmesh_telemetry_transport_labels(),
        'unknown'
    );
    $dataLaneMode = videochat_gossipmesh_safe_label(
        $payload['data_lane_mode'] ?? '',
        ['off', 'shadow', 'active'],
        'off'
    );
    $rolloutStrategy = videochat_gossipmesh_safe_label(
        $payload['rollout_strategy'] ?? '',
        ['sfu_first_explicit'],
        'sfu_first_explicit'
    );

    return [
        'ok' => true,
        'type' => $type,
        'lane' => 'ops',
        'room_id' => $roomId,
        'call_id' => $callId,
        'peer_id' => $peerId,
        'transport_kind' => $transportKind,
        'data_lane_mode' => $dataLaneMode,
        'rollout_strategy' => $rolloutStrategy,
        'neighbor_count' => videochat_gossipmesh_clamp_int($payload['neighbor_count'] ?? 0, 0, 0, VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS),
        'topology_epoch' => videochat_gossipmesh_clamp_int($payload['topology_epoch'] ?? 0, 0, 0, PHP_INT_MAX),
        'counters' => videochat_gossipmesh_sanitize_telemetry_counters($payload['counters'] ?? []),
        'error' => '',
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_gossipmesh_aggregate_telemetry_snapshot(array &$presenceState, array $snapshot, ?int $nowMs = null): array
{
    $roomId = (string) ($snapshot['room_id'] ?? '');
    $callId = (string) ($snapshot['call_id'] ?? '');
    $peerId = (string) ($snapshot['peer_id'] ?? '');
    if ($roomId === '' || $callId === '' || $peerId === '') {
        return ['ok' => false, 'error' => 'missing_context'];
    }

    $nowMs = $nowMs ?? (int) floor(microtime(true) * 1000);
    $roomAggregate = is_array($presenceState['gossipmesh_telemetry'][$roomId] ?? null)
        ? $presenceState['gossipmesh_telemetry'][$roomId]
        : [
            'room_id' => $roomId,
            'call_id' => $callId,
            'updated_at_ms' => 0,
            'peer_count' => 0,
            'transports' => [],
            'totals' => videochat_gossipmesh_sanitize_telemetry_counters([]),
            'peers' => [],
        ];

    $roomAggregate['room_id'] = $roomId;
    $roomAggregate['call_id'] = $callId;
    $roomAggregate['updated_at_ms'] = $nowMs;
    $roomAggregate['peers'][$peerId] = [
        'peer_id' => $peerId,
        'transport_kind' => (string) ($snapshot['transport_kind'] ?? 'unknown'),
        'data_lane_mode' => (string) ($snapshot['data_lane_mode'] ?? 'off'),
        'rollout_strategy' => (string) ($snapshot['rollout_strategy'] ?? 'sfu_first_explicit'),
        'neighbor_count' => (int) ($snapshot['neighbor_count'] ?? 0),
        'topology_epoch' => (int) ($snapshot['topology_epoch'] ?? 0),
        'counters' => videochat_gossipmesh_sanitize_telemetry_counters($snapshot['counters'] ?? []),
        'updated_at_ms' => $nowMs,
    ];

    $totals = videochat_gossipmesh_sanitize_telemetry_counters([]);
    $transports = [];
    foreach ($roomAggregate['peers'] as $peerSnapshot) {
        if (!is_array($peerSnapshot)) {
            continue;
        }
        $transportKind = videochat_gossipmesh_safe_label(
            $peerSnapshot['transport_kind'] ?? 'unknown',
            videochat_gossipmesh_telemetry_transport_labels(),
            'unknown'
        );
        $transports[$transportKind] = ($transports[$transportKind] ?? 0) + 1;
        $peerCounters = videochat_gossipmesh_sanitize_telemetry_counters($peerSnapshot['counters'] ?? []);
        foreach ($totals as $counterName => $counterValue) {
            $totals[$counterName] = min(
                VIDEOCHAT_GOSSIPMESH_TELEMETRY_MAX_COUNTER_VALUE,
                $counterValue + (int) ($peerCounters[$counterName] ?? 0)
            );
        }
    }

    ksort($transports);
    $roomAggregate['totals'] = $totals;
    $roomAggregate['transports'] = $transports;
    $roomAggregate['peer_count'] = count($roomAggregate['peers']);
    $roomAggregate['rollout_gate'] = videochat_gossipmesh_derive_telemetry_rollout_gate($roomAggregate);
    $presenceState['gossipmesh_telemetry'][$roomId] = $roomAggregate;

    return [
        'ok' => true,
        'error' => '',
        'room_id' => $roomId,
        'call_id' => $callId,
        'peer_count' => (int) $roomAggregate['peer_count'],
        'transports' => $transports,
        'totals' => $totals,
        'updated_at_ms' => $nowMs,
        'rollout_gate' => $roomAggregate['rollout_gate'],
    ];
}

function videochat_gossipmesh_derive_telemetry_rollout_gate(array $roomAggregate): array
{
    $totals = videochat_gossipmesh_sanitize_telemetry_counters($roomAggregate['totals'] ?? []);
    $peers = is_array($roomAggregate['peers'] ?? null) ? $roomAggregate['peers'] : [];
    $transports = is_array($roomAggregate['transports'] ?? null) ? $roomAggregate['transports'] : [];
    $peerCount = max(0, (int) ($roomAggregate['peer_count'] ?? count($peers)));
    $rtcPeerCount = max(0, (int) ($transports['rtc_datachannel'] ?? 0));
    $minNeighborCount = 0;
    $maxTopologyEpoch = 0;

    foreach ($peers as $peerSnapshot) {
        if (!is_array($peerSnapshot)) {
            continue;
        }
        $neighborCount = max(0, (int) ($peerSnapshot['neighbor_count'] ?? 0));
        $minNeighborCount = $minNeighborCount === 0 ? $neighborCount : min($minNeighborCount, $neighborCount);
        $maxTopologyEpoch = max($maxTopologyEpoch, max(0, (int) ($peerSnapshot['topology_epoch'] ?? 0)));
    }

    $duplicateRate = videochat_gossipmesh_ratio(
        (int) ($totals['duplicates'] ?? 0),
        max(1, (int) ($totals['received'] ?? 0) + (int) ($totals['duplicates'] ?? 0))
    );
    $ttlExhaustionRate = videochat_gossipmesh_ratio(
        (int) ($totals['ttl_exhausted'] ?? 0),
        max(1, (int) ($totals['forwarded'] ?? 0) + (int) ($totals['ttl_exhausted'] ?? 0))
    );
    $lateDropRate = videochat_gossipmesh_ratio(
        (int) ($totals['late_drops'] ?? 0),
        max(1, (int) ($totals['sent'] ?? 0) + (int) ($totals['received'] ?? 0))
    );
    $repairRate = videochat_gossipmesh_ratio(
        (int) ($totals['topology_repairs_requested'] ?? 0),
        max(1, $peerCount)
    );
    $rtcReady = $peerCount >= VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT
        && $rtcPeerCount >= $peerCount
        && $minNeighborCount >= VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT
        && $maxTopologyEpoch > 0;
    $telemetryReady = ((int) ($totals['sent'] ?? 0) + (int) ($totals['received'] ?? 0)) > 0
        && $duplicateRate <= 0.02
        && $ttlExhaustionRate <= 0.01
        && $lateDropRate <= 0.01
        && $repairRate <= 0.05;

    return [
        'kind' => 'gossip_rollout_gate_state',
        'decision' => ($rtcReady && $telemetryReady) ? 'active_allowed_diagnostic' : 'sfu_first_explicit',
        'active_allowed' => $rtcReady && $telemetryReady,
        'observational_only' => true,
        'sfu_first' => !($rtcReady && $telemetryReady),
        'rtc_ready' => $rtcReady,
        'telemetry_ready' => $telemetryReady,
        'peer_count' => $peerCount,
        'rtc_peer_count' => $rtcPeerCount,
        'min_neighbor_count' => $minNeighborCount,
        'max_topology_epoch' => $maxTopologyEpoch,
        'duplicate_rate' => $duplicateRate,
        'ttl_exhaustion_rate' => $ttlExhaustionRate,
        'late_drop_rate' => $lateDropRate,
        'repair_rate' => $repairRate,
    ];
}

function videochat_gossipmesh_ratio(int $numerator, int $denominator): float
{
    return max(0.0, min(1.0, $numerator / max(1, $denominator)));
}

/**
 * @return array{id: string, user_id: string, display_name: string, relay_score: int, activity_score: int}|null
 */
function videochat_gossipmesh_normalize_member(array $member): ?array
{
    foreach (videochat_gossipmesh_forbidden_payload_fields() as $field) {
        if (array_key_exists($field, $member)) {
            return null;
        }
    }

    if (!videochat_gossipmesh_is_admitted_member($member)) {
        return null;
    }

    $id = videochat_gossipmesh_safe_id(
        $member['participant_id'] ?? $member['publisher_id'] ?? $member['peer_id'] ?? $member['user_id'] ?? ''
    );
    if ($id === '') {
        return null;
    }

    $displayName = trim((string) ($member['display_name'] ?? $member['name'] ?? $id));
    $displayName = function_exists('mb_substr') ? mb_substr($displayName, 0, 160) : substr($displayName, 0, 160);

    return [
        'id' => $id,
        'user_id' => videochat_gossipmesh_safe_id($member['user_id'] ?? $id),
        'display_name' => $displayName,
        'relay_score' => videochat_gossipmesh_clamp_int($member['relay_score'] ?? $member['bandwidth_score'] ?? 0, 0, 0, 100),
        'activity_score' => videochat_gossipmesh_clamp_int($member['activity_score'] ?? 0, 0, 0, 100),
    ];
}

function videochat_gossipmesh_member_from_room_participant(array $participant): array
{
    $user = is_array($participant['user'] ?? null) ? $participant['user'] : [];
    $userId = (int) ($user['id'] ?? ($participant['user_id'] ?? 0));
    $callRole = strtolower(trim((string) ($user['call_role'] ?? ($participant['call_role'] ?? 'participant'))));
    $inviteState = strtolower(trim((string) ($participant['invite_state'] ?? $user['invite_state'] ?? $participant['state'] ?? $user['state'] ?? 'allowed')));
    if ($inviteState === '') {
        $inviteState = 'allowed';
    }
    $relayScore = match ($callRole) {
        'owner' => 100,
        'moderator' => 85,
        default => 50,
    };

    return [
        'participant_id' => (string) $userId,
        'user_id' => (string) $userId,
        'display_name' => (string) ($user['display_name'] ?? ($participant['display_name'] ?? ('User ' . $userId))),
        'invite_state' => $inviteState,
        'left_at' => trim((string) ($participant['left_at'] ?? $user['left_at'] ?? '')),
        'removed_at' => trim((string) ($participant['removed_at'] ?? $user['removed_at'] ?? '')),
        'relay_score' => $relayScore,
        'activity_score' => 0,
    ];
}

/**
 * @param array<int, array<string, mixed>> $participants
 * @return array<int, array<string, mixed>>
 */
function videochat_gossipmesh_members_from_room_participants(array $participants): array
{
    $members = [];
    foreach ($participants as $participant) {
        if (is_array($participant)) {
            $members[] = videochat_gossipmesh_member_from_room_participant($participant);
        }
    }

    return $members;
}

function videochat_gossipmesh_estimate_ttl(int $memberCount): int
{
    if ($memberCount <= 1) {
        return 0;
    }
    if ($memberCount <= 10) {
        return 3;
    }
    if ($memberCount <= 50) {
        return 4;
    }
    if ($memberCount <= 100) {
        return 5;
    }
    if ($memberCount <= 500) {
        return 6;
    }

    return 7;
}

/**
 * @param array<int, array<string, mixed>> $members
 * @return array{
 *   contract: string,
 *   authority: string,
 *   runtime_path: string,
 *   envelope_contract: string,
 *   call_id: string,
 *   room_id: string,
 *   ttl: int,
 *   forward_count: int,
 *   members: array<int, array{id: string, user_id: string, display_name: string, relay_score: int, activity_score: int}>,
 *   topology: array<string, array<int, string>>,
 *   relay_candidates: array<int, string>,
 *   rejected_members: int
 * }
 */
function videochat_gossipmesh_plan_topology(string $callId, string $roomId, array $members, array $options = []): array
{
    $callId = videochat_gossipmesh_safe_id($callId);
    $roomId = videochat_gossipmesh_safe_id($roomId);
    if ($callId === '' || $roomId === '') {
        throw new InvalidArgumentException('call_id and room_id are required for GossipMesh topology planning');
    }

    $avoidPairs = [];
    $excludedPeerIds = [];
    if (is_array($options['avoid_pairs'] ?? null)) {
        foreach ($options['avoid_pairs'] as $key => $value) {
            if (is_string($key) && $value === true) {
                if (preg_match('/^[A-Za-z0-9._:-]+\|[A-Za-z0-9._:-]+$/', $key) === 1) {
                    $avoidPairs[$key] = true;
                    continue;
                }
                if (preg_match('/^([A-Za-z0-9._:-]+)\|\*$/', $key, $matches) === 1) {
                    $peerId = videochat_gossipmesh_safe_id($matches[1] ?? '');
                    if ($peerId !== '') {
                        $avoidPairs[videochat_gossipmesh_excluded_peer_pair_key($peerId)] = true;
                        $excludedPeerIds[$peerId] = true;
                    }
                    continue;
                }
            }
            if (is_array($value)) {
                $pairKey = videochat_gossipmesh_link_pair_key(
                    (string) ($value['peer_id'] ?? ''),
                    (string) ($value['lost_peer_id'] ?? ($value['neighbor_id'] ?? ''))
                );
                if ($pairKey !== '') {
                    $avoidPairs[$pairKey] = true;
                }
            }
        }
    }

    $normalizedById = [];
    $rejected = 0;
    foreach ($members as $member) {
        if (!is_array($member)) {
            $rejected++;
            continue;
        }
        $normalized = videochat_gossipmesh_normalize_member($member);
        if ($normalized === null) {
            $rejected++;
            continue;
        }
        if (($excludedPeerIds[$normalized['id']] ?? false) === true) {
            $rejected++;
            continue;
        }
        $normalizedById[$normalized['id']] = $normalized;
    }

    $normalizedMembers = array_values($normalizedById);
    $seed = (string) ($options['seed'] ?? 'default');
    usort($normalizedMembers, static function (array $left, array $right) use ($callId, $roomId, $seed): int {
        $leftHash = hash('sha256', $callId . '|' . $roomId . '|' . $seed . '|' . $left['id']);
        $rightHash = hash('sha256', $callId . '|' . $roomId . '|' . $seed . '|' . $right['id']);
        return $leftHash <=> $rightHash;
    });

    $memberCount = count($normalizedMembers);
    $neighborLimit = videochat_gossipmesh_clamp_int(
        $options['max_neighbors'] ?? VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS,
        VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS,
        VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT,
        VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS
    );
    $forwardCount = videochat_gossipmesh_clamp_int(
        $options['forward_count'] ?? VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT,
        VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT,
        VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT,
        VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS
    );
    $topology = [];
    if ($memberCount > 1) {
        $perMemberNeighbors = min($neighborLimit, $memberCount - 1);
        foreach ($normalizedMembers as $index => $member) {
            $neighbors = [];
            for ($offset = 1; $offset < $memberCount && count($neighbors) < $perMemberNeighbors; $offset++) {
                $neighborId = $normalizedMembers[($index + $offset) % $memberCount]['id'];
                if (!videochat_gossipmesh_should_avoid_pair($member['id'], $neighborId, $avoidPairs)) {
                    $neighbors[] = $neighborId;
                }
            }
            $topology[$member['id']] = $neighbors;
        }
    } else {
        foreach ($normalizedMembers as $member) {
            $topology[$member['id']] = [];
        }
    }

    $relayMembers = $normalizedMembers;
    usort($relayMembers, static function (array $left, array $right): int {
        $score = $right['relay_score'] <=> $left['relay_score'];
        return $score !== 0 ? $score : ($left['id'] <=> $right['id']);
    });
    $relayCandidates = array_slice(
        array_values(array_map(static fn(array $member): string => $member['id'], $relayMembers)),
        0,
        VIDEOCHAT_GOSSIPMESH_MAX_RELAY_CANDIDATES
    );

    return [
        'contract' => VIDEOCHAT_GOSSIPMESH_CONTRACT,
        'authority' => 'server',
        'runtime_path' => VIDEOCHAT_GOSSIPMESH_RUNTIME_PATH,
        'envelope_contract' => VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT,
        'call_id' => $callId,
        'room_id' => $roomId,
        'ttl' => videochat_gossipmesh_estimate_ttl($memberCount),
        'forward_count' => min($forwardCount, max(VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT, $neighborLimit)),
        'members' => $normalizedMembers,
        'topology' => $topology,
        'relay_candidates' => $relayCandidates,
        'rejected_members' => $rejected,
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_gossipmesh_topology_hint_payload(array $topologyPlan, string $peerId, string $reason = '', ?int $epochMs = null): array
{
    $safePeerId = videochat_gossipmesh_safe_id($peerId);
    if ($safePeerId === '') {
        throw new InvalidArgumentException('peer_id is required for GossipMesh topology hints');
    }

    $neighbors = [];
    $neighborIds = is_array($topologyPlan['topology'][$safePeerId] ?? null) ? $topologyPlan['topology'][$safePeerId] : [];
    $priority = 1;
    foreach ($neighborIds as $neighborId) {
        $safeNeighborId = videochat_gossipmesh_safe_id($neighborId);
        if ($safeNeighborId === '' || $safeNeighborId === $safePeerId) {
            continue;
        }
        $neighbors[] = [
            'peer_id' => $safeNeighborId,
            'transport' => 'rtc_datachannel',
            'codec' => VIDEOCHAT_GOSSIPMESH_DATA_CODEC,
            'envelope_contract' => VIDEOCHAT_GOSSIPMESH_DATA_ENVELOPE_CONTRACT,
            'priority' => $priority,
        ];
        $priority++;
        if (count($neighbors) >= VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS) {
            break;
        }
    }

    return [
        'lane' => 'ops',
        'type' => VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HINT_TYPE,
        'kind' => VIDEOCHAT_GOSSIPMESH_TOPOLOGY_HINT_TYPE,
        'contract' => VIDEOCHAT_GOSSIPMESH_CONTRACT,
        'room_id' => (string) ($topologyPlan['room_id'] ?? ''),
        'call_id' => (string) ($topologyPlan['call_id'] ?? ''),
        'peer_id' => $safePeerId,
        'topology_epoch' => $epochMs ?? (int) floor(microtime(true) * 1000),
        'neighbors' => $neighbors,
        'reconnect_reason' => trim($reason) === '' ? 'topology_repair' : trim($reason),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_gossipmesh_call_topology_payload(array $topologyPlan, string $peerId, string $reason = '', ?int $epochMs = null): array
{
    $hint = videochat_gossipmesh_topology_hint_payload($topologyPlan, $peerId, $reason, $epochMs);

    return [
        'type' => VIDEOCHAT_GOSSIPMESH_CALL_TOPOLOGY_TYPE,
        'room_id' => (string) ($topologyPlan['room_id'] ?? ''),
        'call_id' => (string) ($topologyPlan['call_id'] ?? ''),
        'payload' => $hint,
        'time' => gmdate('c'),
    ];
}

/**
 * @param array<int, string> $seenWindow
 * @return array{accepted: bool, duplicate: bool, seen_window: array<int, string>}
 */
function videochat_gossipmesh_accept_frame_once(array $seenWindow, string $publisherId, int $sequence, int $maxSize = VIDEOCHAT_GOSSIPMESH_SEEN_WINDOW_SIZE): array
{
    $publisherId = videochat_gossipmesh_safe_id($publisherId);
    $maxSize = videochat_gossipmesh_clamp_int($maxSize, VIDEOCHAT_GOSSIPMESH_SEEN_WINDOW_SIZE, 1, 4096);
    if ($publisherId === '' || $sequence < 0) {
        return ['accepted' => false, 'duplicate' => false, 'seen_window' => array_values($seenWindow)];
    }

    $key = $publisherId . ':' . $sequence;
    $window = array_values(array_filter($seenWindow, static fn(mixed $value): bool => is_string($value) && $value !== ''));
    if (in_array($key, $window, true)) {
        return ['accepted' => false, 'duplicate' => true, 'seen_window' => $window];
    }

    $window[] = $key;
    if (count($window) > $maxSize) {
        $window = array_slice($window, -$maxSize);
    }

    return ['accepted' => true, 'duplicate' => false, 'seen_window' => $window];
}

/**
 * @param array<int, string> $neighbors
 * @return array<int, string>
 */
function videochat_gossipmesh_select_forward_targets(array $neighbors, string $publisherId, int $sequence, int $ttl, int $forwardCount = VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT): array
{
    if ($ttl <= 0) {
        return [];
    }

    $publisherId = videochat_gossipmesh_safe_id($publisherId);
    if ($publisherId === '') {
        return [];
    }

    $forwardCount = videochat_gossipmesh_clamp_int(
        $forwardCount,
        VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT,
        VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT,
        VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS
    );
    $safeNeighbors = [];
    foreach ($neighbors as $neighbor) {
        $neighborId = videochat_gossipmesh_safe_id($neighbor);
        if ($neighborId !== '' && $neighborId !== $publisherId) {
            $safeNeighbors[$neighborId] = $neighborId;
        }
    }

    $safeNeighbors = array_values($safeNeighbors);
    usort($safeNeighbors, static function (string $left, string $right) use ($publisherId, $sequence): int {
        $leftHash = hash('sha256', $publisherId . '|' . $sequence . '|' . $left);
        $rightHash = hash('sha256', $publisherId . '|' . $sequence . '|' . $right);
        return $leftHash <=> $rightHash;
    });

    return array_slice($safeNeighbors, 0, min($forwardCount, count($safeNeighbors)));
}

/**
 * @return array{ok: bool, protected_frame: string, error: string}
 */
function videochat_gossipmesh_validate_transport_envelope(array $envelope): array
{
    foreach (videochat_gossipmesh_forbidden_payload_fields() as $field) {
        if (array_key_exists($field, $envelope)) {
            return ['ok' => false, 'protected_frame' => '', 'error' => 'forbidden_plaintext_or_secret_field'];
        }
    }

    if (array_key_exists('data', $envelope)) {
        return ['ok' => false, 'protected_frame' => '', 'error' => 'legacy_plaintext_data_forbidden'];
    }

    $contract = (string) ($envelope['envelope_contract'] ?? $envelope['contract'] ?? '');
    if ($contract !== VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT) {
        return ['ok' => false, 'protected_frame' => '', 'error' => 'missing_protected_envelope_contract'];
    }

    $protectedFrame = trim((string) ($envelope['protected_frame'] ?? ''));
    if ($protectedFrame === '') {
        return ['ok' => false, 'protected_frame' => '', 'error' => 'missing_protected_frame'];
    }
    if (strlen($protectedFrame) > VIDEOCHAT_GOSSIPMESH_MAX_PROTECTED_FRAME_BYTES) {
        return ['ok' => false, 'protected_frame' => '', 'error' => 'protected_frame_too_large'];
    }
    if (preg_match('/^[A-Za-z0-9_-]+$/', $protectedFrame) !== 1) {
        return ['ok' => false, 'protected_frame' => '', 'error' => 'malformed_protected_frame'];
    }

    return ['ok' => true, 'protected_frame' => $protectedFrame, 'error' => ''];
}

/**
 * @param array{
 *   topology: array<string, array<int, string>>,
 *   relay_candidates: array<int, string>,
 *   forward_count: int
 * } $topologyPlan
 * @param array<string, bool> $failedPeers
 * @return array{
 *   ok: bool,
 *   error: string,
 *   duplicate: bool,
 *   ttl: int,
 *   next_ttl: int,
 *   direct_targets: array<int, string>,
 *   relay_targets: array<int, array{target_id: string, relay_id: string}>,
 *   seen_window: array<int, string>
 * }
 */
function videochat_gossipmesh_plan_message_route(
    array $topologyPlan,
    array $seenWindow,
    string $publisherId,
    int $sequence,
    int $ttl,
    array $transportEnvelope,
    array $failedPeers = []
): array {
    $baseFailure = [
        'ok' => false,
        'error' => '',
        'duplicate' => false,
        'ttl' => $ttl,
        'next_ttl' => max(0, $ttl - 1),
        'direct_targets' => [],
        'relay_targets' => [],
        'seen_window' => array_values($seenWindow),
    ];

    $publisherId = videochat_gossipmesh_safe_id($publisherId);
    if ($publisherId === '') {
        return [...$baseFailure, 'error' => 'invalid_publisher'];
    }
    if ($sequence < 0) {
        return [...$baseFailure, 'error' => 'invalid_sequence'];
    }

    $envelope = videochat_gossipmesh_validate_transport_envelope($transportEnvelope);
    if (!$envelope['ok']) {
        return [...$baseFailure, 'error' => $envelope['error']];
    }

    $accepted = videochat_gossipmesh_accept_frame_once($seenWindow, $publisherId, $sequence);
    if (!$accepted['accepted']) {
        return [
            ...$baseFailure,
            'ok' => false,
            'error' => $accepted['duplicate'] ? 'duplicate_frame' : 'frame_rejected',
            'duplicate' => $accepted['duplicate'],
            'seen_window' => $accepted['seen_window'],
        ];
    }

    if ($ttl <= 0) {
        return [
            ...$baseFailure,
            'ok' => true,
            'error' => '',
            'seen_window' => $accepted['seen_window'],
        ];
    }

    $neighbors = $topologyPlan['topology'][$publisherId] ?? null;
    if (!is_array($neighbors)) {
        return [
            ...$baseFailure,
            'error' => 'publisher_not_in_topology',
            'seen_window' => $accepted['seen_window'],
        ];
    }

    $forwardCount = videochat_gossipmesh_clamp_int(
        $topologyPlan['forward_count'] ?? VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT,
        VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT,
        VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT,
        VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS
    );
    $targets = videochat_gossipmesh_select_forward_targets($neighbors, $publisherId, $sequence, $ttl, $forwardCount);
    $relayCandidates = array_values(array_filter(
        $topologyPlan['relay_candidates'] ?? [],
        static fn(mixed $value): bool => is_string($value) && videochat_gossipmesh_safe_id($value) !== ''
    ));
    $directTargets = [];
    $relayTargets = [];

    foreach ($targets as $targetId) {
        if (($failedPeers[$targetId] ?? false) !== true) {
            $directTargets[] = $targetId;
            continue;
        }

        $relayId = '';
        foreach ($relayCandidates as $candidateId) {
            if ($candidateId !== $targetId && $candidateId !== $publisherId && ($failedPeers[$candidateId] ?? false) !== true) {
                $relayId = $candidateId;
                break;
            }
        }
        if ($relayId === '') {
            return [
                ...$baseFailure,
                'error' => 'relay_unavailable',
                'seen_window' => $accepted['seen_window'],
            ];
        }
        $relayTargets[] = ['target_id' => $targetId, 'relay_id' => $relayId];
    }

    return [
        'ok' => true,
        'error' => '',
        'duplicate' => false,
        'ttl' => $ttl,
        'next_ttl' => $ttl - 1,
        'direct_targets' => $directTargets,
        'relay_targets' => $relayTargets,
        'seen_window' => $accepted['seen_window'],
    ];
}
