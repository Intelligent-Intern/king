--TEST--
King semantic DNS excludes unhealthy services from optimal routing
--FILE--
<?php
var_dump(king_semantic_dns_register_service([
    'service_id' => 'api-bad-1',
    'service_name' => 'api_unhealthy_only',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'unhealthy',
    'hostname' => 'api-bad.internal',
    'port' => 9443,
    'current_load_percent' => 1,
    'active_connections' => 0,
]));

$route = king_semantic_dns_get_optimal_route('api_unhealthy_only');
var_dump($route['service_id']);
var_dump($route['error']);
?>
--EXPECT--
bool(true)
NULL
string(24) "No healthy service found"
