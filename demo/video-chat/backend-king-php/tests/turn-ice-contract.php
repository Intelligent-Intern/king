<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/turn_ice.php';

function videochat_turn_ice_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[turn-ice-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_turn_ice_contract_env(string $name, ?string $value): void
{
    if ($value === null) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
        return;
    }

    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

try {
    foreach ([
        'VIDEOCHAT_STUN_URIS',
        'VIDEOCHAT_STUN_DEFAULT_DOMAIN',
        'VIDEOCHAT_STUN_DOMAIN',
        'VIDEOCHAT_TURN_STATIC_AUTH_SECRET',
        'VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE',
        'VIDEOCHAT_TURN_URIS',
        'VIDEOCHAT_TURN_TTL_SECONDS',
        'VIDEOCHAT_TURN_USERNAME_PREFIX',
        'VIDEOCHAT_DEPLOY_TURN_DOMAIN',
        'VIDEOCHAT_EDGE_TURN_DOMAIN',
        'VIDEOCHAT_V1_TURN_REALM',
        'TURN_DOMAIN',
        'VIDEOCHAT_V1_PUBLIC_HOST',
        'VIDEOCHAT_EDGE_DOMAIN',
    ] as $name) {
        videochat_turn_ice_contract_env($name, null);
    }

    videochat_turn_ice_contract_env('VIDEOCHAT_DEPLOY_TURN_DOMAIN', 'turn.example.test');
    $stunOnly = videochat_turn_ice_server_payload(1_000);
    videochat_turn_ice_contract_assert(($stunOnly['enabled'] ?? true) === false, 'STUN-only payload must report TURN disabled');
    videochat_turn_ice_contract_assert((array) ($stunOnly['ice_servers'] ?? []) === [['urls' => 'stun:turn.example.test:3478']], 'STUN fallback must prefer the deployment TURN host');

    videochat_turn_ice_contract_env('VIDEOCHAT_TURN_STATIC_AUTH_SECRET', 'contract-secret-1234567890');
    videochat_turn_ice_contract_env('VIDEOCHAT_TURN_URIS', 'turn:turn.example.test:3478?transport=udp,turn:turn.example.test:3478?transport=tcp');
    videochat_turn_ice_contract_env('VIDEOCHAT_TURN_TTL_SECONDS', '3600');

    $withTurn = videochat_turn_ice_server_payload(1_000);
    $servers = (array) ($withTurn['ice_servers'] ?? []);
    $turnServers = array_values(array_filter($servers, static function (mixed $entry): bool {
        return is_array($entry) && str_starts_with((string) ($entry['urls'] ?? ''), 'turn:');
    }));

    videochat_turn_ice_contract_assert(($withTurn['enabled'] ?? false) === true, 'TURN payload must report enabled');
    videochat_turn_ice_contract_assert((int) ($withTurn['ttl_seconds'] ?? 0) === 3600, 'TURN TTL mismatch');
    videochat_turn_ice_contract_assert((string) ($withTurn['expires_at'] ?? '') === '1970-01-01T01:16:40+00:00', 'TURN expiry mismatch');
    videochat_turn_ice_contract_assert(count($turnServers) === 2, 'TURN payload must include UDP and TCP transports');

    foreach ($turnServers as $turnServer) {
        videochat_turn_ice_contract_assert((string) ($turnServer['username'] ?? '') === '4600:king-videochat', 'TURN username mismatch');
        videochat_turn_ice_contract_assert((string) ($turnServer['credential'] ?? '') !== '', 'TURN credential missing');
    }

    $tmpSecret = tempnam(sys_get_temp_dir(), 'videochat-turn-secret-');
    videochat_turn_ice_contract_assert(is_string($tmpSecret) && $tmpSecret !== '', 'could not create temporary secret file');
    file_put_contents($tmpSecret, "file-secret-1234567890\n");
    videochat_turn_ice_contract_env('VIDEOCHAT_TURN_STATIC_AUTH_SECRET', null);
    videochat_turn_ice_contract_env('VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE', $tmpSecret);

    $fromFile = videochat_turn_ice_server_payload(1_000);
    $fromFileServers = array_values(array_filter((array) ($fromFile['ice_servers'] ?? []), static function (mixed $entry): bool {
        return is_array($entry) && str_starts_with((string) ($entry['urls'] ?? ''), 'turn:');
    }));
    videochat_turn_ice_contract_assert(count($fromFileServers) === 2, 'TURN secret file must generate TURN servers');
    @unlink($tmpSecret);

    fwrite(STDOUT, "[turn-ice-contract] PASS\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "[turn-ice-contract] FAIL: {$exception->getMessage()}\n");
    exit(1);
}
