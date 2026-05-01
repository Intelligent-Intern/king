<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/module_runtime.php';

function videochat_runtime_public_safety_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[runtime-public-safety-contract] FAIL: {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function videochat_runtime_public_safety_decode(array $response): array
{
    $body = $response['body'] ?? '';
    videochat_runtime_public_safety_assert(is_string($body) && trim($body) !== '', 'response body must be JSON');
    try {
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        fwrite(STDERR, "[runtime-public-safety-contract] FAIL: invalid JSON: {$error->getMessage()}\n");
        exit(1);
    }

    videochat_runtime_public_safety_assert(is_array($decoded), 'decoded response must be an object');
    return $decoded;
}

function videochat_runtime_public_safety_contains_key(mixed $value, string $needle): bool
{
    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $key => $child) {
        if ((string) $key === $needle) {
            return true;
        }
        if (videochat_runtime_public_safety_contains_key($child, $needle)) {
            return true;
        }
    }

    return false;
}

function videochat_runtime_public_safety_assert_public_payload(array $payload, string $path): void
{
    $keys = array_keys($payload);
    sort($keys);
    videochat_runtime_public_safety_assert($keys === ['asset_version', 'service', 'status', 'time'], "{$path} must stay on the public health allow-list");
    videochat_runtime_public_safety_assert((string) ($payload['asset_version'] ?? '') === '20260430060000', "{$path} asset version mismatch");
    videochat_runtime_public_safety_assert((string) ($payload['service'] ?? '') === 'video-chat-backend-king-php', "{$path} service mismatch");
    videochat_runtime_public_safety_assert((string) ($payload['status'] ?? '') === 'ok', "{$path} status mismatch");

    foreach ([
        'active_runtime_count',
        'app',
        'auth',
        'calls',
        'database',
        'demo_users',
        'migrations_applied',
        'path',
        'permission_matrix',
        'runtime',
        'schema_version',
        'table_names',
        'upload_limits',
    ] as $forbiddenKey) {
        videochat_runtime_public_safety_assert(
            !videochat_runtime_public_safety_contains_key($payload, $forbiddenKey),
            "{$path} must not expose {$forbiddenKey}"
        );
    }
}

$jsonResponse = static function (int $status, array $payload): array {
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
};

$runtimeEnvelope = static function (): array {
    return [
        'service' => 'video-chat-backend-king-php',
        'app' => [
            'name' => 'king-video-chat-backend',
            'version' => '1.0.7-beta',
            'environment' => 'production',
        ],
        'runtime' => [
            'king_version' => '1.0.6',
            'transport' => 'king_http1_server_listen_once',
            'ws_path' => '/ws',
            'health' => [
                'module_status' => 'ok',
                'system_status' => 'not_initialized',
                'build' => 'v1',
                'module_version' => '1.0.6',
                'active_runtime_count' => 30,
            ],
        ],
        'database' => [
            'path' => '/data/video-chat.sqlite',
            'schema_version' => 18,
            'migrations_applied' => 18,
            'table_names' => ['users', 'sessions', 'calls'],
            'demo_users' => [
                ['email' => 'admin@example.test', 'display_name' => 'Admin', 'role' => 'admin'],
            ],
        ],
        'auth' => [
            'rbac' => [
                'permission_matrix' => [['id' => 'rest_admin_scope']],
            ],
            'upload_limits' => [
                'chat_object_store_max_bytes' => 2147483648,
            ],
        ],
        'calls' => [
            'list_endpoint' => '/api/calls',
        ],
        'time' => '2026-04-21T05:24:13+00:00',
    ];
};

foreach (['/health', '/api/runtime'] as $path) {
    putenv('VIDEOCHAT_ASSET_VERSION=20260430060000');
    $response = videochat_handle_runtime_routes($path, 'GET', $jsonResponse, $runtimeEnvelope, '/ws');
    videochat_runtime_public_safety_assert(is_array($response), "{$path} should be handled");
    videochat_runtime_public_safety_assert((int) ($response['status'] ?? 0) === 200, "{$path} should return 200");
    videochat_runtime_public_safety_assert_public_payload(videochat_runtime_public_safety_decode($response), $path);
}

$adminResponse = videochat_handle_runtime_routes('/api/admin/runtime', 'GET', $jsonResponse, $runtimeEnvelope, '/ws');
videochat_runtime_public_safety_assert(is_array($adminResponse), 'admin runtime should be handled');
videochat_runtime_public_safety_assert((int) ($adminResponse['status'] ?? 0) === 200, 'admin runtime should return 200');
$adminPayload = videochat_runtime_public_safety_decode($adminResponse);
videochat_runtime_public_safety_assert(isset($adminPayload['database']['schema_version']), 'authorized admin runtime should keep schema diagnostics');
videochat_runtime_public_safety_assert(isset($adminPayload['auth']['rbac']['permission_matrix']), 'authorized admin runtime should keep RBAC diagnostics');
videochat_runtime_public_safety_assert(isset($adminPayload['runtime']['health']['active_runtime_count']), 'authorized admin runtime should keep runtime diagnostics');

fwrite(STDOUT, "[runtime-public-safety-contract] PASS\n");
