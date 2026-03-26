--TEST--
King semantic DNS init and start-server expose a local core server runtime
--INI--
king.security_allow_config_override=1
--FILE--
<?php
var_dump(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 8053,
    'default_record_ttl_sec' => 120,
    'service_discovery_max_ips_per_response' => 5,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://node-1',
    'routing_policies' => ['mode' => 'local'],
]));
var_dump(king_semantic_dns_start_server());
var_dump(king_semantic_dns_start_server());
var_dump(king_semantic_dns_register_service([
    'service_id' => 'api-1',
    'service_name' => 'api',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'healthy',
    'hostname' => 'api.internal',
    'port' => 8443,
]));
var_dump(king_semantic_dns_get_optimal_route('api')['service_id']);

$health = king_health();
var_dump(in_array('semantic_dns_server_runtime', $health['active_runtimes'], true));
var_dump(in_array('semantic_dns_server', $health['stubbed_api_groups'], true));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
string(5) "api-1"
bool(true)
bool(false)
