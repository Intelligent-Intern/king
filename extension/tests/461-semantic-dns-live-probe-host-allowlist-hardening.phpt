--TEST--
King semantic DNS refuses live probe targets outside the configured host allowlist
--INI--
king.security_allow_config_override=1
king.dns_live_probe_allowed_hosts=localhost
--FILE--
<?php
require __DIR__ . '/semantic_dns_live_probe_helper.inc';

$serverState = tempnam(sys_get_temp_dir(), 'king-semantic-dns-live-block-');
king_semantic_dns_live_probe_write_state($serverState, [
    'http_status' => 200,
    'status' => 'healthy',
    'current_load_percent' => 1,
    'active_connections' => 1,
    'total_requests' => 1,
]);
$server = king_semantic_dns_live_probe_start_server($serverState);

try {
    var_dump(king_semantic_dns_init([
        'enabled' => true,
        'bind_address' => '127.0.0.1',
        'dns_port' => 5353,
        'default_record_ttl_sec' => 60,
        'service_discovery_max_ips_per_response' => 8,
    ]));
    var_dump(king_semantic_dns_start_server());
    var_dump(king_semantic_dns_register_service([
        'service_id' => 'svc-blocked',
        'service_name' => 'blocked-api',
        'service_type' => 'http_server',
        'hostname' => 'api.internal',
        'port' => 9443,
        'status' => 'healthy',
        'current_load_percent' => 1,
        'active_connections' => 1,
        'total_requests' => 1,
        'attributes' => [
            'health_check_host' => '127.0.0.1',
            'health_check_port' => $server['port'],
            'health_check_path' => '/health',
        ],
    ]));

    $route = king_semantic_dns_get_optimal_route('blocked-api');
    $topology = king_semantic_dns_get_service_topology();

    var_dump($route['service_id'] === null);
    var_dump($route['error'] === 'No healthy service found');
    var_dump($topology['statistics']['unhealthy_services'] === 1);
} finally {
    $capture = king_semantic_dns_live_probe_stop_server($server);
    var_dump(($capture['request_count'] ?? 0) === 0);
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
