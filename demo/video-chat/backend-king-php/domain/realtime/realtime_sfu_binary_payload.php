<?php

declare(strict_types=1);

function videochat_sfu_base64url_encoded_length(int $byteLength): int
{
    if ($byteLength <= 0) {
        return 0;
    }

    $base64Length = intdiv($byteLength + 2, 3) * 4;
    $paddingLength = (3 - ($byteLength % 3)) % 3;
    return $base64Length - $paddingLength;
}

function videochat_sfu_frame_data_binary(array $frame): string
{
    $dataBinary = $frame['data_binary'] ?? null;
    return is_string($dataBinary) ? $dataBinary : '';
}

function videochat_sfu_transport_payload_chars(string $dataBase64, string $dataBinary): int
{
    return $dataBinary !== '' ? videochat_sfu_base64url_encoded_length(strlen($dataBinary)) : strlen($dataBase64);
}

/**
 * @return array<string, mixed>
 */
function videochat_sfu_frame_json_safe_for_live_relay(array $frame): array
{
    $dataBinary = videochat_sfu_frame_data_binary($frame);
    if ($dataBinary === '') {
        return $frame;
    }

    unset($frame['data_binary']);
    $frame['data_base64'] = videochat_sfu_base64url_encode($dataBinary);
    $frame['payload_chars'] = videochat_sfu_base64url_encoded_length(strlen($dataBinary));
    $frame['payload_bytes'] = strlen($dataBinary);
    return $frame;
}
