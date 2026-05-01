<?php

declare(strict_types=1);

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_turn_default_stun_uris(): array
{
    $candidates = [
        trim((string) (getenv('VIDEOCHAT_STUN_DEFAULT_DOMAIN') ?: '')),
        trim((string) (getenv('VIDEOCHAT_STUN_DOMAIN') ?: '')),
        trim((string) (getenv('VIDEOCHAT_DEPLOY_TURN_DOMAIN') ?: '')),
        trim((string) (getenv('VIDEOCHAT_EDGE_TURN_DOMAIN') ?: '')),
        trim((string) (getenv('VIDEOCHAT_V1_TURN_REALM') ?: '')),
        trim((string) (getenv('TURN_DOMAIN') ?: '')),
    ];

    $publicHost = trim((string) (getenv('VIDEOCHAT_V1_PUBLIC_HOST') ?: getenv('VIDEOCHAT_EDGE_DOMAIN') ?: ''));
    if ($publicHost !== '') {
        $candidates[] = preg_match('/^(localhost|[0-9.]+)$/', $publicHost) === 1 || str_contains($publicHost, ':')
            ? $publicHost
            : 'turn.' . preg_replace('/^(api|ws|sfu|cdn|cnd|turn)\./i', '', $publicHost);
    }

    $uris = [];
    foreach ($candidates as $candidate) {
        $normalized = strtolower(trim((string) $candidate));
        if ($normalized === '') {
            continue;
        }

        $normalized = preg_replace('/^[a-z]+:\/\//i', '', $normalized) ?? $normalized;
        $normalized = explode('/', $normalized, 2)[0];
        if ($normalized === '') {
            continue;
        }

        $uris['stun:' . $normalized . ':3478'] = true;
    }

    return array_keys($uris);
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_turn_static_stun_servers(): array
{
    $raw = trim((string) (getenv('VIDEOCHAT_STUN_URIS') ?: ''));
    $uris = $raw !== ''
        ? preg_split('/\s*,\s*/', $raw) ?: []
        : videochat_turn_default_stun_uris();

    $servers = [];
    foreach ($uris as $uri) {
        $normalizedUri = trim((string) $uri);
        if ($normalizedUri === '' || preg_match('/^stuns?:/i', $normalizedUri) !== 1) {
            continue;
        }
        $servers[] = ['urls' => $normalizedUri];
    }

    return $servers;
}

function videochat_turn_secret_value(): string
{
    $secret = trim((string) (getenv('VIDEOCHAT_TURN_STATIC_AUTH_SECRET') ?: ''));
    if ($secret !== '') {
        return $secret;
    }

    $secretFile = trim((string) (getenv('VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE') ?: ''));
    if ($secretFile === '' || !is_file($secretFile) || !is_readable($secretFile)) {
        return '';
    }

    return trim((string) file_get_contents($secretFile));
}

/**
 * @return array<int, string>
 */
function videochat_turn_uri_values(): array
{
    $raw = trim((string) (getenv('VIDEOCHAT_TURN_URIS') ?: ''));
    if ($raw === '') {
        return [];
    }

    $uris = [];
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $uri) {
        $normalizedUri = trim((string) $uri);
        if ($normalizedUri === '' || preg_match('/^turns?:/i', $normalizedUri) !== 1) {
            continue;
        }
        $uris[$normalizedUri] = true;
    }

    return array_keys($uris);
}

function videochat_turn_ttl_seconds(): int
{
    $rawTtl = trim((string) (getenv('VIDEOCHAT_TURN_TTL_SECONDS') ?: '3600'));
    if (preg_match('/^[0-9]+$/', $rawTtl) !== 1) {
        return 3600;
    }

    return max(60, min(86400, (int) $rawTtl));
}

/**
 * @return array{
 *   enabled: bool,
 *   ttl_seconds: int,
 *   expires_at: string,
 *   ice_servers: array<int, array<string, mixed>>
 * }
 */
function videochat_turn_ice_server_payload(?int $now = null): array
{
    $effectiveNow = is_int($now) && $now > 0 ? $now : time();
    $ttlSeconds = videochat_turn_ttl_seconds();
    $expiresAt = $effectiveNow + $ttlSeconds;
    $servers = videochat_turn_static_stun_servers();

    $secret = videochat_turn_secret_value();
    $turnUris = videochat_turn_uri_values();
    if ($secret !== '' && strlen($secret) >= 16 && $turnUris !== []) {
        $usernamePrefix = trim((string) (getenv('VIDEOCHAT_TURN_USERNAME_PREFIX') ?: 'king-videochat'));
        if ($usernamePrefix === '' || preg_match('/^[A-Za-z0-9._:-]+$/', $usernamePrefix) !== 1) {
            $usernamePrefix = 'king-videochat';
        }

        $username = (string) $expiresAt . ':' . $usernamePrefix;
        $credential = base64_encode(hash_hmac('sha1', $username, $secret, true));
        foreach ($turnUris as $turnUri) {
            $servers[] = [
                'urls' => $turnUri,
                'username' => $username,
                'credential' => $credential,
            ];
        }
    }

    return [
        'enabled' => count($servers) > count(videochat_turn_static_stun_servers()),
        'ttl_seconds' => $ttlSeconds,
        'expires_at' => gmdate('c', $expiresAt),
        'ice_servers' => $servers,
    ];
}
