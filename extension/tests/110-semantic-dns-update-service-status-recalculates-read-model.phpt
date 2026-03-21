--TEST--
King semantic DNS update-service-status recalculates topology, discovery, and route
--SKIPIF--
<?php
if (
    king_semantic_dns_register_service([
        'service_id' => 'api-1',
        'service_name' => 'api',
        'service_type' => 'pipeline_orchestrator',
        'status' => 'healthy',
        'hostname' => 'api-1.internal',
        'port' => 8443,
    ]) === false
    || king_semantic_dns_update_service_status(
        'api-1',
        'unhealthy',
        [
            'current_load_percent' => 90,
            'active_connections' => 12,
            'total_requests' => 100,
        ]
    ) === false
) {
    die('skip Semantic-DNS update-service-status is not implemented yet');
}
?>
--FILE--
<?php
var_dump(king_semantic_dns_register_service([
    'service_id' => 'api-1',
    'service_name' => 'api',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'healthy',
    'hostname' => 'api-1.internal',
    'port' => 8443,
]));
var_dump(king_semantic_dns_register_service([
    'service_id' => 'api-2',
    'service_name' => 'api',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'healthy',
    'hostname' => 'api-2.internal',
    'port' => 8444,
]));

var_dump(king_semantic_dns_get_optimal_route('api')['service_id']);
var_dump(king_semantic_dns_discover_service('pipeline_orchestrator', ['status' => 'healthy'])['service_count']);

var_dump(king_semantic_dns_update_service_status('api-1', 'unhealthy', [
    'current_load_percent' => 90,
    'active_connections' => 12,
    'total_requests' => 100,
]));

$topology = king_semantic_dns_get_service_topology();
$servicesById = [];
foreach ($topology['services'] as $service) {
    $servicesById[$service['service_id']] = $service;
}

var_dump($topology['statistics']['total_services']);
var_dump($topology['statistics']['healthy_services']);
var_dump($topology['statistics']['unhealthy_services']);
var_dump($servicesById['api-1']['status']);
var_dump($servicesById['api-2']['status']);

var_dump(king_semantic_dns_discover_service('pipeline_orchestrator', ['status' => 'healthy'])['service_count']);
var_dump(king_semantic_dns_discover_service('pipeline_orchestrator')['service_count']);
var_dump(king_semantic_dns_get_optimal_route('api')['service_id']);
?>
--EXPECT--
bool(true)
bool(true)
string(5) "api-1"
int(2)
bool(true)
int(2)
int(1)
int(1)
string(9) "unhealthy"
string(7) "healthy"
int(1)
int(1)
string(5) "api-2"
