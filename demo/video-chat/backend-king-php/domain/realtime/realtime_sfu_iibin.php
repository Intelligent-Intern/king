<?php

declare(strict_types=1);

const VIDEOCHAT_SFU_IIBIN_FRAME_MAGIC = 'KSCI';
const VIDEOCHAT_SFU_IIBIN_FRAME_VERSION = 1;
const VIDEOCHAT_SFU_IIBIN_KIND_ENUM = 'VideoChatSfuIibinControlKindV1';
const VIDEOCHAT_SFU_IIBIN_CONTROL_SCHEMA = 'VideoChatSfuControlMetadataV1';

const VIDEOCHAT_SFU_IIBIN_KIND_ROOM_BINDING = 1;
const VIDEOCHAT_SFU_IIBIN_KIND_PUBLISHER_JOINED = 2;
const VIDEOCHAT_SFU_IIBIN_KIND_PUBLISHER_LEFT = 3;
const VIDEOCHAT_SFU_IIBIN_KIND_TRACK_PUBLISHED = 4;
const VIDEOCHAT_SFU_IIBIN_KIND_TRACK_UNPUBLISHED = 5;
const VIDEOCHAT_SFU_IIBIN_KIND_DIAGNOSTIC = 6;
const VIDEOCHAT_SFU_IIBIN_KIND_MEDIA_METADATA = 7;

/**
 * Native King IIBIN boundary for SFU control/metadata.
 *
 * The binary media payload itself stays in the KSFB media envelope. This schema
 * owns typed metadata around that path: bound room/call identity, publisher and
 * track lifecycle, transport diagnostics, queue pressure, and media-envelope
 * metadata. It intentionally does not route through a browser/package shim.
 */
function videochat_sfu_iibin_available(): bool
{
    return function_exists('king_proto_define_schema')
        && function_exists('king_proto_define_enum')
        && function_exists('king_proto_is_schema_defined')
        && function_exists('king_proto_is_enum_defined')
        && function_exists('king_proto_encode')
        && function_exists('king_proto_decode');
}

function videochat_sfu_iibin_register_schemas(): bool
{
    static $registered = false;
    if ($registered) {
        return true;
    }
    if (!videochat_sfu_iibin_available()) {
        return false;
    }

    if (!king_proto_is_enum_defined(VIDEOCHAT_SFU_IIBIN_KIND_ENUM)) {
        king_proto_define_enum(VIDEOCHAT_SFU_IIBIN_KIND_ENUM, [
            'ROOM_BINDING' => VIDEOCHAT_SFU_IIBIN_KIND_ROOM_BINDING,
            'PUBLISHER_JOINED' => VIDEOCHAT_SFU_IIBIN_KIND_PUBLISHER_JOINED,
            'PUBLISHER_LEFT' => VIDEOCHAT_SFU_IIBIN_KIND_PUBLISHER_LEFT,
            'TRACK_PUBLISHED' => VIDEOCHAT_SFU_IIBIN_KIND_TRACK_PUBLISHED,
            'TRACK_UNPUBLISHED' => VIDEOCHAT_SFU_IIBIN_KIND_TRACK_UNPUBLISHED,
            'DIAGNOSTIC' => VIDEOCHAT_SFU_IIBIN_KIND_DIAGNOSTIC,
            'MEDIA_METADATA' => VIDEOCHAT_SFU_IIBIN_KIND_MEDIA_METADATA,
        ]);
    }

    if (king_proto_is_schema_defined(VIDEOCHAT_SFU_IIBIN_CONTROL_SCHEMA)) {
        $registered = true;
        return true;
    }

    king_proto_define_schema(VIDEOCHAT_SFU_IIBIN_CONTROL_SCHEMA, [
        'envelope_version' => ['tag' => 1, 'type' => 'int32', 'required' => true],
        'kind' => ['tag' => 2, 'type' => VIDEOCHAT_SFU_IIBIN_KIND_ENUM, 'required' => true],
        'room_id' => ['tag' => 3, 'type' => 'string', 'required' => true],
        'call_id' => ['tag' => 4, 'type' => 'string'],
        'client_id' => ['tag' => 5, 'type' => 'string'],
        'publisher_id' => ['tag' => 6, 'type' => 'string'],
        'publisher_user_id' => ['tag' => 7, 'type' => 'string'],
        'publisher_name' => ['tag' => 8, 'type' => 'string'],
        'track_id' => ['tag' => 9, 'type' => 'string'],
        'track_kind' => ['tag' => 10, 'type' => 'string'],
        'track_label' => ['tag' => 11, 'type' => 'string'],
        'diagnostic_code' => ['tag' => 12, 'type' => 'string'],
        'diagnostic_level' => ['tag' => 13, 'type' => 'string'],
        'diagnostic_message' => ['tag' => 14, 'type' => 'string'],
        'transport_path' => ['tag' => 15, 'type' => 'string'],
        'payload_bytes' => ['tag' => 16, 'type' => 'uint64'],
        'wire_payload_bytes' => ['tag' => 17, 'type' => 'uint64'],
        'queue_pressure_bytes' => ['tag' => 18, 'type' => 'uint64'],
        'binary_envelope_version' => ['tag' => 19, 'type' => 'int32'],
        'binary_continuation_state' => ['tag' => 20, 'type' => 'string'],
        'binary_continuation_required' => ['tag' => 21, 'type' => 'bool'],
        'frame_sequence' => ['tag' => 22, 'type' => 'uint64'],
        'frame_type' => ['tag' => 23, 'type' => 'string'],
        'codec_id' => ['tag' => 24, 'type' => 'string'],
        'runtime_id' => ['tag' => 25, 'type' => 'string'],
        'protection_mode' => ['tag' => 26, 'type' => 'string'],
        'layout_mode' => ['tag' => 27, 'type' => 'string'],
        'layer_id' => ['tag' => 28, 'type' => 'string'],
        'cache_epoch' => ['tag' => 29, 'type' => 'uint64'],
        'tile_count' => ['tag' => 30, 'type' => 'int32'],
        'selection_tile_count' => ['tag' => 31, 'type' => 'int32'],
        'selection_total_tile_count' => ['tag' => 32, 'type' => 'int32'],
        'selection_tile_ratio' => ['tag' => 33, 'type' => 'double'],
        'selection_mask_guided' => ['tag' => 34, 'type' => 'bool'],
        'roi_area_ratio' => ['tag' => 35, 'type' => 'double'],
    ]);

    $registered = true;
    return true;
}

function videochat_sfu_iibin_control_frame_has_magic(string $frame): bool
{
    return strncmp($frame, VIDEOCHAT_SFU_IIBIN_FRAME_MAGIC, strlen(VIDEOCHAT_SFU_IIBIN_FRAME_MAGIC)) === 0;
}

/**
 * @param array<string, mixed> $payload
 * @return string|false
 */
function videochat_sfu_iibin_encode_control_frame(array $payload): string|false
{
    if (!videochat_sfu_iibin_register_schemas()) {
        return false;
    }

    $normalized = videochat_sfu_iibin_normalize_control_payload($payload);
    if ($normalized === []) {
        return false;
    }

    $encoded = king_proto_encode(VIDEOCHAT_SFU_IIBIN_CONTROL_SCHEMA, $normalized);
    if (!is_string($encoded) || $encoded === '') {
        return false;
    }

    return VIDEOCHAT_SFU_IIBIN_FRAME_MAGIC . chr(VIDEOCHAT_SFU_IIBIN_FRAME_VERSION) . $encoded;
}

/**
 * @return array{ok: bool, payload: array<string, mixed>, error: string}
 */
function videochat_sfu_iibin_decode_control_frame(string $frame, string $boundRoomId): array
{
    if (!videochat_sfu_iibin_control_frame_has_magic($frame)) {
        return ['ok' => false, 'payload' => [], 'error' => 'invalid_iibin_control_magic'];
    }
    if (strlen($frame) <= strlen(VIDEOCHAT_SFU_IIBIN_FRAME_MAGIC)) {
        return ['ok' => false, 'payload' => [], 'error' => 'truncated_iibin_control_frame'];
    }

    $versionOffset = strlen(VIDEOCHAT_SFU_IIBIN_FRAME_MAGIC);
    $frameVersion = ord($frame[$versionOffset]);
    if ($frameVersion !== VIDEOCHAT_SFU_IIBIN_FRAME_VERSION) {
        return ['ok' => false, 'payload' => [], 'error' => 'unsupported_iibin_control_version'];
    }
    if (!videochat_sfu_iibin_register_schemas()) {
        return ['ok' => false, 'payload' => [], 'error' => 'iibin_unavailable'];
    }

    try {
        $decoded = king_proto_decode(VIDEOCHAT_SFU_IIBIN_CONTROL_SCHEMA, substr($frame, $versionOffset + 1));
    } catch (Throwable) {
        return ['ok' => false, 'payload' => [], 'error' => 'invalid_iibin_control_payload'];
    }
    if (!is_array($decoded)) {
        return ['ok' => false, 'payload' => [], 'error' => 'invalid_iibin_control_payload'];
    }

    return videochat_sfu_iibin_control_payload_to_command($decoded, $boundRoomId);
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function videochat_sfu_iibin_normalize_control_payload(array $payload): array
{
    $roomId = trim((string) ($payload['room_id'] ?? ($payload['roomId'] ?? '')));
    $kind = videochat_sfu_iibin_kind_name($payload['kind'] ?? ($payload['message_kind'] ?? ($payload['type'] ?? '')));
    if ($roomId === '' || $kind === '') {
        return [];
    }

    $normalized = [
        'envelope_version' => VIDEOCHAT_SFU_IIBIN_FRAME_VERSION,
        'kind' => $kind,
        'room_id' => $roomId,
    ];

    foreach (videochat_sfu_iibin_scalar_fields() as $field => $type) {
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $field))));
        $value = $payload[$field] ?? ($payload[$camel] ?? null);
        if ($value === null) {
            continue;
        }
        if ($type === 'string') {
            $normalized[$field] = trim((string) $value);
        } elseif ($type === 'bool') {
            $normalized[$field] = (bool) $value;
        } elseif ($type === 'float') {
            $normalized[$field] = (float) $value;
        } else {
            $normalized[$field] = max(0, (int) $value);
        }
    }

    return $normalized;
}

/**
 * @return array<string, string>
 */
function videochat_sfu_iibin_scalar_fields(): array
{
    return [
        'call_id' => 'string',
        'client_id' => 'string',
        'publisher_id' => 'string',
        'publisher_user_id' => 'string',
        'publisher_name' => 'string',
        'track_id' => 'string',
        'track_kind' => 'string',
        'track_label' => 'string',
        'diagnostic_code' => 'string',
        'diagnostic_level' => 'string',
        'diagnostic_message' => 'string',
        'transport_path' => 'string',
        'payload_bytes' => 'int',
        'wire_payload_bytes' => 'int',
        'queue_pressure_bytes' => 'int',
        'binary_envelope_version' => 'int',
        'binary_continuation_state' => 'string',
        'binary_continuation_required' => 'bool',
        'frame_sequence' => 'int',
        'frame_type' => 'string',
        'codec_id' => 'string',
        'runtime_id' => 'string',
        'protection_mode' => 'string',
        'layout_mode' => 'string',
        'layer_id' => 'string',
        'cache_epoch' => 'int',
        'tile_count' => 'int',
        'selection_tile_count' => 'int',
        'selection_total_tile_count' => 'int',
        'selection_tile_ratio' => 'float',
        'selection_mask_guided' => 'bool',
        'roi_area_ratio' => 'float',
    ];
}

function videochat_sfu_iibin_kind_name(mixed $kind): string
{
    $map = [
        VIDEOCHAT_SFU_IIBIN_KIND_ROOM_BINDING => 'ROOM_BINDING',
        VIDEOCHAT_SFU_IIBIN_KIND_PUBLISHER_JOINED => 'PUBLISHER_JOINED',
        VIDEOCHAT_SFU_IIBIN_KIND_PUBLISHER_LEFT => 'PUBLISHER_LEFT',
        VIDEOCHAT_SFU_IIBIN_KIND_TRACK_PUBLISHED => 'TRACK_PUBLISHED',
        VIDEOCHAT_SFU_IIBIN_KIND_TRACK_UNPUBLISHED => 'TRACK_UNPUBLISHED',
        VIDEOCHAT_SFU_IIBIN_KIND_DIAGNOSTIC => 'DIAGNOSTIC',
        VIDEOCHAT_SFU_IIBIN_KIND_MEDIA_METADATA => 'MEDIA_METADATA',
    ];
    if (is_int($kind) || ctype_digit((string) $kind)) {
        return $map[(int) $kind] ?? '';
    }

    $normalized = strtoupper(trim((string) $kind));
    $normalized = str_replace(['SFU/', 'SFU_', '-', ' '], ['', '', '_', '_'], $normalized);
    return in_array($normalized, $map, true) ? $normalized : '';
}

/**
 * @param array<string, mixed> $decoded
 * @return array{ok: bool, payload: array<string, mixed>, error: string}
 */
function videochat_sfu_iibin_control_payload_to_command(array $decoded, string $boundRoomId): array
{
    $roomId = trim((string) ($decoded['room_id'] ?? ''));
    $normalizedBoundRoomId = function_exists('videochat_presence_normalize_room_id')
        ? videochat_presence_normalize_room_id($boundRoomId, '')
        : trim($boundRoomId);
    if ($roomId === '' || $roomId !== $normalizedBoundRoomId) {
        return ['ok' => false, 'payload' => [], 'error' => 'sfu_room_mismatch'];
    }

    $kindName = videochat_sfu_iibin_kind_name($decoded['kind'] ?? 0);
    if ($kindName === 'ROOM_BINDING') {
        return ['ok' => true, 'payload' => ['type' => 'sfu/join', 'room_id' => $roomId], 'error' => ''];
    }
    if ($kindName === 'TRACK_PUBLISHED') {
        $trackId = trim((string) ($decoded['track_id'] ?? ''));
        if ($trackId === '') {
            return ['ok' => false, 'payload' => [], 'error' => 'invalid_iibin_track_id'];
        }
        return [
            'ok' => true,
            'payload' => [
                'type' => 'sfu/publish',
                'room_id' => $roomId,
                'track_id' => $trackId,
                'kind' => trim((string) ($decoded['track_kind'] ?? 'video')) ?: 'video',
                'label' => trim((string) ($decoded['track_label'] ?? '')),
            ],
            'error' => '',
        ];
    }
    if ($kindName === 'TRACK_UNPUBLISHED') {
        $trackId = trim((string) ($decoded['track_id'] ?? ''));
        if ($trackId === '') {
            return ['ok' => false, 'payload' => [], 'error' => 'invalid_iibin_track_id'];
        }
        return ['ok' => true, 'payload' => ['type' => 'sfu/unpublish', 'room_id' => $roomId, 'track_id' => $trackId], 'error' => ''];
    }
    if ($kindName === 'DIAGNOSTIC' || $kindName === 'MEDIA_METADATA') {
        return [
            'ok' => true,
            'payload' => [
                'type' => 'sfu/iibin-control',
                'room_id' => $roomId,
                'diagnostic_code' => (string) ($decoded['diagnostic_code'] ?? strtolower($kindName)),
                'diagnostic_level' => (string) ($decoded['diagnostic_level'] ?? 'info'),
                'diagnostic_message' => (string) ($decoded['diagnostic_message'] ?? ''),
                'transport_path' => (string) ($decoded['transport_path'] ?? ''),
                'payload_bytes' => (int) ($decoded['payload_bytes'] ?? 0),
                'wire_payload_bytes' => (int) ($decoded['wire_payload_bytes'] ?? 0),
                'queue_pressure_bytes' => (int) ($decoded['queue_pressure_bytes'] ?? 0),
                'binary_continuation_state' => (string) ($decoded['binary_continuation_state'] ?? ''),
            ],
            'error' => '',
        ];
    }

    return ['ok' => false, 'payload' => [], 'error' => 'unsupported_iibin_control_kind'];
}
