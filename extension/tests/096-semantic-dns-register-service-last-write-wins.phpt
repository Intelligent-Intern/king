--TEST--
King semantic DNS register-service uses last-write-wins for the same service id
--SKIPIF--
<?php
if (king_semantic_dns_register_service([
    'service_id' => 'api-1',
    'service_name' => 'api',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'healthy',
    'hostname' => 'api.internal',
    'port' => 8443,
]) === false) {
    die('skip Semantic-DNS register-service is not implemented yet');
}
?>
--FILE--
<?php
var_dump(king_semantic_dns_register_service([
    'service_id' => 'api-1',
    'service_name' => 'api',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'healthy',
    'hostname' => 'api.internal',
    'port' => 8443,
]));
var_dump(king_semantic_dns_register_service([
    'service_id' => 'api-1',
    'service_name' => 'api-v2',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'degraded',
    'hostname' => 'api-v2.internal',
    'port' => 8444,
]));

$topology = king_semantic_dns_get_service_topology();
var_dump($topology['statistics']['total_services']);
var_dump(count($topology['services']));
var_dump($topology['services'][0]['service_id']);
var_dump($topology['services'][0]['service_name']);

$discovery = king_semantic_dns_discover_service('pipeline_orchestrator');
var_dump($discovery['service_count']);
var_dump($discovery['services'][0]['service_id']);
var_dump($discovery['services'][0]['service_name']);

$route = king_semantic_dns_get_optimal_route('api-v2', [
    'location' => [
        'latitude' => 52.52,
        'longitude' => 13.405,
    ],
]);
var_dump($route['service_id']);
var_dump(isset($route['error']) ? $route['error'] : null);
?>
--EXPECT--
bool(true)
bool(true)
int(1)
int(1)
string(5) "api-1"
string(6) "api-v2"
int(1)
string(5) "api-1"
string(6) "api-v2"
string(5) "api-1"
NULL
