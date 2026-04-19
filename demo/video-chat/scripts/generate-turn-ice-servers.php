<?php
declare(strict_types=1);

function videochat_turn_env(string $name): string
{
    $value = getenv($name);
    return is_string($value) ? trim($value) : '';
}

function videochat_turn_fail(string $message): never
{
    fwrite(STDERR, "[turn-ice-generator] {$message}\n");
    exit(1);
}

function videochat_turn_secret(): string
{
    $secret = videochat_turn_env('VIDEOCHAT_TURN_STATIC_AUTH_SECRET');
    if ($secret !== '') {
        return $secret;
    }

    $secretFile = videochat_turn_env('VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE');
    if ($secretFile === '') {
        videochat_turn_fail('missing VIDEOCHAT_TURN_STATIC_AUTH_SECRET or VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE');
    }

    if (!is_file($secretFile) || !is_readable($secretFile)) {
        videochat_turn_fail("secret file is not readable: {$secretFile}");
    }

    $fileSecret = trim((string) file_get_contents($secretFile));
    if ($fileSecret === '') {
        videochat_turn_fail("secret file is empty: {$secretFile}");
    }

    return $fileSecret;
}

function videochat_turn_positive_int(string $name, int $fallback, int $min, int $max): int
{
    $raw = videochat_turn_env($name);
    if ($raw === '') {
        return $fallback;
    }

    if (!preg_match('/^[0-9]+$/', $raw)) {
        videochat_turn_fail("{$name} must be an integer");
    }

    $value = (int) $raw;
    if ($value < $min || $value > $max) {
        videochat_turn_fail("{$name} must be between {$min} and {$max}");
    }

    return $value;
}

function videochat_turn_urls(): array
{
    $raw = videochat_turn_env('VIDEOCHAT_TURN_URIS');
    if ($raw === '') {
        videochat_turn_fail('missing VIDEOCHAT_TURN_URIS');
    }

    $urls = [];
    foreach (explode(',', $raw) as $entry) {
        $url = trim($entry);
        if ($url === '') {
            continue;
        }
        if (!preg_match('/^turns?:/i', $url)) {
            videochat_turn_fail("TURN URI must start with turn: or turns:: {$url}");
        }
        $urls[] = $url;
    }

    if ($urls === []) {
        videochat_turn_fail('VIDEOCHAT_TURN_URIS did not contain a usable TURN URI');
    }

    return array_values(array_unique($urls));
}

$secret = videochat_turn_secret();
if (strlen($secret) < 16) {
    videochat_turn_fail('TURN static auth secret must be at least 16 characters');
}

$ttlSeconds = videochat_turn_positive_int('VIDEOCHAT_TURN_TTL_SECONDS', 3600, 60, 86400);
$now = videochat_turn_positive_int('VIDEOCHAT_TURN_NOW', time(), 1, PHP_INT_MAX);
$usernamePrefix = videochat_turn_env('VIDEOCHAT_TURN_USERNAME_PREFIX');
if ($usernamePrefix === '') {
    $usernamePrefix = 'king-videochat';
}
if (!preg_match('/^[A-Za-z0-9._:-]+$/', $usernamePrefix)) {
    videochat_turn_fail('VIDEOCHAT_TURN_USERNAME_PREFIX contains unsupported characters');
}

$username = (string) ($now + $ttlSeconds) . ':' . $usernamePrefix;
$credential = base64_encode(hash_hmac('sha1', $username, $secret, true));
$servers = [];

foreach (videochat_turn_urls() as $url) {
    $servers[] = [
        'urls' => $url,
        'username' => $username,
        'credential' => $credential,
    ];
}

echo json_encode($servers, JSON_UNESCAPED_SLASHES) . "\n";
