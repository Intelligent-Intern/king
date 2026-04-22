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
    putenv('VIDEOCHAT_DEPLOY_CDN_DOMAIN=cnd.video.example.test');
    putenv('VIDEOCHAT_INFRA_NODE_ROLES=edge,http,ws,sfu');
    putenv('VIDEOCHAT_OTEL_ENABLE=1');
    putenv('VIDEOCHAT_OTEL_EXPORTER_ENDPOINT=http://otel-collector:4317');
    putenv('VIDEOCHAT_OTEL_METRICS_ENABLE=1');
    putenv('VIDEOCHAT_OTEL_LOGS_ENABLE=1');

    $adapters = videochat_infra_provider_adapters();
    foreach (['hetzner', 'kubernetes', 'static'] as $adapterId) {
        videochat_admin_infra_assert(isset($adapters[$adapterId]), "missing provider adapter {$adapterId}");
        videochat_admin_infra_assert(is_callable($adapters[$adapterId]['inventory'] ?? null), "provider adapter {$adapterId} inventory must be callable");
        videochat_admin_infra_assert(is_array($adapters[$adapterId]['modes'] ?? null), "provider adapter {$adapterId} modes must be declared");
    }
    videochat_admin_infra_assert(
        in_array('kubernetes', (array) ($adapters['kubernetes']['modes'] ?? []), true),
        'kubernetes adapter must be selectable without changing endpoint code'
    );

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
    videochat_admin_infra_assert((string) (($payload['deployment'] ?? [])['cdn_domain'] ?? '') === 'cnd.video.example.test', 'CDN domain mismatch');
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

    putenv('VIDEOCHAT_INFRA_PROVIDER=kubernetes');
    putenv('VIDEOCHAT_INFRA_KUBERNETES_ENABLE=1');
    putenv('HOSTNAME=pod-contract-1');
    $kubernetesResponse = videochat_handle_infrastructure_routes(
        '/api/admin/infrastructure',
        'GET',
        $jsonResponse,
        $errorResponse
    );
    videochat_admin_infra_assert(is_array($kubernetesResponse), 'kubernetes provider response should be handled');
    $kubernetesPayload = videochat_admin_infra_decode($kubernetesResponse);
    $providerIds = array_map(
        static fn (array $provider): string => (string) ($provider['id'] ?? ''),
        array_filter((array) ($kubernetesPayload['providers'] ?? []), 'is_array')
    );
    videochat_admin_infra_assert(in_array('kubernetes', $providerIds, true), 'kubernetes provider should be reported by adapter');
    videochat_admin_infra_assert(in_array('static', $providerIds, true), 'static provider should remain the safe node fallback');
    videochat_admin_infra_assert(count((array) ($kubernetesPayload['nodes'] ?? [])) === 1, 'kubernetes mode should keep static node fallback until node reader exists');
    foreach ((array) ($kubernetesPayload['providers'] ?? []) as $provider) {
        if (!is_array($provider) || (string) ($provider['id'] ?? '') !== 'kubernetes') {
            continue;
        }
        videochat_admin_infra_assert((string) ($provider['status'] ?? '') === 'detected', 'kubernetes provider should be detected when enabled');
        videochat_admin_infra_assert(
            (bool) (($provider['capabilities'] ?? [])['scale_sfu_deployment'] ?? false) === true,
            'kubernetes adapter should report future SFU scaling capability'
        );
    }

    fwrite(STDOUT, "[admin-infrastructure-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[admin-infrastructure-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
