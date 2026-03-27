--TEST--
King Smart-DNS routes against live HTTP health and load signals instead of only static registry metrics
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/semantic_dns_live_probe_helper.inc';

$serverAState = tempnam(sys_get_temp_dir(), 'king-semantic-dns-live-a-');
$serverBState = tempnam(sys_get_temp_dir(), 'king-semantic-dns-live-b-');

king_semantic_dns_live_probe_write_state($serverAState, [
    'http_status' => 200,
    'status' => 'healthy',
    'current_load_percent' => 90,
    'active_connections' => 180,
    'total_requests' => 1200,
]);
king_semantic_dns_live_probe_write_state($serverBState, [
    'http_status' => 200,
    'status' => 'healthy',
    'current_load_percent' => 5,
    'active_connections' => 18,
    'total_requests' => 450,
]);

$serverA = king_semantic_dns_live_probe_start_server($serverAState);
$serverB = king_semantic_dns_live_probe_start_server($serverBState);

try {
    var_dump(king_semantic_dns_init([
        'enabled' => true,
        'bind_address' => '127.0.0.1',
        'dns_port' => 5353,
        'semantic_mode_enable' => false,
        'default_record_ttl_sec' => 60,
        'service_discovery_max_ips_per_response' => 8,
    ]));
    var_dump(king_semantic_dns_start_server());

    var_dump(king_semantic_dns_register_service([
        'service_id' => 'svc-a',
        'service_name' => 'global-chat-api',
        'service_type' => 'http_server',
        'hostname' => '127.0.0.1',
        'port' => $serverA['port'],
        'status' => 'healthy',
        'current_load_percent' => 0,
        'active_connections' => 1,
        'total_requests' => 10,
        'attributes' => [
            'health_check_path' => '/health',
        ],
    ]));
    var_dump(king_semantic_dns_register_service([
        'service_id' => 'svc-b',
        'service_name' => 'global-chat-api',
        'service_type' => 'http_server',
        'hostname' => '127.0.0.1',
        'port' => $serverB['port'],
        'status' => 'healthy',
        'current_load_percent' => 99,
        'active_connections' => 999,
        'total_requests' => 9999,
        'attributes' => [
            'health_check_path' => '/health',
        ],
    ]));

    $firstRoute = king_semantic_dns_get_optimal_route('global-chat-api');
    $firstDiscovery = king_semantic_dns_discover_service('http_server', ['status' => 'healthy']);
    $firstTopology = king_semantic_dns_get_service_topology();

    var_dump($firstRoute['service_id'] === 'svc-b');
    var_dump($firstRoute['current_load_percent'] === 5);
    var_dump($firstRoute['active_connections'] === 18);
    var_dump($firstDiscovery['service_count'] === 2);
    var_dump($firstTopology['statistics']['healthy_services'] === 2);

    king_semantic_dns_live_probe_write_state($serverAState, [
        'http_status' => 200,
        'status' => 'healthy',
        'current_load_percent' => 4,
        'active_connections' => 14,
        'total_requests' => 1220,
    ]);
    king_semantic_dns_live_probe_write_state($serverBState, [
        'http_status' => 200,
        'status' => 'healthy',
        'current_load_percent' => 92,
        'active_connections' => 220,
        'total_requests' => 470,
    ]);

    $secondRoute = king_semantic_dns_get_optimal_route('global-chat-api');
    var_dump($secondRoute['service_id'] === 'svc-a');
    var_dump($secondRoute['current_load_percent'] === 4);
    var_dump($secondRoute['active_connections'] === 14);

    king_semantic_dns_live_probe_write_state($serverAState, [
        'http_status' => 200,
        'status' => 'healthy',
        'current_load_percent' => 88,
        'active_connections' => 240,
        'total_requests' => 1300,
    ]);
    king_semantic_dns_live_probe_write_state($serverBState, [
        'http_status' => 503,
        'status' => 'unhealthy',
        'current_load_percent' => 3,
        'active_connections' => 6,
        'total_requests' => 500,
    ]);

    $thirdRoute = king_semantic_dns_get_optimal_route('global-chat-api');
    $thirdDiscovery = king_semantic_dns_discover_service('http_server', ['status' => 'healthy']);
    $thirdTopology = king_semantic_dns_get_service_topology();

    var_dump($thirdRoute['service_id'] === 'svc-a');
    var_dump($thirdRoute['status'] === 'healthy');
    var_dump($thirdDiscovery['service_count'] === 1);
    var_dump($thirdTopology['statistics']['healthy_services'] === 1);
    var_dump($thirdTopology['statistics']['unhealthy_services'] === 1);
} finally {
    $captureA = king_semantic_dns_live_probe_stop_server($serverA);
    $captureB = king_semantic_dns_live_probe_stop_server($serverB);

    var_dump(($captureA['request_count'] ?? 0) >= 5);
    var_dump(($captureB['request_count'] ?? 0) >= 5);
}
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
