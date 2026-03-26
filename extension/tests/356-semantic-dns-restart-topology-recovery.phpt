--TEST--
King semantic-dns restart recovery rehydrates persisted services mother nodes and routing state
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$state_dir = '/tmp/king_semantic_dns_state';
$state_file = $state_dir . '/durable_state.bin';
$extension_path = dirname(__DIR__) . '/modules/king.so';
$writer_script = sys_get_temp_dir() . '/king_sdns_restart_writer_' . getmypid() . '.php';
$reader_script = sys_get_temp_dir() . '/king_sdns_restart_reader_' . getmypid() . '.php';

$state_dir_existed = is_dir($state_dir);
$state_backup = null;
$state_was_file = is_file($state_file) && !is_link($state_file);
if ($state_was_file) {
    $state_backup = file_get_contents($state_file);
}

if (!$state_dir_existed) {
    mkdir($state_dir, 0700, true);
}
chmod($state_dir, 0700);
@unlink($state_file);

file_put_contents($writer_script, <<<'PHP'
<?php
var_dump(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5453,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://restart-seed',
]));
var_dump(king_semantic_dns_start_server());
var_dump(king_semantic_dns_register_service([
    'service_id' => 'api-1',
    'service_name' => 'api',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'healthy',
    'hostname' => 'api-1.internal',
    'port' => 8443,
    'current_load_percent' => 25,
    'active_connections' => 7,
    'total_requests' => 111,
]));
var_dump(king_semantic_dns_register_service([
    'service_id' => 'api-2',
    'service_name' => 'api',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'degraded',
    'hostname' => 'api-2.internal',
    'port' => 8444,
    'current_load_percent' => 10,
    'active_connections' => 5,
    'total_requests' => 222,
]));
var_dump(king_semantic_dns_register_service([
    'service_id' => 'edge-1',
    'service_name' => 'edge',
    'service_type' => 'http_server',
    'status' => 'healthy',
    'hostname' => 'edge-1.internal',
    'port' => 9443,
    'current_load_percent' => 30,
    'active_connections' => 9,
    'total_requests' => 333,
]));
var_dump(king_semantic_dns_register_mother_node([
    'node_id' => 'mother-a',
    'hostname' => 'mother-a.internal',
    'port' => 9553,
    'status' => 'healthy',
    'managed_services_count' => 2,
    'trust_score' => 0.90,
]));
var_dump(king_semantic_dns_register_mother_node([
    'node_id' => 'mother-b',
    'hostname' => 'mother-b.internal',
    'port' => 9554,
    'status' => 'degraded',
    'managed_services_count' => 1,
    'trust_score' => 0.60,
]));
PHP
);

file_put_contents($reader_script, <<<'PHP'
<?php
var_dump(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5453,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://restart-seed',
]));
var_dump(king_semantic_dns_start_server());

$topology = king_semantic_dns_get_service_topology();
$servicesById = [];
foreach ($topology['services'] as $service) {
    $servicesById[$service['service_id']] = $service;
}
$motherById = [];
foreach ($topology['mother_nodes'] as $mother) {
    $motherById[$mother['node_id']] = $mother;
}

$discovery = king_semantic_dns_discover_service('pipeline_orchestrator');
$route = king_semantic_dns_get_optimal_route('api');

var_dump($topology['statistics']['total_services']);
var_dump($topology['statistics']['healthy_services']);
var_dump($topology['statistics']['degraded_services']);
var_dump($topology['statistics']['mother_nodes']);
var_dump($discovery['service_count']);
var_dump($route['service_id']);
var_dump($servicesById['api-1']['current_load_percent']);
var_dump($servicesById['api-1']['total_requests']);
var_dump(isset($motherById['mother-a']));
var_dump($motherById['mother-a']['trust_score']);
var_dump($motherById['mother-b']['status']);
PHP
);

$writer_cmd = sprintf(
    '%s -d king.security_allow_config_override=1 -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extension_path),
    escapeshellarg($writer_script)
);
$reader_cmd = sprintf(
    '%s -d king.security_allow_config_override=1 -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extension_path),
    escapeshellarg($reader_script)
);

exec($writer_cmd, $writer_output, $writer_status);
exec($reader_cmd, $reader_output, $reader_status);

var_dump($writer_status);
var_dump($reader_status);
echo implode("\n", $reader_output), "\n";

if ($state_was_file) {
    file_put_contents($state_file, $state_backup);
} else {
    @unlink($state_file);
}
if (!$state_dir_existed) {
    @rmdir($state_dir);
}

@unlink($writer_script);
@unlink($reader_script);
?>
--EXPECT--
int(0)
int(0)
bool(true)
bool(true)
int(3)
int(2)
int(1)
int(2)
int(2)
string(5) "api-1"
int(25)
int(111)
bool(true)
float(0.9)
string(8) "degraded"
