<?php

declare(strict_types=1);

const VIDEOCHAT_WLVC_MAGIC_U32_BE = 0x574C5643;
const VIDEOCHAT_WLVC_HEADER_BYTES = 28;

const VIDEOCHAT_WLVC_FRAME_TYPE_KEYFRAME = 0;
const VIDEOCHAT_WLVC_FRAME_TYPE_DELTA = 1;

const VIDEOCHAT_WLVC_QUALITY_MIN = 1;
const VIDEOCHAT_WLVC_QUALITY_MAX = 100;
const VIDEOCHAT_WLVC_DWT_LEVELS_MIN = 1;
const VIDEOCHAT_WLVC_DWT_LEVELS_MAX = 8;
const VIDEOCHAT_WLVC_DIMENSION_MIN = 1;
const VIDEOCHAT_WLVC_DIMENSION_MAX = 8192;
const VIDEOCHAT_WLVC_CHANNEL_MAX_BYTES = 16 * 1024 * 1024;
const VIDEOCHAT_WLVC_PAYLOAD_MAX_BYTES = 48 * 1024 * 1024;

function videochat_wlvc_is_int_range(mixed $value, int $min, int $max): bool
{
    return is_int($value) && $value >= $min && $value <= $max;
}

function videochat_wlvc_frame_to_hex(string $bytes): string
{
    return strtolower(bin2hex($bytes));
}

function videochat_wlvc_hex_to_bytes(string $hex): string
{
    $normalized = strtolower(trim($hex));
    if ($normalized === '') {
        return '';
    }

    if (strlen($normalized) % 2 !== 0 || preg_match('/[^0-9a-f]/', $normalized) === 1) {
        throw new InvalidArgumentException('wlvc_hex_invalid');
    }

    $decoded = hex2bin($normalized);
    if (!is_string($decoded)) {
        throw new InvalidArgumentException('wlvc_hex_invalid');
    }

    return $decoded;
}

/**
 * @return array{ok: bool, error_code?: string, bytes?: string, frame?: array<string, int>}
 */
function videochat_wlvc_encode_frame(array $input): array
{
    $version = isset($input['version']) ? (int) $input['version'] : 0;
    $frameType = isset($input['frame_type']) ? (int) $input['frame_type'] : -1;
    $quality = isset($input['quality']) ? (int) $input['quality'] : 0;
    $dwtLevels = isset($input['dwt_levels']) ? (int) $input['dwt_levels'] : 0;
    $width = isset($input['width']) ? (int) $input['width'] : 0;
    $height = isset($input['height']) ? (int) $input['height'] : 0;

    if (!videochat_wlvc_is_int_range($version, 1, 255)) {
        return ['ok' => false, 'error_code' => 'version_invalid'];
    }
    if (!in_array($frameType, [VIDEOCHAT_WLVC_FRAME_TYPE_KEYFRAME, VIDEOCHAT_WLVC_FRAME_TYPE_DELTA], true)) {
        return ['ok' => false, 'error_code' => 'frame_type_invalid'];
    }
    if (!videochat_wlvc_is_int_range($quality, VIDEOCHAT_WLVC_QUALITY_MIN, VIDEOCHAT_WLVC_QUALITY_MAX)) {
        return ['ok' => false, 'error_code' => 'quality_invalid'];
    }
    if (!videochat_wlvc_is_int_range($dwtLevels, VIDEOCHAT_WLVC_DWT_LEVELS_MIN, VIDEOCHAT_WLVC_DWT_LEVELS_MAX)) {
        return ['ok' => false, 'error_code' => 'dwt_levels_invalid'];
    }
    if (!videochat_wlvc_is_int_range($width, VIDEOCHAT_WLVC_DIMENSION_MIN, VIDEOCHAT_WLVC_DIMENSION_MAX)) {
        return ['ok' => false, 'error_code' => 'width_invalid'];
    }
    if (!videochat_wlvc_is_int_range($height, VIDEOCHAT_WLVC_DIMENSION_MIN, VIDEOCHAT_WLVC_DIMENSION_MAX)) {
        return ['ok' => false, 'error_code' => 'height_invalid'];
    }

    try {
        $yData = videochat_wlvc_payload_bytes($input, 'y_data', 'y_hex');
        $uData = videochat_wlvc_payload_bytes($input, 'u_data', 'u_hex');
        $vData = videochat_wlvc_payload_bytes($input, 'v_data', 'v_hex');
    } catch (Throwable $error) {
        return ['ok' => false, 'error_code' => $error->getMessage() !== '' ? $error->getMessage() : 'payload_type_invalid'];
    }

    $yLength = strlen($yData);
    $uLength = strlen($uData);
    $vLength = strlen($vData);

    if ($yLength > VIDEOCHAT_WLVC_CHANNEL_MAX_BYTES || $uLength > VIDEOCHAT_WLVC_CHANNEL_MAX_BYTES || $vLength > VIDEOCHAT_WLVC_CHANNEL_MAX_BYTES) {
        return ['ok' => false, 'error_code' => 'channel_too_large'];
    }

    $payloadLength = $yLength + $uLength + $vLength;
    if ($payloadLength > VIDEOCHAT_WLVC_PAYLOAD_MAX_BYTES) {
        return ['ok' => false, 'error_code' => 'payload_too_large'];
    }

    $defaultUvWidth = (int) ceil($width / 2);
    $defaultUvHeight = (int) ceil($height / 2);

    $uvWidth = isset($input['uv_width']) ? (int) $input['uv_width'] : $defaultUvWidth;
    $uvHeight = isset($input['uv_height']) ? (int) $input['uv_height'] : $defaultUvHeight;

    if (!videochat_wlvc_is_int_range($uvWidth, VIDEOCHAT_WLVC_DIMENSION_MIN, VIDEOCHAT_WLVC_DIMENSION_MAX)) {
        return ['ok' => false, 'error_code' => 'uv_width_invalid'];
    }
    if (!videochat_wlvc_is_int_range($uvHeight, VIDEOCHAT_WLVC_DIMENSION_MIN, VIDEOCHAT_WLVC_DIMENSION_MAX)) {
        return ['ok' => false, 'error_code' => 'uv_height_invalid'];
    }

    $header = pack('NCCCCnnNNNnn',
        VIDEOCHAT_WLVC_MAGIC_U32_BE,
        $version,
        $frameType,
        $quality,
        $dwtLevels,
        $width,
        $height,
        $yLength,
        $uLength,
        $vLength,
        $uvWidth,
        $uvHeight
    );

    return [
        'ok' => true,
        'bytes' => $header . $yData . $uData . $vData,
        'frame' => [
            'version' => $version,
            'frame_type' => $frameType,
            'quality' => $quality,
            'dwt_levels' => $dwtLevels,
            'width' => $width,
            'height' => $height,
            'uv_width' => $uvWidth,
            'uv_height' => $uvHeight,
            'y_length' => $yLength,
            'u_length' => $uLength,
            'v_length' => $vLength,
            'payload_length' => $payloadLength,
        ],
    ];
}

/**
 * @return array{ok: bool, error_code?: string, frame?: array<string, mixed>}
 */
function videochat_wlvc_decode_frame(string $bytes): array
{
    $totalLength = strlen($bytes);
    if ($totalLength < VIDEOCHAT_WLVC_HEADER_BYTES) {
        return ['ok' => false, 'error_code' => 'frame_too_short'];
    }

    $header = unpack('Nmagic/Cversion/Cframe_type/Cquality/Cdwt_levels/nwidth/nheight/Ny_length/Nu_length/Nv_length/nuv_width/nuv_height',
        substr($bytes, 0, VIDEOCHAT_WLVC_HEADER_BYTES)
    );
    if (!is_array($header)) {
        return ['ok' => false, 'error_code' => 'header_unpack_failed'];
    }

    $magic = (int) ($header['magic'] ?? 0);
    if ($magic !== VIDEOCHAT_WLVC_MAGIC_U32_BE) {
        return ['ok' => false, 'error_code' => 'magic_mismatch'];
    }

    $version = (int) ($header['version'] ?? 0);
    if ($version !== 1) {
        return ['ok' => false, 'error_code' => 'version_unsupported'];
    }

    $frameType = (int) ($header['frame_type'] ?? -1);
    if (!in_array($frameType, [VIDEOCHAT_WLVC_FRAME_TYPE_KEYFRAME, VIDEOCHAT_WLVC_FRAME_TYPE_DELTA], true)) {
        return ['ok' => false, 'error_code' => 'frame_type_invalid'];
    }

    $quality = (int) ($header['quality'] ?? 0);
    $dwtLevels = (int) ($header['dwt_levels'] ?? 0);
    $width = (int) ($header['width'] ?? 0);
    $height = (int) ($header['height'] ?? 0);
    $yLength = (int) ($header['y_length'] ?? -1);
    $uLength = (int) ($header['u_length'] ?? -1);
    $vLength = (int) ($header['v_length'] ?? -1);
    $uvWidth = (int) ($header['uv_width'] ?? 0);
    $uvHeight = (int) ($header['uv_height'] ?? 0);

    if (!videochat_wlvc_is_int_range($quality, VIDEOCHAT_WLVC_QUALITY_MIN, VIDEOCHAT_WLVC_QUALITY_MAX)) {
        return ['ok' => false, 'error_code' => 'quality_invalid'];
    }
    if (!videochat_wlvc_is_int_range($dwtLevels, VIDEOCHAT_WLVC_DWT_LEVELS_MIN, VIDEOCHAT_WLVC_DWT_LEVELS_MAX)) {
        return ['ok' => false, 'error_code' => 'dwt_levels_invalid'];
    }
    if (!videochat_wlvc_is_int_range($width, VIDEOCHAT_WLVC_DIMENSION_MIN, VIDEOCHAT_WLVC_DIMENSION_MAX)) {
        return ['ok' => false, 'error_code' => 'width_invalid'];
    }
    if (!videochat_wlvc_is_int_range($height, VIDEOCHAT_WLVC_DIMENSION_MIN, VIDEOCHAT_WLVC_DIMENSION_MAX)) {
        return ['ok' => false, 'error_code' => 'height_invalid'];
    }
    if (!videochat_wlvc_is_int_range($uvWidth, VIDEOCHAT_WLVC_DIMENSION_MIN, VIDEOCHAT_WLVC_DIMENSION_MAX)) {
        return ['ok' => false, 'error_code' => 'uv_width_invalid'];
    }
    if (!videochat_wlvc_is_int_range($uvHeight, VIDEOCHAT_WLVC_DIMENSION_MIN, VIDEOCHAT_WLVC_DIMENSION_MAX)) {
        return ['ok' => false, 'error_code' => 'uv_height_invalid'];
    }

    if ($yLength < 0 || $uLength < 0 || $vLength < 0) {
        return ['ok' => false, 'error_code' => 'channel_length_invalid'];
    }
    if ($yLength > VIDEOCHAT_WLVC_CHANNEL_MAX_BYTES || $uLength > VIDEOCHAT_WLVC_CHANNEL_MAX_BYTES || $vLength > VIDEOCHAT_WLVC_CHANNEL_MAX_BYTES) {
        return ['ok' => false, 'error_code' => 'channel_too_large'];
    }

    $payloadLength = $yLength + $uLength + $vLength;
    if ($payloadLength > VIDEOCHAT_WLVC_PAYLOAD_MAX_BYTES) {
        return ['ok' => false, 'error_code' => 'payload_too_large'];
    }

    if ($totalLength !== VIDEOCHAT_WLVC_HEADER_BYTES + $payloadLength) {
        return ['ok' => false, 'error_code' => 'payload_length_mismatch'];
    }

    $cursor = VIDEOCHAT_WLVC_HEADER_BYTES;
    $yData = substr($bytes, $cursor, $yLength);
    $cursor += $yLength;
    $uData = substr($bytes, $cursor, $uLength);
    $cursor += $uLength;
    $vData = substr($bytes, $cursor, $vLength);

    return [
        'ok' => true,
        'frame' => [
            'version' => $version,
            'frame_type' => $frameType,
            'quality' => $quality,
            'dwt_levels' => $dwtLevels,
            'width' => $width,
            'height' => $height,
            'uv_width' => $uvWidth,
            'uv_height' => $uvHeight,
            'y_length' => $yLength,
            'u_length' => $uLength,
            'v_length' => $vLength,
            'payload_length' => $payloadLength,
            'total_length' => $totalLength,
            'y_data' => $yData,
            'u_data' => $uData,
            'v_data' => $vData,
        ],
    ];
}

function videochat_wlvc_payload_bytes(array $input, string $bytesKey, string $hexKey): string
{
    $bytesValue = $input[$bytesKey] ?? null;
    if (is_string($bytesValue)) {
        return $bytesValue;
    }

    $hexValue = $input[$hexKey] ?? null;
    if (is_string($hexValue)) {
        return videochat_wlvc_hex_to_bytes($hexValue);
    }

    if ($bytesValue === null && $hexValue === null) {
        return '';
    }

    throw new InvalidArgumentException('wlvc_payload_type_invalid');
}
