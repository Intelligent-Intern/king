--TEST--
King Smart-DNS routing ignores stale static telemetry beyond a bounded TTL and recovers when refreshed via status update
--INI--
king.security_allow_config_override=1
--FILE--
<?php
king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5353,
    'default_record_ttl_sec' => 1,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => false,
]);
king_semantic_dns_start_server();

king_semantic_dns_register_service([
    'service_id' => 'ttl-a',
    'service_name' => 'ttl-route',
    'service_type' => 'http_server',
    'hostname' => '127.0.0.1',
    'port' => 8443,
    'status' => 'healthy',
    'current_load_percent' => 15,
    'active_connections' => 7,
    'total_requests' => 100,
]);
king_semantic_dns_register_service([
    'service_id' => 'ttl-b',
    'service_name' => 'ttl-route',
    'service_type' => 'http_server',
    'hostname' => '127.0.0.1',
    'port' => 8444,
    'status' => 'healthy',
    'current_load_percent' => 40,
    'active_connections' => 99,
    'total_requests' => 120,
]);

$initialRoute = king_semantic_dns_get_optimal_route('ttl-route');
$initialDiscover = king_semantic_dns_discover_service('http_server', ['status' => 'healthy']);
var_dump($initialRoute['service_id'] === 'ttl-a');
var_dump($initialDiscover['service_count']);

sleep(2);

$staleRoute = king_semantic_dns_get_optimal_route('ttl-route');
$staleDiscover = king_semantic_dns_discover_service('http_server', ['status' => 'healthy']);
var_dump($staleRoute['service_id']);
var_dump($staleRoute['error']);
var_dump($staleDiscover['service_count']);

king_semantic_dns_update_service_status('ttl-a', 'healthy');
$refreshedRoute = king_semantic_dns_get_optimal_route('ttl-route');
$refreshedDiscover = king_semantic_dns_discover_service('http_server', ['status' => 'healthy']);
var_dump($refreshedRoute['service_id'] === 'ttl-a');
var_dump($refreshedDiscover['service_count']);
--EXPECT--
bool(true)
int(2)
NULL
string(24) "No healthy service found"
int(0)
bool(true)
int(1)
