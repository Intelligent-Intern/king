--TEST--
King semantic DNS large topology churn keeps discovery routing and mother-node sync statistics coherent
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/semantic_dns_wire_helper.inc';

mt_srand(357);
$dnsPort = king_semantic_dns_wire_allocate_udp_port();

$stateDir = '/tmp/king_semantic_dns_state';
$statePath = $stateDir . '/durable_state.bin';
$backupPath = $stateDir . '/durable_state.bin.testbackup.' . getmypid();

if (is_file($statePath)) {
    @rename($statePath, $backupPath);
}

register_shutdown_function(static function () use ($statePath, $backupPath): void {
    if (is_file($statePath)) {
        @unlink($statePath);
    }
    if (is_file($backupPath)) {
        @rename($backupPath, $statePath);
    }
});

var_dump(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => $dnsPort,
    'default_record_ttl_sec' => 120,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://large-topology-seed',
    'routing_policies' => ['mode' => 'local'],
]));
var_dump(king_semantic_dns_start_server());

$motherStatuses = [];
for ($i = 0; $i < 12; $i++) {
    $nodeId = sprintf('mother-%02d', $i);
    $status = ($i % 3 === 0) ? 'healthy' : (($i % 3 === 1) ? 'degraded' : 'unknown');
    $motherStatuses[$nodeId] = $status;

    king_semantic_dns_register_mother_node([
        'node_id' => $nodeId,
        'hostname' => $nodeId . '.internal',
        'port' => 9400 + $i,
        'status' => $status,
        'managed_services_count' => 40 + $i,
        'trust_score' => max(0.2, 0.95 - ($i * 0.05)),
    ]);
}

// Churn a few mother nodes through last-write-wins updates.
king_semantic_dns_register_mother_node([
    'node_id' => 'mother-03',
    'hostname' => 'mother-03.internal',
    'port' => 9403,
    'status' => 'healthy',
    'managed_services_count' => 77,
    'trust_score' => 0.88,
]);
$motherStatuses['mother-03'] = 'healthy';

king_semantic_dns_register_mother_node([
    'node_id' => 'mother-08',
    'hostname' => 'mother-08.internal',
    'port' => 9408,
    'status' => 'degraded',
    'managed_services_count' => 91,
    'trust_score' => 0.52,
]);
$motherStatuses['mother-08'] = 'degraded';

$serviceStatuses = [];

king_semantic_dns_register_service([
    'service_id' => 'best-route',
    'service_name' => 'api-fleet',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'healthy',
    'hostname' => 'best.internal',
    'port' => 8800,
    'current_load_percent' => 1,
    'active_connections' => 1,
    'total_requests' => 5000,
]);
$serviceStatuses['best-route'] = 'healthy';

for ($i = 0; $i < 35; $i++) {
    $serviceId = sprintf('fleet-%02d', $i);
    $status = ($i % 4 === 0) ? 'healthy' : (($i % 4 === 1) ? 'degraded' : 'unhealthy');

    king_semantic_dns_register_service([
        'service_id' => $serviceId,
        'service_name' => 'api-fleet',
        'service_type' => 'pipeline_orchestrator',
        'status' => $status,
        'hostname' => $serviceId . '.internal',
        'port' => 9000 + $i,
        'current_load_percent' => 10 + (($i * 3) % 70),
        'active_connections' => 15 + $i,
        'total_requests' => 100 + $i,
    ]);

    $serviceStatuses[$serviceId] = $status;
}

for ($i = 0; $i < 60; $i++) {
    $serviceId = sprintf('fleet-%02d', mt_rand(0, 34));
    $status = (mt_rand(0, 2) === 0) ? 'healthy' : ((mt_rand(0, 1) === 0) ? 'degraded' : 'unhealthy');

    king_semantic_dns_update_service_status($serviceId, $status, [
        'current_load_percent' => mt_rand(5, 95),
        'active_connections' => mt_rand(1, 250),
        'total_requests' => 1000 + $i,
    ]);
    $serviceStatuses[$serviceId] = $status;
}

$healthy = count(array_filter($serviceStatuses, static fn($status) => $status === 'healthy'));
$degraded = count(array_filter($serviceStatuses, static fn($status) => $status === 'degraded'));
$unhealthy = count(array_filter($serviceStatuses, static fn($status) => $status === 'unhealthy'));

$topology = king_semantic_dns_get_service_topology();
$servicesById = [];
$motherById = [];
foreach ($topology['services'] as $service) {
    $servicesById[$service['service_id']] = $service;
}
foreach ($topology['mother_nodes'] as $mother) {
    $motherById[$mother['node_id']] = $mother;
}

$discovery = king_semantic_dns_discover_service('pipeline_orchestrator', ['status' => 'healthy']);
$route = king_semantic_dns_get_optimal_route('api-fleet');

var_dump(count($topology['services']) === 36);
var_dump($topology['statistics']['total_services'] === 36);
var_dump($topology['statistics']['healthy_services'] === $healthy);
var_dump($topology['statistics']['degraded_services'] === $degraded);
var_dump($topology['statistics']['unhealthy_services'] === $unhealthy);
var_dump($topology['statistics']['mother_nodes'] === 12);
var_dump($topology['statistics']['discovered_mother_nodes'] === 12);
var_dump($topology['statistics']['synced_mother_nodes'] === 12);
var_dump(($motherById['mother-03']['status'] ?? null) === 'healthy');
var_dump(($motherById['mother-08']['status'] ?? null) === 'degraded');
var_dump($discovery['service_count'] === min($healthy, 8));
var_dump(count($discovery['services']) === min($healthy, 8));
var_dump(count(array_filter(
    $discovery['services'],
    static fn(array $service): bool => $service['status'] === 'healthy' && $service['service_type'] === 'pipeline_orchestrator'
)) === count($discovery['services']));
var_dump(($servicesById[$route['service_id']]['status'] ?? null) === 'healthy');
var_dump($route['service_id'] === 'best-route');
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
