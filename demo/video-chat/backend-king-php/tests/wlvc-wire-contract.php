<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/wlvc_frame.php';

function videochat_wlvc_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[wlvc-wire-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_wlvc_contract_load_catalog(): array
{
    $path = __DIR__ . '/../../contracts/v1/wlvc-frame.contract.json';
    $raw = file_get_contents($path);
    videochat_wlvc_contract_assert(is_string($raw) && trim($raw) !== '', 'wlvc contract file missing/empty');

    try {
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        fwrite(STDERR, "[wlvc-wire-contract] FAIL: invalid contract JSON: {$error->getMessage()}\n");
        exit(1);
    }

    videochat_wlvc_contract_assert(is_array($decoded), 'contract root must be object');
    return $decoded;
}

try {
    $catalog = videochat_wlvc_contract_load_catalog();

    videochat_wlvc_contract_assert(
        (string) ($catalog['contract_name'] ?? '') === 'king-video-chat-wlvc-frame',
        'contract_name mismatch'
    );
    videochat_wlvc_contract_assert(
        (string) ($catalog['contract_version'] ?? '') === '1.0.0',
        'contract_version mismatch'
    );
    videochat_wlvc_contract_assert(
        (int) (($catalog['header'] ?? [])['length_bytes'] ?? 0) === VIDEOCHAT_WLVC_HEADER_BYTES,
        'header length mismatch'
    );

    $vector = $catalog['sample_vectors'][0] ?? null;
    videochat_wlvc_contract_assert(is_array($vector), 'missing sample vector');

    $frame = $vector['frame'] ?? null;
    $expectedHex = strtolower((string) ($vector['expected_frame_hex'] ?? ''));

    videochat_wlvc_contract_assert(is_array($frame), 'sample vector frame missing');
    videochat_wlvc_contract_assert($expectedHex !== '', 'sample vector expected_frame_hex missing');

    $encoded = videochat_wlvc_encode_frame([
        'version' => (int) ($frame['version'] ?? 0),
        'frame_type' => (int) ($frame['frame_type'] ?? -1),
        'quality' => (int) ($frame['quality'] ?? 0),
        'dwt_levels' => (int) ($frame['dwt_levels'] ?? 0),
        'width' => (int) ($frame['width'] ?? 0),
        'height' => (int) ($frame['height'] ?? 0),
        'uv_width' => (int) ($frame['uv_width'] ?? 0),
        'uv_height' => (int) ($frame['uv_height'] ?? 0),
        'y_hex' => (string) ($frame['y_hex'] ?? ''),
        'u_hex' => (string) ($frame['u_hex'] ?? ''),
        'v_hex' => (string) ($frame['v_hex'] ?? ''),
    ]);

    videochat_wlvc_contract_assert((bool) ($encoded['ok'] ?? false), 'sample encode should succeed');
    $encodedBytes = (string) ($encoded['bytes'] ?? '');
    $encodedHex = videochat_wlvc_frame_to_hex($encodedBytes);
    videochat_wlvc_contract_assert($encodedHex === $expectedHex, 'encoded sample hex mismatch');

    $decoded = videochat_wlvc_decode_frame(videochat_wlvc_hex_to_bytes($expectedHex));
    videochat_wlvc_contract_assert((bool) ($decoded['ok'] ?? false), 'sample decode should succeed');

    $decodedFrame = (array) ($decoded['frame'] ?? []);
    videochat_wlvc_contract_assert((int) ($decodedFrame['version'] ?? 0) === 1, 'decoded version mismatch');
    videochat_wlvc_contract_assert((int) ($decodedFrame['frame_type'] ?? -1) === 0, 'decoded frame_type mismatch');
    videochat_wlvc_contract_assert((int) ($decodedFrame['quality'] ?? 0) === 73, 'decoded quality mismatch');
    videochat_wlvc_contract_assert((int) ($decodedFrame['dwt_levels'] ?? 0) === 4, 'decoded dwt_levels mismatch');
    videochat_wlvc_contract_assert((int) ($decodedFrame['width'] ?? 0) === 640, 'decoded width mismatch');
    videochat_wlvc_contract_assert((int) ($decodedFrame['height'] ?? 0) === 360, 'decoded height mismatch');
    videochat_wlvc_contract_assert((int) ($decodedFrame['uv_width'] ?? 0) === 320, 'decoded uv_width mismatch');
    videochat_wlvc_contract_assert((int) ($decodedFrame['uv_height'] ?? 0) === 180, 'decoded uv_height mismatch');
    videochat_wlvc_contract_assert(videochat_wlvc_frame_to_hex((string) ($decodedFrame['y_data'] ?? '')) === '0a0b0c0d', 'decoded y channel mismatch');
    videochat_wlvc_contract_assert(videochat_wlvc_frame_to_hex((string) ($decodedFrame['u_data'] ?? '')) === '1112', 'decoded u channel mismatch');
    videochat_wlvc_contract_assert(videochat_wlvc_frame_to_hex((string) ($decodedFrame['v_data'] ?? '')) === 'aabbcc', 'decoded v channel mismatch');

    $badMagicBytes = $encodedBytes;
    $badMagicBytes[0] = "\x58";
    $badMagic = videochat_wlvc_decode_frame($badMagicBytes);
    videochat_wlvc_contract_assert(!(bool) ($badMagic['ok'] ?? true), 'decode should fail on bad magic');
    videochat_wlvc_contract_assert((string) ($badMagic['error_code'] ?? '') === 'magic_mismatch', 'bad magic error mismatch');

    $truncatedHeader = videochat_wlvc_decode_frame(substr($encodedBytes, 0, VIDEOCHAT_WLVC_HEADER_BYTES - 1));
    videochat_wlvc_contract_assert(!(bool) ($truncatedHeader['ok'] ?? true), 'decode should fail on short header');
    videochat_wlvc_contract_assert((string) ($truncatedHeader['error_code'] ?? '') === 'frame_too_short', 'short header error mismatch');

    $truncatedPayload = videochat_wlvc_decode_frame(substr($encodedBytes, 0, -1));
    videochat_wlvc_contract_assert(!(bool) ($truncatedPayload['ok'] ?? true), 'decode should fail on short payload');
    videochat_wlvc_contract_assert((string) ($truncatedPayload['error_code'] ?? '') === 'payload_length_mismatch', 'short payload error mismatch');

    $badFrameType = $encodedBytes;
    $badFrameType[5] = "\x07";
    $badFrameTypeDecoded = videochat_wlvc_decode_frame($badFrameType);
    videochat_wlvc_contract_assert(!(bool) ($badFrameTypeDecoded['ok'] ?? true), 'decode should fail on invalid frame type');
    videochat_wlvc_contract_assert((string) ($badFrameTypeDecoded['error_code'] ?? '') === 'frame_type_invalid', 'bad frame type error mismatch');

    fwrite(STDOUT, "[wlvc-wire-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[wlvc-wire-contract] FAIL: unexpected exception: {$error->getMessage()}\n");
    exit(1);
}
