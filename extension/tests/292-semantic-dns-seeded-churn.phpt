--TEST--
King semantic DNS seeded churn keeps topology, discovery, and routing internally consistent
--INI--
king.security_allow_config_override=1
--FILE--
<?php
mt_srand(292);

var_dump(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 8053,
    'default_record_ttl_sec' => 120,
    'service_discovery_max_ips_per_response' => 16,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://seeded-fuzz',
    'mothernode_sync_interval_sec' => 60,
    'routing_policies' => ['mode' => 'local'],
]));
var_dump(king_semantic_dns_start_server());

$statusPool = ['healthy', 'degraded', 'unhealthy'];
$expectedStatuses = [];

for ($i = 0; $i < 12; $i++) {
    $serviceId = sprintf('fuzz-%02d', $i);
    $status = $statusPool[$i % count($statusPool)];

    var_dump(king_semantic_dns_register_service([
        'service_id' => $serviceId,
        'service_name' => 'api-fuzz',
        'service_type' => 'pipeline_orchestrator',
        'status' => $status,
        'hostname' => $serviceId . '.internal',
        'port' => 9000 + $i,
        'current_load_percent' => ($i * 7) % 100,
        'active_connections' => 10 + $i,
    ]));

    $expectedStatuses[$serviceId] = $status;
}

for ($i = 0; $i < 48; $i++) {
    $serviceId = sprintf('fuzz-%02d', mt_rand(0, 11));
    $status = $statusPool[mt_rand(0, count($statusPool) - 1)];

    king_semantic_dns_update_service_status($serviceId, $status, [
        'current_load_percent' => mt_rand(1, 99),
        'active_connections' => mt_rand(1, 250),
        'total_requests' => 1000 + $i,
    ]);

    $expectedStatuses[$serviceId] = $status;
}

if (!in_array('healthy', $expectedStatuses, true)) {
    king_semantic_dns_update_service_status('fuzz-00', 'healthy', [
        'current_load_percent' => 1,
        'active_connections' => 1,
        'total_requests' => 9999,
    ]);
    $expectedStatuses['fuzz-00'] = 'healthy';
}

$healthy = count(array_filter($expectedStatuses, static fn($status) => $status === 'healthy'));
$degraded = count(array_filter($expectedStatuses, static fn($status) => $status === 'degraded'));
$unhealthy = count(array_filter($expectedStatuses, static fn($status) => $status === 'unhealthy'));

$topology = king_semantic_dns_get_service_topology();
$servicesById = [];
foreach ($topology['services'] as $service) {
    $servicesById[$service['service_id']] = $service;
}

$discovery = king_semantic_dns_discover_service('pipeline_orchestrator', ['status' => 'healthy']);
$route = king_semantic_dns_get_optimal_route('api-fuzz');

var_dump(count($topology['services']) === 12);
var_dump($topology['statistics']['healthy_services'] === $healthy);
var_dump($topology['statistics']['degraded_services'] === $degraded);
var_dump($topology['statistics']['unhealthy_services'] === $unhealthy);
var_dump($discovery['service_count'] === $healthy);
var_dump(isset($servicesById[$route['service_id']]));
var_dump(($servicesById[$route['service_id']]['status'] ?? null) === 'healthy');
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
