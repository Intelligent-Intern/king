--TEST--
King semantic DNS routing and network integration respects custom constraints
--FILE--
<?php

// Seed some test services
king_semantic_dns_register_service([
    'service_id' => 'test-svc-1',
    'service_name' => 'api_backend',
    'service_type' => 'http_server',
    'hostname' => 'node1.internal',
    'port' => 8080,
    'status' => 'healthy',
    'current_load_percent' => 5,
    'active_connections' => 50
]);

king_semantic_dns_register_service([
    'service_id' => 'test-svc-2',
    'service_name' => 'api_backend',
    'service_type' => 'http_server',
    'hostname' => 'node2.internal',
    'port' => 8080,
    'status' => 'healthy',
    'current_load_percent' => 90, // Unfavorable load
    'active_connections' => 200 
]);

king_semantic_dns_register_service([
    'service_id' => 'test-svc-3',
    'service_name' => 'api_backend',
    'service_type' => 'http_server',
    'hostname' => 'node3.internal',
    'port' => 8081,
    'status' => 'degraded', // Degraded
    'current_load_percent' => 2,
    'active_connections' => 10 
]);

// 1. Expected optimal choice is node1.internal because it has the best load and health
$optimal = king_semantic_dns_get_optimal_route('api_backend');
var_dump($optimal['hostname']);

// 2. E2E Routing Policy constraint check: Force target with high load to be unacceptable with our criteria
$constrained = king_semantic_dns_get_optimal_route('api_backend', [
    'max_load_percent' => 80 // Should exclude node2, normally node1 is picked anyway, but let's test port constraint
]);
var_dump($constrained['hostname']);

// 3. E2E Policy: Force port 8081 (which belongs to the degraded node3)
$forced_port = king_semantic_dns_get_optimal_route('api_backend', [
    'port' => 8081
]);
var_dump($forced_port['hostname']);
var_dump($forced_port['status']);

// 4. E2E Network Policy: Non-existent host constraint yields no results
$impossible = king_semantic_dns_get_optimal_route('api_backend', [
    'hostname' => 'node99.internal'
]);
var_dump($impossible['error']);

?>
--EXPECTF--
string(14) "node1.internal"
string(14) "node1.internal"
string(14) "node3.internal"
string(8) "degraded"
string(24) "No healthy service found"
