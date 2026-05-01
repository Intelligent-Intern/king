<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/gateway_jwt.php';

function videochat_gateway_jwt_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[gateway-jwt-binding-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_gateway_jwt_contract_token(array $claims, string $secret, array $header = []): string
{
    $resolvedHeader = ['typ' => 'JWT', 'alg' => 'HS256', ...$header];
    $headerSegment = videochat_gateway_jwt_base64url_encode(json_encode($resolvedHeader, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $claimsSegment = videochat_gateway_jwt_base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $signatureSegment = videochat_gateway_jwt_sign_hs256($headerSegment . '.' . $claimsSegment, $secret);

    return $headerSegment . '.' . $claimsSegment . '.' . $signatureSegment;
}

try {
    $now = 1770000000;
    $secret = 'gateway-contract-secret-32-bytes-minimum';
    $join = ['peer_id' => 'peer-ada', 'room_id' => 'call-room-1'];
    $claims = [
        'sub' => 'peer-ada',
        'effective_id' => 'peer-ada',
        'room' => 'call-room-1',
        'call_id' => 'call-room-1',
        'exp' => $now + 300,
    ];

    $valid = videochat_gateway_jwt_contract_token($claims, $secret);
    $validResult = videochat_gateway_jwt_validate_join_token($valid, $secret, $join, ['now_unix' => $now]);
    videochat_gateway_jwt_contract_assert((bool) ($validResult['ok'] ?? false), 'valid HS256 token should pass');
    videochat_gateway_jwt_contract_assert((string) (($validResult['binding'] ?? [])['peer_id'] ?? '') === 'peer-ada', 'valid binding peer mismatch');
    videochat_gateway_jwt_contract_assert((string) (($validResult['binding'] ?? [])['room_id'] ?? '') === 'call-room-1', 'valid binding room mismatch');

    $callIdOnly = videochat_gateway_jwt_contract_token([
        'sub' => 'peer-ada',
        'effective_id' => 'peer-ada',
        'call_id' => 'call-room-1',
        'exp' => $now + 300,
    ], $secret);
    $callIdOnlyResult = videochat_gateway_jwt_validate_join_token($callIdOnly, $secret, $join, ['now_unix' => $now]);
    videochat_gateway_jwt_contract_assert((bool) ($callIdOnlyResult['ok'] ?? false), 'call_id-only room alias should pass');

    $peerMismatch = videochat_gateway_jwt_contract_token([
        ...$claims,
        'effective_id' => 'peer-mallory',
    ], $secret);
    $peerMismatchResult = videochat_gateway_jwt_validate_join_token($peerMismatch, $secret, $join, ['now_unix' => $now]);
    videochat_gateway_jwt_contract_assert(!(bool) ($peerMismatchResult['ok'] ?? true), 'peer mismatch should fail');
    videochat_gateway_jwt_contract_assert((string) ($peerMismatchResult['reason'] ?? '') === 'peer_binding_mismatch', 'peer mismatch reason mismatch');

    $roomMismatch = videochat_gateway_jwt_contract_token([
        ...$claims,
        'room' => 'call-room-2',
        'call_id' => 'call-room-2',
    ], $secret);
    $roomMismatchResult = videochat_gateway_jwt_validate_join_token($roomMismatch, $secret, $join, ['now_unix' => $now]);
    videochat_gateway_jwt_contract_assert(!(bool) ($roomMismatchResult['ok'] ?? true), 'room mismatch should fail');
    videochat_gateway_jwt_contract_assert((string) ($roomMismatchResult['reason'] ?? '') === 'room_binding_mismatch', 'room mismatch reason mismatch');

    $splitRoomClaim = videochat_gateway_jwt_contract_token([
        ...$claims,
        'room' => 'call-room-1',
        'call_id' => 'call-room-2',
    ], $secret);
    $splitRoomClaimResult = videochat_gateway_jwt_validate_join_token($splitRoomClaim, $secret, $join, ['now_unix' => $now]);
    videochat_gateway_jwt_contract_assert(!(bool) ($splitRoomClaimResult['ok'] ?? true), 'split room/call_id claim should fail');
    videochat_gateway_jwt_contract_assert((string) ($splitRoomClaimResult['reason'] ?? '') === 'room_claim_mismatch', 'split room claim reason mismatch');

    $missingEffective = videochat_gateway_jwt_contract_token([
        'sub' => 'peer-ada',
        'room' => 'call-room-1',
        'call_id' => 'call-room-1',
        'exp' => $now + 300,
    ], $secret);
    $missingEffectiveResult = videochat_gateway_jwt_validate_join_token($missingEffective, $secret, $join, ['now_unix' => $now]);
    videochat_gateway_jwt_contract_assert(!(bool) ($missingEffectiveResult['ok'] ?? true), 'missing effective_id should fail');
    videochat_gateway_jwt_contract_assert((string) ($missingEffectiveResult['reason'] ?? '') === 'missing_peer_claims', 'missing effective_id reason mismatch');

    $expired = videochat_gateway_jwt_contract_token([...$claims, 'exp' => $now - 1], $secret);
    $expiredResult = videochat_gateway_jwt_validate_join_token($expired, $secret, $join, ['now_unix' => $now]);
    videochat_gateway_jwt_contract_assert(!(bool) ($expiredResult['ok'] ?? true), 'expired token should fail');
    videochat_gateway_jwt_contract_assert((string) ($expiredResult['reason'] ?? '') === 'expired', 'expired reason mismatch');

    $wrongAlg = videochat_gateway_jwt_contract_token($claims, $secret, ['alg' => 'none']);
    $wrongAlgResult = videochat_gateway_jwt_validate_join_token($wrongAlg, $secret, $join, ['now_unix' => $now]);
    videochat_gateway_jwt_contract_assert(!(bool) ($wrongAlgResult['ok'] ?? true), 'non-HS256 token should fail');
    videochat_gateway_jwt_contract_assert((string) ($wrongAlgResult['reason'] ?? '') === 'unsupported_alg', 'wrong alg reason mismatch');

    $wrongSecretResult = videochat_gateway_jwt_validate_join_token($valid, $secret . '-wrong', $join, ['now_unix' => $now]);
    videochat_gateway_jwt_contract_assert(!(bool) ($wrongSecretResult['ok'] ?? true), 'wrong secret should fail signature check');
    videochat_gateway_jwt_contract_assert((string) ($wrongSecretResult['reason'] ?? '') === 'invalid_signature', 'wrong secret reason mismatch');

    $unsafeSecretResult = videochat_gateway_jwt_validate_join_token($valid, 'dev-secret-unsafe', $join, ['now_unix' => $now]);
    videochat_gateway_jwt_contract_assert(!(bool) ($unsafeSecretResult['ok'] ?? true), 'unsafe secret should fail');
    videochat_gateway_jwt_contract_assert((string) ($unsafeSecretResult['reason'] ?? '') === 'invalid_secret', 'unsafe secret reason mismatch');
    videochat_gateway_jwt_contract_assert((string) (($unsafeSecretResult['details'] ?? [])['secret_reason'] ?? '') === 'unsafe_secret', 'unsafe secret detail mismatch');

    $oversizedResult = videochat_gateway_jwt_validate_join_token($valid . str_repeat('x', 5000), $secret, $join, ['now_unix' => $now, 'max_token_bytes' => 512]);
    videochat_gateway_jwt_contract_assert(!(bool) ($oversizedResult['ok'] ?? true), 'oversized token should fail');
    videochat_gateway_jwt_contract_assert((string) ($oversizedResult['reason'] ?? '') === 'token_too_large', 'oversized token reason mismatch');

    $rateState = [];
    $first = videochat_gateway_join_rate_limit_allow($rateState, 'peer-ada:call-room-1', $now, 2, 60);
    $second = videochat_gateway_join_rate_limit_allow($rateState, 'peer-ada:call-room-1', $now + 1, 2, 60);
    $third = videochat_gateway_join_rate_limit_allow($rateState, 'peer-ada:call-room-1', $now + 2, 2, 60);
    $afterWindow = videochat_gateway_join_rate_limit_allow($rateState, 'peer-ada:call-room-1', $now + 61, 2, 60);
    videochat_gateway_jwt_contract_assert((bool) ($first['ok'] ?? false), 'first join should pass rate limit');
    videochat_gateway_jwt_contract_assert((bool) ($second['ok'] ?? false), 'second join should pass rate limit');
    videochat_gateway_jwt_contract_assert(!(bool) ($third['ok'] ?? true), 'third join should be rate limited');
    videochat_gateway_jwt_contract_assert((string) ($third['reason'] ?? '') === 'rate_limited', 'rate-limit reason mismatch');
    videochat_gateway_jwt_contract_assert((bool) ($afterWindow['ok'] ?? false), 'rate limit should reopen after window');

    fwrite(STDOUT, "[gateway-jwt-binding-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[gateway-jwt-binding-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
