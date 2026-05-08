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
        'cdn_domain' => videochat_infra_env('VIDEOCHAT_DEPLOY_CDN_DOMAIN', 'cdn.' . $domain),
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

/** @return array{idle: int, total: int}|null */
function videochat_infra_cpu_sample(): ?array
{
    $lines = is_readable('/proc/stat') ? file('/proc/stat', FILE_IGNORE_NEW_LINES) : false;
    $line = is_array($lines) ? ($lines[0] ?? '') : '';
    if (!is_string($line) || !str_starts_with($line, 'cpu ')) {
        return null;
    }

    $parts = array_values(array_filter(explode(' ', trim($line)), static fn (string $part): bool => $part !== ''));
    array_shift($parts);
    $values = array_map(static fn (string $part): int => max(0, (int) $part), $parts);
    if (count($values) < 5) {
        return null;
    }

    $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
    return ['idle' => $idle, 'total' => array_sum($values)];
}

function videochat_infra_cpu_usage_percent(): ?float
{
    $first = videochat_infra_cpu_sample();
    if ($first === null) {
        return null;
    }

    usleep(100000);
    $second = videochat_infra_cpu_sample();
    if ($second === null) {
        return null;
    }

    $totalDelta = $second['total'] - $first['total'];
    $idleDelta = $second['idle'] - $first['idle'];
    if ($totalDelta <= 0) {
        return null;
    }

    return round(max(0.0, min(100.0, (1 - ($idleDelta / $totalDelta)) * 100)), 1);
}

/** @return array{percent: float, total_mb: int, used_mb: int}|null */
function videochat_infra_memory_usage(): ?array
{
    if (!is_readable('/proc/meminfo')) {
        return null;
    }

    $values = [];
    foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', (string) $line, $matches) === 1) {
            $values[$matches[1]] = (int) $matches[2];
        }
    }

    $totalKb = (int) ($values['MemTotal'] ?? 0);
    $availableKb = (int) ($values['MemAvailable'] ?? 0);
    if ($totalKb <= 0 || $availableKb < 0) {
        return null;
    }

    $usedKb = max(0, $totalKb - $availableKb);
    return [
        'percent' => round(max(0.0, min(100.0, ($usedKb / $totalKb) * 100)), 1),
        'total_mb' => (int) round($totalKb / 1024),
        'used_mb' => (int) round($usedKb / 1024),
    ];
}

/** @return array<string, mixed> */
function videochat_infra_local_resource_usage(): array
{
    $memory = videochat_infra_memory_usage();
    $resources = [
        'cpu_usage_percent' => null,
        'memory_usage_percent' => null,
        'memory_total_mb' => null,
        'memory_used_mb' => null,
    ];
    $cpuUsage = videochat_infra_cpu_usage_percent();
    if ($cpuUsage !== null) {
        $resources['cpu_usage_percent'] = $cpuUsage;
    }
    if ($memory !== null) {
        $resources['memory_usage_percent'] = $memory['percent'];
        $resources['memory_total_mb'] = $memory['total_mb'];
        $resources['memory_used_mb'] = $memory['used_mb'];
    }
    return $resources;
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
    $localServerName = videochat_infra_env(
        'VIDEOCHAT_INFRA_LOCAL_NODE_NAME',
        videochat_infra_env('VIDEOCHAT_DEPLOY_HCLOUD_SERVER_NAME', gethostname() ?: '')
    );
    $localPublicIpv4 = videochat_infra_env(
        'VIDEOCHAT_INFRA_LOCAL_PUBLIC_IP',
        videochat_infra_env('VIDEOCHAT_DEPLOY_PUBLIC_IP', videochat_infra_env('VIDEOCHAT_DEPLOY_HOST'))
    );
    $localResourceUsage = videochat_infra_local_resource_usage();
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
        $resources = [
            'type' => (string) ($serverType['name'] ?? ''),
            'cpu' => (int) ($serverType['cores'] ?? 0),
            'memory_gb' => (float) ($serverType['memory'] ?? 0),
            'disk_gb' => (int) ($serverType['disk'] ?? 0),
        ];
        if (
            ($localServerName !== '' && hash_equals($localServerName, $name))
            || ($localPublicIpv4 !== '' && $publicIpv4 !== '' && hash_equals($localPublicIpv4, $publicIpv4))
        ) {
            $resources = array_merge($resources, $localResourceUsage);
        }
        $nodes[] = videochat_infra_node_row($id, $name, 'hetzner', $roles, $status, (string) ($location['name'] ?? ''), $publicIpv4, $resources, $labels);
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
        filter_var($host, FILTER_VALIDATE_IP) ? $host : '',
        videochat_infra_local_resource_usage()
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

/** @return array{provider: array<string, mixed>, nodes: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>} */
function videochat_infra_kubernetes_inventory(array $deployment): array
{
    unset($deployment);

    return [
        'provider' => videochat_infra_kubernetes_provider(),
        'nodes' => [],
        'services' => [],
    ];
}

/** @return array<string, mixed> */
function videochat_infra_provider_adapter(string $id, array $modes, callable $inventory): array
{
    return [
        'id' => $id,
        'modes' => array_values(array_unique($modes)),
        'inventory' => $inventory,
    ];
}

/** @return array<string, array<string, mixed>> */
function videochat_infra_provider_adapters(): array
{
    return [
        'hetzner' => videochat_infra_provider_adapter('hetzner', ['auto', 'hetzner'], 'videochat_infra_hcloud_inventory'),
        'kubernetes' => videochat_infra_provider_adapter('kubernetes', ['auto', 'hetzner', 'kubernetes', 'static'], 'videochat_infra_kubernetes_inventory'),
        'static' => videochat_infra_provider_adapter('static', ['auto', 'hetzner', 'kubernetes', 'static'], 'videochat_infra_static_inventory'),
    ];
}

function videochat_infra_provider_adapter_matches(array $adapter, string $mode): bool
{
    $modes = is_array($adapter['modes'] ?? null) ? $adapter['modes'] : [];
    return in_array($mode, $modes, true);
}

/** @return array{provider: array<string, mixed>, nodes: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>} */
function videochat_infra_discover_provider(array $adapter, array $deployment): array
{
    $inventory = $adapter['inventory'] ?? null;
    if (!is_callable($inventory)) {
        $id = is_string($adapter['id'] ?? null) ? $adapter['id'] : 'unknown';
        return [
            'provider' => videochat_infra_provider_row($id, $id, 'unknown', false, 'error', [], [
                'error' => 'provider_inventory_not_callable',
            ]),
            'nodes' => [],
            'services' => [],
        ];
    }

    $result = $inventory($deployment);
    return [
        'provider' => is_array($result['provider'] ?? null) ? $result['provider'] : videochat_infra_provider_row((string) ($adapter['id'] ?? 'unknown'), (string) ($adapter['id'] ?? 'unknown'), 'unknown', false, 'error'),
        'nodes' => is_array($result['nodes'] ?? null) ? array_values($result['nodes']) : [],
        'services' => is_array($result['services'] ?? null) ? array_values($result['services']) : [],
    ];
}

/** @return array{providers: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>} */
function videochat_infra_collect_provider_inventory(array $deployment, string $mode): array
{
    $adapters = videochat_infra_provider_adapters();
    $providers = [];
    $nodes = [];
    $services = [];

    foreach ($adapters as $id => $adapter) {
        if ($id === 'static' || !videochat_infra_provider_adapter_matches($adapter, $mode)) {
            continue;
        }

        $result = videochat_infra_discover_provider($adapter, $deployment);
        $providers[] = $result['provider'];
        array_push($nodes, ...$result['nodes']);
        array_push($services, ...$result['services']);
    }

    $staticAdapter = $adapters['static'] ?? null;
    if (is_array($staticAdapter) && ($mode === 'static' || $mode === 'auto' || $nodes === [])) {
        $result = videochat_infra_discover_provider($staticAdapter, $deployment);
        $providers[] = $result['provider'];
        if ($nodes === []) {
            array_push($nodes, ...$result['nodes']);
            array_push($services, ...$result['services']);
        }
    }

    return [
        'providers' => $providers,
        'nodes' => $nodes,
        'services' => $services,
    ];
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
    $inventory = videochat_infra_collect_provider_inventory($deployment, $mode);
    $providers = $inventory['providers'];
    $nodes = $inventory['nodes'];
    $services = $inventory['services'];

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
