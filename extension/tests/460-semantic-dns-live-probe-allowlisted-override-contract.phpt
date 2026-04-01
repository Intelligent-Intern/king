--TEST--
King semantic DNS live probes honor allowlisted host and port override attributes
--INI--
king.security_allow_config_override=1
king.dns_live_probe_allowed_hosts=127.0.0.1
--FILE--
<?php
require __DIR__ . '/semantic_dns_live_probe_helper.inc';

$serverState = tempnam(sys_get_temp_dir(), 'king-semantic-dns-live-allow-');
king_semantic_dns_live_probe_write_state($serverState, [
    'http_status' => 200,
    'status' => 'healthy',
    'current_load_percent' => 7,
    'active_connections' => 11,
    'total_requests' => 120,
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
        'service_id' => 'svc-a',
        'service_name' => 'global-chat-api',
        'service_type' => 'http_server',
        'hostname' => 'chat-a.internal',
        'port' => 9443,
        'status' => 'healthy',
        'current_load_percent' => 99,
        'active_connections' => 999,
        'total_requests' => 9999,
        'attributes' => [
            'health_check_host' => '127.0.0.1',
            'health_check_port' => $server['port'],
            'health_check_path' => '/health',
        ],
    ]));

    $route = king_semantic_dns_get_optimal_route('global-chat-api');
    var_dump($route['service_id'] === 'svc-a');
    var_dump($route['current_load_percent'] === 7);
    var_dump($route['active_connections'] === 11);
    var_dump($route['total_requests'] === 120);
} finally {
    $capture = king_semantic_dns_live_probe_stop_server($server);
    var_dump(($capture['request_count'] ?? 0) >= 1);
    var_dump(($capture['requests'][0]['request_line'] ?? null) === 'GET /health HTTP/1.1');
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
