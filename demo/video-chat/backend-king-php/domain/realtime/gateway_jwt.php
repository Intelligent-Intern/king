<?php

declare(strict_types=1);

function videochat_gateway_jwt_base64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function videochat_gateway_jwt_base64url_decode(string $value): ?string
{
    $normalized = strtr(trim($value), '-_', '+/');
    if ($normalized === '') {
        return null;
    }

    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($normalized, true);
    return is_string($decoded) ? $decoded : null;
}

/**
 * @return array<string, mixed>|null
 */
function videochat_gateway_jwt_decode_json_segment(string $segment): ?array
{
    $json = videochat_gateway_jwt_base64url_decode($segment);
    if (!is_string($json) || $json === '') {
        return null;
    }

    try {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array{ok: bool, reason: string}
 */
function videochat_gateway_jwt_validate_secret(string $secret): array
{
    $trimmed = trim($secret);
    if ($trimmed === '') {
        return ['ok' => false, 'reason' => 'missing_secret'];
    }

    $unsafeValues = [
        'dev-secret-unsafe',
        'secret',
        'change-me',
        'changeme',
        'jwt-secret',
    ];
    if (in_array(strtolower($trimmed), $unsafeValues, true)) {
        return ['ok' => false, 'reason' => 'unsafe_secret'];
    }

    if (strlen($trimmed) < 32) {
        return ['ok' => false, 'reason' => 'weak_secret'];
    }

    return ['ok' => true, 'reason' => 'ok'];
}

function videochat_gateway_jwt_sign_hs256(string $signingInput, string $secret): string
{
    return videochat_gateway_jwt_base64url_encode(hash_hmac('sha256', $signingInput, $secret, true));
}

function videochat_gateway_jwt_claim_string(array $claims, string $key): string
{
    $value = $claims[$key] ?? null;
    if (!is_scalar($value)) {
        return '';
    }

    return trim((string) $value);
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   claims: array<string, mixed>,
 *   binding: array{peer_id: string, room_id: string},
 *   details: array<string, mixed>
 * }
 */
function videochat_gateway_jwt_validate_join_token(string $token, string $secret, array $joinContext, array $options = []): array
{
    $empty = [
        'ok' => false,
        'reason' => 'invalid_token',
        'claims' => [],
        'binding' => ['peer_id' => '', 'room_id' => ''],
        'details' => [],
    ];

    $maxTokenBytes = is_numeric($options['max_token_bytes'] ?? null) ? (int) $options['max_token_bytes'] : 4096;
    if ($maxTokenBytes < 256) {
        $maxTokenBytes = 256;
    }
    if (strlen($token) > $maxTokenBytes) {
        return [...$empty, 'reason' => 'token_too_large', 'details' => ['max_token_bytes' => $maxTokenBytes]];
    }

    $secretCheck = videochat_gateway_jwt_validate_secret($secret);
    if (!(bool) ($secretCheck['ok'] ?? false)) {
        return [...$empty, 'reason' => 'invalid_secret', 'details' => ['secret_reason' => (string) ($secretCheck['reason'] ?? 'invalid')]];
    }

    $parts = explode('.', trim($token));
    if (count($parts) !== 3 || in_array('', $parts, true)) {
        return [...$empty, 'reason' => 'malformed_token'];
    }

    [$headerSegment, $claimsSegment, $signatureSegment] = $parts;
    $header = videochat_gateway_jwt_decode_json_segment($headerSegment);
    if (!is_array($header)) {
        return [...$empty, 'reason' => 'invalid_header'];
    }

    if (strtoupper(trim((string) ($header['alg'] ?? ''))) !== 'HS256') {
        return [...$empty, 'reason' => 'unsupported_alg'];
    }

    $expectedSignature = videochat_gateway_jwt_sign_hs256($headerSegment . '.' . $claimsSegment, trim($secret));
    if (!hash_equals($expectedSignature, $signatureSegment)) {
        return [...$empty, 'reason' => 'invalid_signature'];
    }

    $claims = videochat_gateway_jwt_decode_json_segment($claimsSegment);
    if (!is_array($claims)) {
        return [...$empty, 'reason' => 'invalid_claims'];
    }

    $nowUnix = is_numeric($options['now_unix'] ?? null) ? (int) $options['now_unix'] : time();
    $leeway = is_numeric($options['leeway_seconds'] ?? null) ? max((int) $options['leeway_seconds'], 0) : 0;
    if (!is_numeric($claims['exp'] ?? null)) {
        return [...$empty, 'reason' => 'missing_exp', 'claims' => $claims];
    }
    if ((int) $claims['exp'] <= ($nowUnix - $leeway)) {
        return [...$empty, 'reason' => 'expired', 'claims' => $claims];
    }

    $expectedPeerId = trim((string) ($joinContext['peer_id'] ?? ''));
    $expectedRoomId = trim((string) ($joinContext['room_id'] ?? ''));
    if ($expectedPeerId === '' || $expectedRoomId === '') {
        return [...$empty, 'reason' => 'missing_join_context', 'claims' => $claims];
    }

    $subject = videochat_gateway_jwt_claim_string($claims, 'sub');
    $effectiveId = videochat_gateway_jwt_claim_string($claims, 'effective_id');
    if ($subject === '' || $effectiveId === '') {
        return [...$empty, 'reason' => 'missing_peer_claims', 'claims' => $claims];
    }
    if ($subject !== $expectedPeerId || $effectiveId !== $expectedPeerId || $subject !== $effectiveId) {
        return [
            ...$empty,
            'reason' => 'peer_binding_mismatch',
            'claims' => $claims,
            'details' => ['sub' => $subject, 'effective_id' => $effectiveId, 'expected_peer_id' => $expectedPeerId],
        ];
    }

    $claimRoomId = videochat_gateway_jwt_claim_string($claims, 'room');
    $claimCallId = videochat_gateway_jwt_claim_string($claims, 'call_id');
    if ($claimRoomId === '' && $claimCallId === '') {
        return [...$empty, 'reason' => 'missing_room_claim', 'claims' => $claims];
    }
    if ($claimRoomId !== '' && $claimCallId !== '' && $claimRoomId !== $claimCallId) {
        return [
            ...$empty,
            'reason' => 'room_claim_mismatch',
            'claims' => $claims,
            'details' => ['room' => $claimRoomId, 'call_id' => $claimCallId],
        ];
    }

    $boundRoomId = $claimRoomId !== '' ? $claimRoomId : $claimCallId;
    if ($boundRoomId !== $expectedRoomId) {
        return [
            ...$empty,
            'reason' => 'room_binding_mismatch',
            'claims' => $claims,
            'details' => ['bound_room_id' => $boundRoomId, 'expected_room_id' => $expectedRoomId],
        ];
    }

    return [
        'ok' => true,
        'reason' => 'ok',
        'claims' => $claims,
        'binding' => ['peer_id' => $expectedPeerId, 'room_id' => $boundRoomId],
        'details' => [],
    ];
}

/**
 * @return array{ok: bool, reason: string, remaining: int, retry_after_seconds: int}
 */
function videochat_gateway_join_rate_limit_allow(
    array &$state,
    string $scopeKey,
    int $nowUnix,
    int $limit = 20,
    int $windowSeconds = 60
): array {
    $key = trim($scopeKey) === '' ? 'anonymous' : trim($scopeKey);
    $effectiveLimit = max($limit, 1);
    $effectiveWindow = max($windowSeconds, 1);
    $cutoff = $nowUnix - $effectiveWindow + 1;

    $events = $state[$key] ?? [];
    if (!is_array($events)) {
        $events = [];
    }

    $events = array_values(array_filter(
        $events,
        static fn (mixed $timestamp): bool => is_numeric($timestamp) && (int) $timestamp >= $cutoff
    ));

    if (count($events) >= $effectiveLimit) {
        $oldest = (int) min($events);
        $state[$key] = $events;
        return [
            'ok' => false,
            'reason' => 'rate_limited',
            'remaining' => 0,
            'retry_after_seconds' => max(($oldest + $effectiveWindow) - $nowUnix, 1),
        ];
    }

    $events[] = $nowUnix;
    $state[$key] = $events;

    return [
        'ok' => true,
        'reason' => 'ok',
        'remaining' => max($effectiveLimit - count($events), 0),
        'retry_after_seconds' => 0,
    ];
}
