--TEST--
King\Config applies namespaced autoscale mcp orchestrator geometry smartcontract and ssh overrides across the bound skeleton session snapshot
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$cfg = king_new_config([
    'autoscale.provider' => 'hetzner',
    'autoscale.max_nodes' => 4,
    'mcp.enable_request_caching' => true,
    'mcp.default_request_timeout_ms' => 45000,
    'orchestrator.enable_distributed_tracing' => false,
    'geometry.default_vector_dimensions' => 1024,
    'geometry.calculation_precision' => 'float32',
    'smartcontract.enable' => true,
    'smartcontract.dlt_provider' => 'solana',
    'ssh.gateway_enable' => true,
    'ssh.gateway_auth_mode' => 'mcp_token',
]);

$session = king_connect('127.0.0.1', 443, $cfg);
$stats = king_get_stats($session);

var_dump($stats['config_autoscale_provider']);
var_dump($stats['config_autoscale_max_nodes']);
var_dump($stats['config_mcp_enable_request_caching']);
var_dump($stats['config_mcp_default_request_timeout_ms']);
var_dump($stats['config_orchestrator_enable_distributed_tracing']);
var_dump($stats['config_geometry_default_vector_dimensions']);
var_dump($stats['config_geometry_calculation_precision']);
var_dump($stats['config_smartcontract_enable']);
var_dump($stats['config_smartcontract_dlt_provider']);
var_dump($stats['config_ssh_gateway_enable']);
var_dump($stats['config_ssh_gateway_auth_mode']);
var_dump($stats['config_option_count']);
?>
--EXPECT--
string(7) "hetzner"
int(4)
bool(true)
int(45000)
bool(false)
int(1024)
string(7) "float32"
bool(true)
string(6) "solana"
bool(true)
string(9) "mcp_token"
int(11)
