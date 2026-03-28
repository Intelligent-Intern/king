--TEST--
King semantic DNS read paths stay lock-free when no live health probe is configured
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function king_semantic_dns_reset_state_dir(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

$stateDir = '/tmp/king_semantic_dns_state';
$lockPath = $stateDir . '/durable_state.bin.lock';

king_semantic_dns_reset_state_dir($stateDir);
register_shutdown_function(static function () use ($stateDir): void {
    king_semantic_dns_reset_state_dir($stateDir);
});

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
var_dump(king_semantic_dns_register_service([
    'service_id' => 'api-1',
    'service_name' => 'api',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'healthy',
    'hostname' => 'api.internal',
    'port' => 8443,
]));

if (is_file($lockPath)) {
    @unlink($lockPath);
}
clearstatcache();
var_dump(is_file($lockPath));

$discovery = king_semantic_dns_discover_service('pipeline_orchestrator');
$route = king_semantic_dns_get_optimal_route('api');
$topology = king_semantic_dns_get_service_topology();

clearstatcache();
var_dump($discovery['service_count']);
var_dump($route['service_id']);
var_dump($topology['statistics']['healthy_services']);
var_dump(is_file($lockPath));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
int(1)
string(5) "api-1"
int(1)
bool(false)
