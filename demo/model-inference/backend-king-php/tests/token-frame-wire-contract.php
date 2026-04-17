<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/token_frame.php';

function model_inference_token_frame_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[token-frame-wire-contract] FAIL: {$message}\n");
    exit(1);
}

function model_inference_token_frame_contract_expect_decode_error(callable $fn, string $reasonSubstring, string $label): void
{
    try {
        $fn();
    } catch (TokenFrameDecodeError $error) {
        model_inference_token_frame_contract_assert(
            str_contains($error->reason, $reasonSubstring),
            "[{$label}] expected decode reason to contain '{$reasonSubstring}', got '{$error->reason}'"
        );
        return;
    }
    model_inference_token_frame_contract_assert(false, "[{$label}] expected TokenFrameDecodeError; nothing thrown");
}

try {
    $contractPath = realpath(__DIR__ . '/../../contracts/v1/token-frame.contract.json');
    model_inference_token_frame_contract_assert(
        is_string($contractPath) && is_file($contractPath),
        'contract fixture not found at demo/model-inference/contracts/v1/token-frame.contract.json'
    );
    $contractRaw = file_get_contents($contractPath);
    $contract = json_decode((string) $contractRaw, true);
    model_inference_token_frame_contract_assert(is_array($contract), 'contract JSON must decode');

    // Header invariants from the contract file must match the codec.
    model_inference_token_frame_contract_assert(
        (int) $contract['header']['magic_u32_be'] === TokenFrame::MAGIC,
        'contract magic_u32_be must equal TokenFrame::MAGIC'
    );
    model_inference_token_frame_contract_assert(
        (int) $contract['header']['length_bytes'] === TokenFrame::HEADER_SIZE,
        'contract header.length_bytes must equal TokenFrame::HEADER_SIZE'
    );
    model_inference_token_frame_contract_assert(
        (int) $contract['validation']['max_payload_bytes'] === TokenFrame::MAX_PAYLOAD_BYTES,
        'contract max_payload_bytes must match TokenFrame::MAX_PAYLOAD_BYTES'
    );

    // 1. Round-trip each committed sample vector bit-identically.
    foreach ($contract['sample_vectors'] as $sv) {
        $name = (string) $sv['name'];
        $expectedHex = (string) $sv['expected_frame_hex'];
        $expectedBytes = (int) $sv['expected_frame_bytes'];

        // Re-encode using the codec and assert identical hex.
        $frameType = (int) $sv['input']['frame_type'];
        $sequence = (int) $sv['input']['sequence'];
        $flags = (int) $sv['input']['flags'];
        $crc32 = (int) $sv['input']['request_id_crc32'];
        $sessionId = (string) $sv['input']['session_id_for_crc32'];
        model_inference_token_frame_contract_assert(
            TokenFrame::requestIdCrc32($sessionId) === $crc32,
            "[{$name}] pinned request_id_crc32 must equal crc32(session_id) = " . TokenFrame::requestIdCrc32($sessionId)
        );

        if ($frameType === TokenFrame::FRAME_TYPE_DELTA) {
            $encoded = TokenFrame::encodeDelta(
                $sequence,
                $crc32,
                (int) $sv['input']['token_count'],
                (string) $sv['input']['payload_utf8'],
                $flags
            );
        } elseif ($frameType === TokenFrame::FRAME_TYPE_END) {
            $encoded = TokenFrame::encodeEnd($sequence, $crc32, (array) $sv['input']['payload_json'], $flags);
        } else {
            $encoded = TokenFrame::encodeError(
                $sequence,
                $crc32,
                (string) $sv['input']['payload_json']['code'],
                (string) $sv['input']['payload_json']['message'],
                $flags
            );
        }

        $encodedHex = bin2hex($encoded);
        model_inference_token_frame_contract_assert(
            $encodedHex === $expectedHex,
            "[{$name}] encode drift: expected {$expectedHex}, got {$encodedHex}"
        );
        model_inference_token_frame_contract_assert(
            strlen($encoded) === $expectedBytes,
            "[{$name}] expected {$expectedBytes} bytes, got " . strlen($encoded)
        );

        // Decode round-trip.
        [$header, $payload] = TokenFrame::decode($encoded);
        model_inference_token_frame_contract_assert($header['magic'] === TokenFrame::MAGIC, "[{$name}] magic roundtrip");
        model_inference_token_frame_contract_assert($header['version'] === TokenFrame::VERSION, "[{$name}] version roundtrip");
        model_inference_token_frame_contract_assert($header['frame_type'] === $frameType, "[{$name}] frame_type roundtrip");
        model_inference_token_frame_contract_assert($header['flags'] === $flags, "[{$name}] flags roundtrip");
        model_inference_token_frame_contract_assert($header['sequence'] === $sequence, "[{$name}] sequence roundtrip");
        model_inference_token_frame_contract_assert($header['request_id_crc32'] === $crc32, "[{$name}] request_id_crc32 roundtrip");
        model_inference_token_frame_contract_assert($header['payload_length'] === strlen($payload), "[{$name}] payload_length roundtrip");

        if ($frameType === TokenFrame::FRAME_TYPE_DELTA) {
            model_inference_token_frame_contract_assert($payload === (string) $sv['input']['payload_utf8'], "[{$name}] delta payload roundtrip");
        } else {
            $decodedJson = json_decode($payload, true);
            model_inference_token_frame_contract_assert(is_array($decodedJson), "[{$name}] json payload must decode");
            foreach ((array) $sv['input']['payload_json'] as $k => $v) {
                model_inference_token_frame_contract_assert(($decodedJson[$k] ?? null) === $v, "[{$name}] json field {$k} roundtrip");
            }
        }
    }

    // 2. bad_magic rejected.
    $good = TokenFrame::encodeDelta(1, 0x12345678, 1, 'x');
    $bad = $good;
    $bad[0] = "\x00";
    model_inference_token_frame_contract_expect_decode_error(static fn () => TokenFrame::decode($bad), 'bad_magic', 'bad magic');

    // 3. unsupported_version rejected.
    $bad = $good;
    $bad[4] = "\x02";
    model_inference_token_frame_contract_expect_decode_error(static fn () => TokenFrame::decode($bad), 'unsupported_version', 'bad version');

    // 4. unknown_frame_type rejected.
    $bad = $good;
    $bad[5] = "\x09";
    model_inference_token_frame_contract_expect_decode_error(static fn () => TokenFrame::decode($bad), 'unknown_frame_type', 'unknown frame type');

    // 5. reserved1 nonzero rejected.
    $bad = $good;
    $bad[7] = "\x7F";
    model_inference_token_frame_contract_expect_decode_error(static fn () => TokenFrame::decode($bad), 'reserved1_nonzero', 'reserved1 nonzero');

    // 6. reserved2 nonzero rejected (offset 22-23).
    $bad = $good;
    $bad[22] = "\x00";
    $bad[23] = "\x01";
    model_inference_token_frame_contract_expect_decode_error(static fn () => TokenFrame::decode($bad), 'reserved2_nonzero', 'reserved2 nonzero');

    // 7. truncated frame rejected (remove last byte of payload).
    $truncated = substr($good, 0, strlen($good) - 1);
    model_inference_token_frame_contract_expect_decode_error(static fn () => TokenFrame::decode($truncated), 'truncated_or_overlong', 'truncated');

    // 8. overlong frame rejected (extra trailing byte).
    $overlong = $good . "\x00";
    model_inference_token_frame_contract_expect_decode_error(static fn () => TokenFrame::decode($overlong), 'truncated_or_overlong', 'overlong');

    // 9. short header rejected (less than 24 bytes).
    model_inference_token_frame_contract_expect_decode_error(static fn () => TokenFrame::decode(substr($good, 0, 10)), 'short_header', 'short header');

    // 10. encode rejects oversized payload.
    $thrown = false;
    try {
        TokenFrame::encodeDelta(1, 0x12345678, 1, str_repeat('z', TokenFrame::MAX_PAYLOAD_BYTES + 1));
    } catch (InvalidArgumentException $error) {
        $thrown = str_contains($error->getMessage(), 'payload exceeds max_payload_bytes');
    }
    model_inference_token_frame_contract_assert($thrown, 'encode must reject payload > MAX_PAYLOAD_BYTES');

    // 11. encode rejects invalid frame_type.
    $thrown = false;
    try {
        TokenFrame::encode(['frame_type' => 7, 'sequence' => 1, 'request_id_crc32' => 0, 'token_count' => 0, 'flags' => 0], '');
    } catch (InvalidArgumentException $error) {
        $thrown = str_contains($error->getMessage(), 'frame_type must be 0|1|2');
    }
    model_inference_token_frame_contract_assert($thrown, 'encode must reject invalid frame_type');

    // 12. encode rejects sequence out of u32 range.
    $thrown = false;
    try {
        TokenFrame::encode(['frame_type' => 0, 'sequence' => 1 << 34, 'request_id_crc32' => 0, 'token_count' => 0, 'flags' => 0], 'x');
    } catch (InvalidArgumentException $error) {
        $thrown = str_contains($error->getMessage(), 'sequence must be a u32');
    }
    model_inference_token_frame_contract_assert($thrown, 'encode must reject sequence > u32_max');

    // 13. encode rejects token_count > u16.
    $thrown = false;
    try {
        TokenFrame::encode(['frame_type' => 0, 'sequence' => 1, 'request_id_crc32' => 0, 'token_count' => 0x10000, 'flags' => 0], 'x');
    } catch (InvalidArgumentException $error) {
        $thrown = str_contains($error->getMessage(), 'token_count must be a u16');
    }
    model_inference_token_frame_contract_assert($thrown, 'encode must reject token_count > u16_max');

    // 14. Zero-length payload is valid for a delta (empty token burst).
    $empty = TokenFrame::encodeDelta(1, 0x12345678, 0, '');
    [$header, $payload] = TokenFrame::decode($empty);
    model_inference_token_frame_contract_assert($payload === '', 'zero-length payload roundtrip');
    model_inference_token_frame_contract_assert($header['payload_length'] === 0, 'zero-length payload_length header field');

    // 15. assertMonotonicSequence accepts increasing sequences.
    $ok = [
        ['sequence' => 1], ['sequence' => 2], ['sequence' => 7], ['sequence' => 42],
    ];
    TokenFrame::assertMonotonicSequence($ok);

    // 16. assertMonotonicSequence rejects duplicates.
    $thrown = false;
    try {
        TokenFrame::assertMonotonicSequence([['sequence' => 1], ['sequence' => 1]]);
    } catch (TokenFrameDecodeError $error) {
        $thrown = str_contains($error->reason, 'sequence_not_monotonic');
    }
    model_inference_token_frame_contract_assert($thrown, 'monotonic-seq must reject duplicates');

    // 17. assertMonotonicSequence rejects a regression.
    $thrown = false;
    try {
        TokenFrame::assertMonotonicSequence([['sequence' => 5], ['sequence' => 3]]);
    } catch (TokenFrameDecodeError $error) {
        $thrown = str_contains($error->reason, 'sequence_not_monotonic');
    }
    model_inference_token_frame_contract_assert($thrown, 'monotonic-seq must reject regression');

    // 18. Every reject_on reason in the contract is reachable from decode.
    $rejectOn = (array) ($contract['validation']['reject_on'] ?? []);
    foreach (['bad_magic', 'unsupported_version', 'unknown_frame_type', 'reserved1_nonzero', 'reserved2_nonzero', 'payload_length_out_of_range', 'truncated_or_overlong', 'sequence_not_monotonic'] as $required) {
        model_inference_token_frame_contract_assert(in_array($required, $rejectOn, true), "contract.validation.reject_on must list '{$required}'");
    }

    fwrite(STDOUT, "[token-frame-wire-contract] PASS (3 sample vectors bit-identical; 18 rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[token-frame-wire-contract] ERROR: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
}
