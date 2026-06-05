<?php

declare(strict_types=1);

require_once __DIR__ . '/call_access.php';
require_once __DIR__ . '/../../support/auth_request.php';

function videochat_sputnik_env_int(string $name, int $default, int $min, int $max): int
{
    $raw = getenv($name);
    if (!is_string($raw) || trim($raw) === '' || !is_numeric($raw)) {
        return $default;
    }

    return max($min, min($max, (int) $raw));
}

/**
 * @return array{
 *   enabled: bool,
 *   runner_url: string,
 *   app_origin: string,
 *   default_count: int,
 *   max_count: int,
 *   fps: int,
 *   width: int,
 *   height: int,
 *   batch_size: int,
 *   timeout_ms: int,
 *   stale_join_ms: int
 * }
 */
function videochat_sputnik_config(): array
{
    $runnerUrl = rtrim(trim((string) (getenv('VIDEOCHAT_SPUTNIK_RUNNER_URL') ?: 'http://videochat-sputnik-runner-v1:19090')), '/');
    $appOrigin = rtrim(trim((string) (getenv('VIDEOCHAT_SPUTNIK_APP_ORIGIN') ?: getenv('VIDEOCHAT_FRONTEND_ORIGIN') ?: '')), '/');
    if ($appOrigin === '') {
        $scheme = trim((string) (getenv('VIDEOCHAT_V1_PUBLIC_SCHEME') ?: 'http'));
        $host = trim((string) (getenv('VIDEOCHAT_DEPLOY_APP_DOMAIN') ?: getenv('VIDEOCHAT_V1_PUBLIC_HOST') ?: '127.0.0.1'));
        $port = trim((string) (getenv('VIDEOCHAT_V1_FRONTEND_PORT') ?: ''));
        $appOrigin = $scheme . '://' . $host;
        if ($port !== '' && !in_array($port, ['80', '443'], true) && !str_contains($host, ':')) {
            $appOrigin .= ':' . $port;
        }
    }

    return [
        'enabled' => !in_array(strtolower(trim((string) (getenv('VIDEOCHAT_SPUTNIK_RUNNER_ENABLED') ?: '1'))), ['0', 'false', 'off', 'no'], true),
        'runner_url' => $runnerUrl,
        'app_origin' => $appOrigin,
        'default_count' => videochat_sputnik_env_int('VIDEOCHAT_SPUTNIK_DEFAULT_COUNT', 10, 1, 25),
        'max_count' => videochat_sputnik_env_int('VIDEOCHAT_SPUTNIK_MAX_COUNT', 25, 1, 50),
        'fps' => videochat_sputnik_env_int('VIDEOCHAT_SPUTNIK_FPS', 10, 1, 30),
        'width' => videochat_sputnik_env_int('VIDEOCHAT_SPUTNIK_WIDTH', 640, 160, 1920),
        'height' => videochat_sputnik_env_int('VIDEOCHAT_SPUTNIK_HEIGHT', 360, 120, 1080),
        'batch_size' => videochat_sputnik_env_int('VIDEOCHAT_SPUTNIK_BATCH_SIZE', 3, 1, 8),
        'timeout_ms' => videochat_sputnik_env_int('VIDEOCHAT_SPUTNIK_TIMEOUT_MS', 90000, 10000, 300000),
        'stale_join_ms' => videochat_sputnik_env_int('VIDEOCHAT_SPUTNIK_STALE_JOIN_MS', 180000, 30000, 1800000),
    ];
}

function videochat_sputnik_can_control(PDO $pdo, int $userId): bool
{
    return videochat_user_is_superadmin($pdo, $userId);
}

function videochat_sputnik_absolute_join_url(string $joinPath, array $config): string
{
    $trimmed = trim($joinPath);
    if ($trimmed === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $trimmed) === 1) {
        return $trimmed;
    }

    $origin = rtrim((string) ($config['app_origin'] ?? ''), '/');
    if ($origin === '') {
        return $trimmed;
    }

    return $origin . '/' . ltrim($trimmed, '/');
}

/**
 * @return array{ok: bool, status: int, body: ?array<string, mixed>, raw: string, reason: string}
 */
function videochat_sputnik_runner_json(string $method, string $url, ?array $payload = null, int $timeoutSeconds = 5): array
{
    $headers = "Accept: application/json\r\n";
    $content = '';
    if ($payload !== null) {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'reason' => 'json_encode_failed'];
        }
        $content = $encoded;
        $headers .= "Content-Type: application/json\r\n";
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => max(1, $timeoutSeconds),
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw)) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'reason' => 'runner_unreachable'];
    }

    $status = 0;
    $responseHeaders = is_array($http_response_header ?? null) ? $http_response_header : [];
    foreach ($responseHeaders as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }

    $decoded = json_decode($raw, true);
    return [
        'ok' => $status >= 200 && $status < 300 && is_array($decoded),
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => $raw,
        'reason' => is_array($decoded) ? 'ok' : 'invalid_runner_json',
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_sputnik_start(PDO $pdo, string $callId, int $authUserId, string $authRole, array $payload, ?int $tenantId = null): array
{
    if (!videochat_sputnik_can_control($pdo, $authUserId)) {
        return ['ok' => false, 'reason' => 'forbidden', 'status' => 403, 'runner' => null];
    }

    $config = videochat_sputnik_config();
    if (!(bool) ($config['enabled'] ?? false) || trim((string) ($config['runner_url'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'runner_disabled', 'status' => 503, 'runner' => null];
    }

    $accessResult = videochat_create_call_access_link_for_user($pdo, $callId, $authUserId, $authRole, ['link_kind' => 'open'], $tenantId);
    if (!(bool) ($accessResult['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => (string) ($accessResult['reason'] ?? 'access_link_failed'),
            'status' => (string) ($accessResult['reason'] ?? '') === 'not_found' ? 404 : 403,
            'runner' => null,
        ];
    }

    $accessId = strtolower(trim((string) (($accessResult['access_link']['id'] ?? ''))));
    $joinUrl = videochat_sputnik_absolute_join_url('/join/' . $accessId, $config);
    if ($accessId === '' || $joinUrl === '') {
        return ['ok' => false, 'reason' => 'access_link_invalid', 'status' => 500, 'runner' => null];
    }

    $maxCount = max(1, (int) ($config['max_count'] ?? 25));
    $count = (int) ($payload['count'] ?? $config['default_count']);
    $count = max(1, min($maxCount, $count));
    $runnerPayload = [
        'batch_size' => (int) ($config['batch_size'] ?? 3),
        'call_id' => $callId,
        'count' => $count,
        'fps' => (int) ($config['fps'] ?? 10),
        'height' => (int) ($config['height'] ?? 360),
        'join_url' => $joinUrl,
        'stale_join_ms' => (int) ($config['stale_join_ms'] ?? 180000),
        'timeout_ms' => (int) ($config['timeout_ms'] ?? 90000),
        'width' => (int) ($config['width'] ?? 640),
    ];

    $runnerUrl = rtrim((string) ($config['runner_url'] ?? ''), '/') . '/jobs/' . rawurlencode($callId);
    $runner = videochat_sputnik_runner_json('POST', $runnerUrl, $runnerPayload, 5);
    if (!(bool) ($runner['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => (string) ($runner['reason'] ?? 'runner_failed'),
            'status' => (int) ($runner['status'] ?? 0) >= 400 ? (int) $runner['status'] : 503,
            'runner' => $runner,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'started',
        'status' => (int) ($runner['status'] ?? 202),
        'join_url' => $joinUrl,
        'runner' => $runner,
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_sputnik_runner_action(PDO $pdo, string $method, string $callId, int $authUserId): array
{
    if (!videochat_sputnik_can_control($pdo, $authUserId)) {
        return ['ok' => false, 'reason' => 'forbidden', 'status' => 403, 'runner' => null];
    }

    $config = videochat_sputnik_config();
    if (!(bool) ($config['enabled'] ?? false) || trim((string) ($config['runner_url'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'runner_disabled', 'status' => 503, 'runner' => null];
    }

    $runnerUrl = rtrim((string) ($config['runner_url'] ?? ''), '/') . '/jobs/' . rawurlencode($callId);
    $runner = videochat_sputnik_runner_json($method, $runnerUrl, null, 5);
    $status = (int) ($runner['status'] ?? 0);
    if ($method === 'GET' && $status === 404) {
        return ['ok' => true, 'reason' => 'not_running', 'status' => 200, 'runner' => $runner];
    }

    if (!(bool) ($runner['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => (string) ($runner['reason'] ?? 'runner_failed'),
            'status' => $status >= 400 ? $status : 503,
            'runner' => $runner,
        ];
    }

    return ['ok' => true, 'reason' => $method === 'DELETE' ? 'stopped' : 'loaded', 'status' => $status, 'runner' => $runner];
}
