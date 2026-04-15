<?php

declare(strict_types=1);

/**
 * IIBIN-style token-frame codec.
 *
 * Fixed big-endian binary framing for server → client token streaming on the
 * WS upgrade path (introduced by #M-11). The header is 24 bytes, fixed
 * layout, versioned; payload format depends on frame_type.
 *
 * See demo/model-inference/contracts/v1/token-frame.contract.json for the
 * canonical layout + committed hex sample vectors. This file mirrors every
 * field and validation rule that contract pins; keep them aligned when
 * either changes.
 *
 * Header (offset: field, bytes, type):
 *   0 : magic              (u32 be = 0x4B495446, "KITF")
 *   4 : version            (u8  = 1)
 *   5 : frame_type         (u8  = 0=delta | 1=end | 2=error)
 *   6 : flags              (u8  bit0 = final_in_burst, bit1 = utf8_boundary_safe)
 *   7 : reserved1          (u8  = 0)
 *   8 : sequence           (u32 be, monotonically increases within one
 *                           request stream)
 *  12 : request_id_crc32   (u32 be; crc32 of the ASCII request_id — lets
 *                           the client de-mux concurrent streams quickly
 *                           without parsing the full id)
 *  16 : token_count        (u16 be; number of tokens in this frame's
 *                           payload. llama.cpp can batch; 1-frame-per-token
 *                           is not claimed)
 *  18 : payload_length     (u32 be; length of the payload body in bytes)
 *  22 : reserved2          (u16 be = 0)
 *
 * Payload:
 *   delta → raw UTF-8 token bytes, length = payload_length
 *   end   → JSON body (tokens_in, tokens_out, ttft_ms, duration_ms)
 *   error → JSON body ({code, message})
 */

final class TokenFrameDecodeError extends RuntimeException
{
    public string $reason;

    public function __construct(string $reason)
    {
        $this->reason = $reason;
        parent::__construct($reason);
    }
}

final class TokenFrame
{
    public const MAGIC = 0x4B495446;            // "KITF"
    public const VERSION = 1;

    public const FRAME_TYPE_DELTA = 0;
    public const FRAME_TYPE_END = 1;
    public const FRAME_TYPE_ERROR = 2;

    public const FLAG_FINAL_IN_BURST = 0x01;
    public const FLAG_UTF8_BOUNDARY_SAFE = 0x02;

    public const HEADER_SIZE = 24;
    public const MAX_PAYLOAD_BYTES = 1048576;

    public const SUPPORTED_VERSIONS = [self::VERSION];

    /**
     * Encode a full frame (header + payload) to a binary string.
     *
     * @param array{frame_type:int, sequence:int, request_id_crc32:int, token_count:int, flags?:int} $header
     */
    public static function encode(array $header, string $payload): string
    {
        $frameType = (int) ($header['frame_type'] ?? -1);
        if (!in_array($frameType, [self::FRAME_TYPE_DELTA, self::FRAME_TYPE_END, self::FRAME_TYPE_ERROR], true)) {
            throw new InvalidArgumentException("frame_type must be 0|1|2 (got {$frameType})");
        }

        $flags = (int) ($header['flags'] ?? 0);
        if ($flags < 0 || $flags > 0xFF) {
            throw new InvalidArgumentException("flags must fit in u8 (got {$flags})");
        }

        $sequence = (int) ($header['sequence'] ?? -1);
        if ($sequence < 0 || $sequence > 0xFFFFFFFF) {
            throw new InvalidArgumentException("sequence must be a u32 (got {$sequence})");
        }

        $requestIdCrc = (int) ($header['request_id_crc32'] ?? -1);
        if ($requestIdCrc < 0 || $requestIdCrc > 0xFFFFFFFF) {
            throw new InvalidArgumentException("request_id_crc32 must be a u32 (got {$requestIdCrc})");
        }

        $tokenCount = (int) ($header['token_count'] ?? 0);
        if ($tokenCount < 0 || $tokenCount > 0xFFFF) {
            throw new InvalidArgumentException("token_count must be a u16 (got {$tokenCount})");
        }

        $payloadLength = strlen($payload);
        if ($payloadLength > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException("payload exceeds max_payload_bytes (got {$payloadLength} > " . self::MAX_PAYLOAD_BYTES . ')');
        }

        // N: u32 big-endian, n: u16 big-endian, C: u8.
        $headerBytes = pack(
            'NCCCCNNnNn',
            self::MAGIC,
            self::VERSION,
            $frameType,
            $flags,
            0,                          // reserved1
            $sequence,
            $requestIdCrc,
            $tokenCount,
            $payloadLength,
            0                           // reserved2
        );

        return $headerBytes . $payload;
    }

    /**
     * Decode a full frame. Returns [$header, $payload].
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function decode(string $bytes): array
    {
        $byteLength = strlen($bytes);
        if ($byteLength < self::HEADER_SIZE) {
            throw new TokenFrameDecodeError("short_header: need " . self::HEADER_SIZE . ", got {$byteLength}");
        }

        $unpacked = unpack(
            'Nmagic/Cversion/Cframe_type/Cflags/Creserved1/Nsequence/Nrequest_id_crc32/ntoken_count/Npayload_length/nreserved2',
            substr($bytes, 0, self::HEADER_SIZE)
        );
        if (!is_array($unpacked)) {
            throw new TokenFrameDecodeError('header_unpack_failed');
        }

        if ((int) $unpacked['magic'] !== self::MAGIC) {
            throw new TokenFrameDecodeError(sprintf('bad_magic: expected 0x%08X, got 0x%08X', self::MAGIC, (int) $unpacked['magic']));
        }
        if (!in_array((int) $unpacked['version'], self::SUPPORTED_VERSIONS, true)) {
            throw new TokenFrameDecodeError("unsupported_version: " . (int) $unpacked['version']);
        }
        if (!in_array((int) $unpacked['frame_type'], [self::FRAME_TYPE_DELTA, self::FRAME_TYPE_END, self::FRAME_TYPE_ERROR], true)) {
            throw new TokenFrameDecodeError("unknown_frame_type: " . (int) $unpacked['frame_type']);
        }
        if ((int) $unpacked['reserved1'] !== 0) {
            throw new TokenFrameDecodeError('reserved1_nonzero');
        }
        if ((int) $unpacked['reserved2'] !== 0) {
            throw new TokenFrameDecodeError('reserved2_nonzero');
        }

        $payloadLength = (int) $unpacked['payload_length'];
        if ($payloadLength < 0 || $payloadLength > self::MAX_PAYLOAD_BYTES) {
            throw new TokenFrameDecodeError("payload_length_out_of_range: {$payloadLength}");
        }
        $expectedTotal = self::HEADER_SIZE + $payloadLength;
        if ($byteLength !== $expectedTotal) {
            throw new TokenFrameDecodeError("truncated_or_overlong: expected {$expectedTotal} bytes, got {$byteLength}");
        }

        $payload = $payloadLength === 0 ? '' : substr($bytes, self::HEADER_SIZE, $payloadLength);

        return [
            [
                'magic' => (int) $unpacked['magic'],
                'version' => (int) $unpacked['version'],
                'frame_type' => (int) $unpacked['frame_type'],
                'flags' => (int) $unpacked['flags'],
                'sequence' => (int) $unpacked['sequence'],
                'request_id_crc32' => (int) $unpacked['request_id_crc32'],
                'token_count' => (int) $unpacked['token_count'],
                'payload_length' => $payloadLength,
            ],
            $payload,
        ];
    }

    /**
     * Compute the canonical request_id_crc32 for a request id string.
     * Matches the value the wire format expects.
     */
    public static function requestIdCrc32(string $requestId): int
    {
        return crc32($requestId) & 0xFFFFFFFF;
    }

    /**
     * Convenience: encode a delta (UTF-8 token bytes) frame.
     */
    public static function encodeDelta(int $sequence, int $requestIdCrc32, int $tokenCount, string $tokenBytes, int $flags = 0): string
    {
        return self::encode([
            'frame_type' => self::FRAME_TYPE_DELTA,
            'flags' => $flags,
            'sequence' => $sequence,
            'request_id_crc32' => $requestIdCrc32,
            'token_count' => $tokenCount,
        ], $tokenBytes);
    }

    /**
     * Convenience: encode an end frame with a JSON summary body.
     *
     * @param array{tokens_in:int, tokens_out:int, ttft_ms:int, duration_ms:int} $summary
     */
    public static function encodeEnd(int $sequence, int $requestIdCrc32, array $summary, int $flags = self::FLAG_FINAL_IN_BURST): string
    {
        foreach (['tokens_in', 'tokens_out', 'ttft_ms', 'duration_ms'] as $field) {
            if (!array_key_exists($field, $summary)) {
                throw new InvalidArgumentException("end summary missing '{$field}'");
            }
        }
        $ordered = [
            'tokens_in' => (int) $summary['tokens_in'],
            'tokens_out' => (int) $summary['tokens_out'],
            'ttft_ms' => (int) $summary['ttft_ms'],
            'duration_ms' => (int) $summary['duration_ms'],
        ];
        $body = json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            throw new RuntimeException('end summary failed to encode to JSON');
        }
        return self::encode([
            'frame_type' => self::FRAME_TYPE_END,
            'flags' => $flags,
            'sequence' => $sequence,
            'request_id_crc32' => $requestIdCrc32,
            'token_count' => 0,
        ], $body);
    }

    /**
     * Convenience: encode an error frame with a {code, message} JSON body.
     */
    public static function encodeError(int $sequence, int $requestIdCrc32, string $code, string $message, int $flags = self::FLAG_FINAL_IN_BURST): string
    {
        $ordered = [
            'code' => $code,
            'message' => $message,
        ];
        $body = json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            throw new RuntimeException('error body failed to encode to JSON');
        }
        return self::encode([
            'frame_type' => self::FRAME_TYPE_ERROR,
            'flags' => $flags,
            'sequence' => $sequence,
            'request_id_crc32' => $requestIdCrc32,
            'token_count' => 0,
        ], $body);
    }

    /**
     * Assert that a list of decoded header arrays has strictly increasing
     * sequence numbers. Throws TokenFrameDecodeError on the first violation.
     *
     * @param array<int, array<string, mixed>> $headers
     */
    public static function assertMonotonicSequence(array $headers): void
    {
        $prev = null;
        foreach ($headers as $idx => $header) {
            $seq = (int) ($header['sequence'] ?? 0);
            if ($prev !== null && $seq <= $prev) {
                throw new TokenFrameDecodeError("sequence_not_monotonic: frame #{$idx} sequence={$seq} not > previous {$prev}");
            }
            $prev = $seq;
        }
    }
}
