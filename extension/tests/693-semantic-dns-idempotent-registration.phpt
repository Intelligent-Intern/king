--TEST--
King semantic-dns identical service registration is idempotent and skips redundant durable-state rewrites
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$state_dir = '/tmp/king_semantic_dns_state';
$state_file = $state_dir . '/durable_state.bin';

if (!is_dir($state_dir)) {
    mkdir($state_dir, 0700, true);
}
chmod($state_dir, 0700);
@unlink($state_file);

$service = [
    'service_id' => 'api-bench',
    'service_name' => 'api-bench',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'healthy',
    'hostname' => 'api.internal',
    'port' => 8443,
    'current_load_percent' => 12,
    'active_connections' => 4,
    'total_requests' => 80,
];

var_dump(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 8053,
    'default_record_ttl_sec' => 120,
    'service_discovery_max_ips_per_response' => 5,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://bench-node',
    'routing_policies' => ['mode' => 'local'],
]));
var_dump(king_semantic_dns_start_server());
var_dump(king_semantic_dns_register_service($service));

$registered_at_1 = king_semantic_dns_get_service_topology()['services'][0]['registered_at'];

sleep(1);

var_dump(king_semantic_dns_register_service($service));
$registered_at_2 = king_semantic_dns_get_service_topology()['services'][0]['registered_at'];

sleep(1);

$service['active_connections'] = 9;
var_dump(king_semantic_dns_register_service($service));
$topology = king_semantic_dns_get_service_topology();

var_dump($registered_at_1 === $registered_at_2);
var_dump($registered_at_2 < $topology['services'][0]['registered_at']);
var_dump($topology['services'][0]['active_connections']);

@unlink($state_file);
@rmdir($state_dir);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(9)
