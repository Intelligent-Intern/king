<?php

declare(strict_types=1);

function videochat_infra_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if (!is_string($value)) {
        return $default;
    }

    $value = trim($value);
    return $value === '' ? $default : $value;
}

function videochat_infra_env_bool(string $name, bool $default = false): bool
{
    $value = strtolower(videochat_infra_env($name, $default ? '1' : '0'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/** @return array<int, string> */
function videochat_infra_csv(string $value, array $fallback = []): array
{
    $items = array_values(array_filter(array_map(
        static fn ($item): string => trim((string) $item),
        explode(',', $value)
    ), static fn (string $item): bool => $item !== ''));

    return $items === [] ? $fallback : $items;
}

function videochat_infra_public_domain(): string
{
    return videochat_infra_env(
        'VIDEOCHAT_INFRA_PUBLIC_DOMAIN',
        videochat_infra_env('VIDEOCHAT_DEPLOY_DOMAIN', videochat_infra_env('VIDEOCHAT_V1_PUBLIC_HOST', 'local'))
    );
}

/** @return array<string, mixed> */
function videochat_infra_deployment_snapshot(): array
{
    $domain = videochat_infra_public_domain();
    $deploymentId = videochat_infra_env(
        'VIDEOCHAT_INFRA_DEPLOYMENT_ID',
        preg_replace('/[^a-z0-9-]+/', '-', strtolower($domain)) ?: 'king-videochat'
    );

    return [
        'id' => $deploymentId,
        'name' => videochat_infra_env('VIDEOCHAT_INFRA_CLUSTER_NAME', $deploymentId),
        'environment' => videochat_infra_env('VIDEOCHAT_INFRA_ENVIRONMENT', videochat_infra_env('VIDEOCHAT_APP_ENV', 'production')),
        'public_domain' => $domain,
        'api_domain' => videochat_infra_env('VIDEOCHAT_DEPLOY_API_DOMAIN', 'api.' . $domain),
        'ws_domain' => videochat_infra_env('VIDEOCHAT_DEPLOY_WS_DOMAIN', 'ws.' . $domain),
        'sfu_domain' => videochat_infra_env('VIDEOCHAT_DEPLOY_SFU_DOMAIN', 'sfu.' . $domain),
        'turn_domain' => videochat_infra_env('VIDEOCHAT_DEPLOY_TURN_DOMAIN', 'turn.' . $domain),
        'inventory_mode' => videochat_infra_env('VIDEOCHAT_INFRA_PROVIDER', 'auto'),
    ];
}

/** @return array<string, mixed> */
function videochat_infra_provider_row(
    string $id,
    string $label,
    string $type,
    bool $configured,
    string $status,
    array $capabilities = [],
    array $details = []
): array {
    return [
        'id' => $id,
        'label' => $label,
        'type' => $type,
        'configured' => $configured,
        'status' => $status,
        'capabilities' => $capabilities,
        'details' => $details,
    ];
}

/** @return array<string, mixed> */
function videochat_infra_service_row(
    string $id,
    string $nodeId,
    string $kind,
    string $label,
    string $status,
    string $endpoint = '',
    array $details = []
): array {
    return [
        'id' => $id,
        'node_id' => $nodeId,
        'kind' => $kind,
        'label' => $label,
        'status' => $status,
        'endpoint' => $endpoint,
        'details' => $details,
    ];
}

/** @return array<int, array<string, mixed>> */
function videochat_infra_default_services_for_node(string $nodeId, array $deployment, array $roles, string $status): array
{
    $serviceStatus = $status === 'running' || $status === 'healthy' ? 'healthy' : 'unknown';
    $services = [];
    if (in_array('edge', $roles, true)) {
        $services[] = videochat_infra_service_row('edge:' . $nodeId, $nodeId, 'king-edge', 'King HTTPS edge', $serviceStatus, 'https://' . (string) $deployment['public_domain']);
    }
    if (in_array('http', $roles, true)) {
        $services[] = videochat_infra_service_row('http:' . $nodeId, $nodeId, 'king-http', 'King API', $serviceStatus, 'https://' . (string) $deployment['api_domain'] . '/health', [
            'workers' => (int) videochat_infra_env('VIDEOCHAT_V1_HTTP_WORKERS', '24'),
        ]);
    }
    if (in_array('ws', $roles, true)) {
        $services[] = videochat_infra_service_row('ws:' . $nodeId, $nodeId, 'king-ws', 'Lobby websocket', $serviceStatus, 'wss://' . (string) $deployment['ws_domain'] . '/ws', [
            'workers' => (int) videochat_infra_env('VIDEOCHAT_V1_WS_WORKERS', '8'),
        ]);
    }
    if (in_array('sfu', $roles, true)) {
        $services[] = videochat_infra_service_row('sfu:' . $nodeId, $nodeId, 'king-sfu', 'SFU websocket', $serviceStatus, 'wss://' . (string) $deployment['sfu_domain'] . '/sfu', [
            'workers' => (int) videochat_infra_env('VIDEOCHAT_V1_SFU_WORKERS', '8'),
        ]);
    }
    if (in_array('turn', $roles, true)) {
        $services[] = videochat_infra_service_row('turn:' . $nodeId, $nodeId, 'turn', 'TURN relay', 'configured', (string) $deployment['turn_domain']);
    }

    return $services;
}

/** @return array<string, mixed> */
function videochat_infra_node_row(
    string $id,
    string $name,
    string $provider,
    array $roles,
    string $status,
    string $region = '',
    string $publicIpv4 = '',
    array $resources = [],
    array $labels = []
): array {
    $normalizedRoles = array_values(array_unique(array_filter($roles, static fn ($role): bool => is_string($role) && trim($role) !== '')));
    $health = in_array($status, ['running', 'healthy'], true) ? 'healthy' : ($status === 'unknown' ? 'unknown' : 'warning');

    return [
        'id' => $id,
        'name' => $name,
        'provider' => $provider,
        'roles' => $normalizedRoles,
        'status' => $status,
        'health' => $health,
        'region' => $region,
        'public_ipv4' => $publicIpv4,
        'resources' => $resources,
        'labels' => $labels,
    ];
}

/** @return array{ok: bool, status: int, payload: array<string, mixed>|null, error: string} */
function videochat_infra_http_json(string $url, array $headers, float $timeoutSeconds = 2.5): array
{
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headerLines),
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', (string) $line, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }

    if (!is_string($body)) {
        return ['ok' => false, 'status' => $status, 'payload' => null, 'error' => 'request_failed'];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => $status, 'payload' => null, 'error' => 'invalid_json'];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'payload' => $decoded,
        'error' => $status >= 200 && $status < 300 ? '' : 'http_' . $status,
    ];
}

/** @return array<int, string> */
function videochat_infra_hcloud_roles_for_server(array $server): array
{
    $labels = is_array($server['labels'] ?? null) ? $server['labels'] : [];
    $roleText = '';
    foreach (['king_role', 'videochat_role', 'role'] as $labelKey) {
        if (is_string($labels[$labelKey] ?? null) && trim((string) $labels[$labelKey]) !== '') {
            $roleText = (string) $labels[$labelKey];
            break;
        }
    }

    if ($roleText !== '') {
        return videochat_infra_csv($roleText, ['edge', 'http', 'ws', 'sfu']);
    }

    $name = strtolower((string) ($server['name'] ?? ''));
    if (str_contains($name, 'sfu')) {
        return ['sfu'];
    }

    return videochat_infra_csv(videochat_infra_env('VIDEOCHAT_INFRA_NODE_ROLES', 'edge,http,ws,sfu'), ['edge', 'http', 'ws', 'sfu']);
}

/** @return array{provider: array<string, mixed>, nodes: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>} */
function videochat_infra_hcloud_inventory(array $deployment): array
{
    $token = videochat_infra_env('VIDEOCHAT_INFRA_HETZNER_TOKEN', videochat_infra_env('VIDEOCHAT_DEPLOY_HCLOUD_TOKEN'));
    $base = rtrim(videochat_infra_env('VIDEOCHAT_INFRA_HETZNER_API_BASE', videochat_infra_env('VIDEOCHAT_DEPLOY_HCLOUD_API_BASE', 'https://api.hetzner.cloud/v1')), '/');
    if ($token === '') {
        return [
            'provider' => videochat_infra_provider_row('hetzner', 'Hetzner Cloud', 'cloud', false, 'not_configured', [
                'list_nodes' => false,
                'spawn_sfu_instance' => false,
            ], [
                'api_base' => $base,
                'reason' => 'missing_token',
            ]),
            'nodes' => [],
            'services' => [],
        ];
    }

    $response = videochat_infra_http_json($base . '/servers', [
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json',
        'User-Agent' => 'king-videochat-infra-inventory/1.0',
    ]);

    if (!(bool) $response['ok']) {
        return [
            'provider' => videochat_infra_provider_row('hetzner', 'Hetzner Cloud', 'cloud', true, 'error', [
                'list_nodes' => false,
                'spawn_sfu_instance' => false,
            ], [
                'api_base' => $base,
                'error' => $response['error'],
                'status' => $response['status'],
            ]),
            'nodes' => [],
            'services' => [],
        ];
    }

    $servers = is_array($response['payload']['servers'] ?? null) ? $response['payload']['servers'] : [];
    $nodes = [];
    $services = [];
    foreach ($servers as $server) {
        if (!is_array($server)) {
            continue;
        }
        $id = 'hcloud:' . (string) ($server['id'] ?? $server['name'] ?? count($nodes) + 1);
        $name = (string) ($server['name'] ?? $id);
        $status = (string) ($server['status'] ?? 'unknown');
        $roles = videochat_infra_hcloud_roles_for_server($server);
        $location = is_array($server['datacenter']['location'] ?? null) ? $server['datacenter']['location'] : [];
        $serverType = is_array($server['server_type'] ?? null) ? $server['server_type'] : [];
        $publicIpv4 = '';
        if (is_array($server['public_net']['ipv4'] ?? null)) {
            $publicIpv4 = (string) ($server['public_net']['ipv4']['ip'] ?? '');
        }
        $labels = is_array($server['labels'] ?? null) ? $server['labels'] : [];
        $nodes[] = videochat_infra_node_row($id, $name, 'hetzner', $roles, $status, (string) ($location['name'] ?? ''), $publicIpv4, [
            'type' => (string) ($serverType['name'] ?? ''),
            'cpu' => (int) ($serverType['cores'] ?? 0),
            'memory_gb' => (float) ($serverType['memory'] ?? 0),
            'disk_gb' => (int) ($serverType['disk'] ?? 0),
        ], $labels);
        array_push($services, ...videochat_infra_default_services_for_node($id, $deployment, $roles, $status));
    }

    return [
        'provider' => videochat_infra_provider_row('hetzner', 'Hetzner Cloud', 'cloud', true, 'connected', [
            'list_nodes' => true,
            'spawn_sfu_instance' => true,
        ], [
            'api_base' => $base,
            'server_count' => count($nodes),
        ]),
        'nodes' => $nodes,
        'services' => $services,
    ];
}

/** @return array{provider: array<string, mixed>, nodes: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>} */
function videochat_infra_static_inventory(array $deployment): array
{
    $host = videochat_infra_env('VIDEOCHAT_DEPLOY_HOST', videochat_infra_env('VIDEOCHAT_V1_PUBLIC_HOST', gethostname() ?: 'local'));
    $roles = videochat_infra_csv(videochat_infra_env('VIDEOCHAT_INFRA_NODE_ROLES', 'edge,http,ws,sfu'), ['edge', 'http', 'ws', 'sfu']);
    $nodeId = 'static:' . preg_replace('/[^a-zA-Z0-9_.:-]+/', '-', $host);
    $node = videochat_infra_node_row(
        $nodeId,
        videochat_infra_env('VIDEOCHAT_INFRA_NODE_NAME', $host),
        'static',
        $roles,
        'running',
        videochat_infra_env('VIDEOCHAT_INFRA_REGION', 'local'),
        filter_var($host, FILTER_VALIDATE_IP) ? $host : ''
    );

    return [
        'provider' => videochat_infra_provider_row('static', 'Static / self-hosted', 'generic', true, 'configured', [
            'list_nodes' => true,
            'spawn_sfu_instance' => false,
        ]),
        'nodes' => [$node],
        'services' => videochat_infra_default_services_for_node($nodeId, $deployment, $roles, 'running'),
    ];
}

/** @return array<string, mixed> */
function videochat_infra_kubernetes_provider(): array
{
    $detected = videochat_infra_env('KUBERNETES_SERVICE_HOST') !== '' || videochat_infra_env_bool('VIDEOCHAT_INFRA_KUBERNETES_ENABLE');
    return videochat_infra_provider_row('kubernetes', 'Kubernetes', 'orchestrator', $detected, $detected ? 'detected' : 'not_detected', [
        'list_nodes' => false,
        'scale_sfu_deployment' => $detected,
    ], [
        'namespace' => videochat_infra_env('VIDEOCHAT_INFRA_KUBERNETES_NAMESPACE', videochat_infra_env('POD_NAMESPACE', '')),
        'pod_name' => videochat_infra_env('HOSTNAME', ''),
    ]);
}

/** @return array<string, mixed> */
function videochat_infra_telemetry_snapshot(): array
{
    $endpoint = videochat_infra_env('VIDEOCHAT_OTEL_EXPORTER_ENDPOINT');
    $enabled = videochat_infra_env_bool('VIDEOCHAT_OTEL_ENABLE') || $endpoint !== '';

    return [
        'open_telemetry' => [
            'enabled' => $enabled,
            'exporter_endpoint_configured' => $endpoint !== '',
            'exporter_endpoint' => $endpoint === '' ? '' : '[configured]',
            'protocol' => videochat_infra_env('VIDEOCHAT_OTEL_EXPORTER_PROTOCOL', 'grpc'),
            'metrics_enabled' => videochat_infra_env_bool('VIDEOCHAT_OTEL_METRICS_ENABLE', $enabled),
            'logs_enabled' => videochat_infra_env_bool('VIDEOCHAT_OTEL_LOGS_ENABLE', $enabled),
            'service_names' => [
                'http' => videochat_infra_env('VIDEOCHAT_OTEL_SERVICE_NAME_HTTP', 'king-videochat-http'),
                'ws' => videochat_infra_env('VIDEOCHAT_OTEL_SERVICE_NAME_WS', 'king-videochat-ws'),
                'sfu' => videochat_infra_env('VIDEOCHAT_OTEL_SERVICE_NAME_SFU', 'king-videochat-sfu'),
            ],
            'mode' => 'exporter',
        ],
    ];
}

/** @return array<string, mixed> */
function videochat_infra_scaling_snapshot(array $providers, array $nodes): array
{
    $providerById = [];
    foreach ($providers as $provider) {
        if (is_array($provider) && is_string($provider['id'] ?? null)) {
            $providerById[$provider['id']] = $provider;
        }
    }

    $hetznerCanSpawn = (bool) ($providerById['hetzner']['capabilities']['spawn_sfu_instance'] ?? false);
    $kubernetesCanScale = (bool) ($providerById['kubernetes']['capabilities']['scale_sfu_deployment'] ?? false);
    $sfuNodeCount = 0;
    foreach ($nodes as $node) {
        if (is_array($node) && in_array('sfu', is_array($node['roles'] ?? null) ? $node['roles'] : [], true)) {
            $sfuNodeCount++;
        }
    }

    return [
        'strategy' => count($nodes) <= 1 ? 'single_node_split_services' : 'multi_node_provider_inventory',
        'sfu_nodes' => $sfuNodeCount,
        'modes' => [
            [
                'id' => 'monorepo_service_workers',
                'label' => 'Scale King service workers on the current node',
                'available' => true,
                'write_action' => false,
            ],
            [
                'id' => 'hetzner_sfu_node',
                'label' => 'Provision dedicated SFU node through Hetzner Cloud',
                'available' => $hetznerCanSpawn,
                'write_action' => false,
            ],
            [
                'id' => 'kubernetes_sfu_replicas',
                'label' => 'Scale SFU deployment replicas in Kubernetes',
                'available' => $kubernetesCanScale,
                'write_action' => false,
            ],
        ],
        'write_actions_enabled' => false,
        'next_step' => $sfuNodeCount <= 1
            ? 'Add an audited scaling action before creating additional SFU capacity from the dashboard.'
            : 'Review SFU placement and shared-state readiness before changing replica counts.',
    ];
}

/** @return array<string, mixed> */
function videochat_infra_inventory_snapshot(): array
{
    $deployment = videochat_infra_deployment_snapshot();
    $mode = strtolower((string) ($deployment['inventory_mode'] ?? 'auto'));

    $providers = [];
    $nodes = [];
    $services = [];

    if ($mode === 'auto' || $mode === 'hetzner') {
        $hetzner = videochat_infra_hcloud_inventory($deployment);
        $providers[] = $hetzner['provider'];
        $nodes = array_merge($nodes, $hetzner['nodes']);
        $services = array_merge($services, $hetzner['services']);
    }

    $providers[] = videochat_infra_kubernetes_provider();

    if ($mode === 'static' || $mode === 'auto' || $nodes === []) {
        $static = videochat_infra_static_inventory($deployment);
        $providers[] = $static['provider'];
        if ($nodes === []) {
            $nodes = array_merge($nodes, $static['nodes']);
            $services = array_merge($services, $static['services']);
        }
    }

    return [
        'status' => 'ok',
        'deployment' => $deployment,
        'providers' => $providers,
        'nodes' => $nodes,
        'services' => $services,
        'telemetry' => videochat_infra_telemetry_snapshot(),
        'scaling' => videochat_infra_scaling_snapshot($providers, $nodes),
        'time' => gmdate('c'),
    ];
}
