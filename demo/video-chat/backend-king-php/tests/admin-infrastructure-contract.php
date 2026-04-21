<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/module_infrastructure.php';

function videochat_admin_infra_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[admin-infrastructure-contract] FAIL: {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function videochat_admin_infra_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

try {
    putenv('VIDEOCHAT_INFRA_PROVIDER=static');
    putenv('VIDEOCHAT_INFRA_CLUSTER_NAME=contract-cluster');
    putenv('VIDEOCHAT_INFRA_PUBLIC_DOMAIN=video.example.test');
    putenv('VIDEOCHAT_DEPLOY_API_DOMAIN=api.video.example.test');
    putenv('VIDEOCHAT_DEPLOY_WS_DOMAIN=ws.video.example.test');
    putenv('VIDEOCHAT_DEPLOY_SFU_DOMAIN=sfu.video.example.test');
    putenv('VIDEOCHAT_DEPLOY_TURN_DOMAIN=turn.video.example.test');
    putenv('VIDEOCHAT_INFRA_NODE_ROLES=edge,http,ws,sfu');
    putenv('VIDEOCHAT_OTEL_ENABLE=1');
    putenv('VIDEOCHAT_OTEL_EXPORTER_ENDPOINT=http://otel-collector:4317');
    putenv('VIDEOCHAT_OTEL_METRICS_ENABLE=1');
    putenv('VIDEOCHAT_OTEL_LOGS_ENABLE=1');

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, [
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'time' => gmdate('c'),
        ]);
    };

    $methodBlocked = videochat_handle_infrastructure_routes(
        '/api/admin/infrastructure',
        'POST',
        $jsonResponse,
        $errorResponse
    );
    videochat_admin_infra_assert(is_array($methodBlocked), 'POST should be handled');
    videochat_admin_infra_assert((int) ($methodBlocked['status'] ?? 0) === 405, 'POST should be rejected with 405');

    $unrelated = videochat_handle_infrastructure_routes('/api/admin/users', 'GET', $jsonResponse, $errorResponse);
    videochat_admin_infra_assert($unrelated === null, 'unrelated route should not be handled');

    $response = videochat_handle_infrastructure_routes(
        '/api/admin/infrastructure',
        'GET',
        $jsonResponse,
        $errorResponse
    );
    videochat_admin_infra_assert(is_array($response), 'GET should be handled');
    videochat_admin_infra_assert((int) ($response['status'] ?? 0) === 200, 'GET should return 200');
    $payload = videochat_admin_infra_decode($response);

    videochat_admin_infra_assert((string) ($payload['status'] ?? '') === 'ok', 'payload status mismatch');
    videochat_admin_infra_assert((string) (($payload['deployment'] ?? [])['name'] ?? '') === 'contract-cluster', 'deployment name mismatch');
    videochat_admin_infra_assert((string) (($payload['deployment'] ?? [])['sfu_domain'] ?? '') === 'sfu.video.example.test', 'SFU domain mismatch');
    videochat_admin_infra_assert(count((array) ($payload['nodes'] ?? [])) === 1, 'static inventory should expose one node');
    videochat_admin_infra_assert(count((array) ($payload['services'] ?? [])) >= 4, 'static inventory should expose core services');
    videochat_admin_infra_assert((bool) (($payload['telemetry']['open_telemetry'] ?? [])['enabled'] ?? false), 'OTel should be enabled');
    videochat_admin_infra_assert(
        (string) (($payload['telemetry']['open_telemetry'] ?? [])['exporter_endpoint'] ?? '') === '[configured]',
        'OTel endpoint must not expose the configured URL'
    );
    videochat_admin_infra_assert(
        (bool) (($payload['scaling'] ?? [])['write_actions_enabled'] ?? true) === false,
        'scaling write actions must be disabled in inventory contract'
    );

    fwrite(STDOUT, "[admin-infrastructure-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[admin-infrastructure-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
