--TEST--
King Smart-DNS converges routing discovery and mother-node state after conflicting writers and partial service failure
--INI--
king.security_allow_config_override=1
king.dns_live_probe_allowed_hosts=127.0.0.1
--FILE--
<?php
require __DIR__ . '/semantic_dns_live_probe_helper.inc';

$stateDir = '/tmp/king_semantic_dns_state';
$stateFile = $stateDir . '/durable_state.bin';
$lockFile = $stateFile . '.lock';
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$writerAScript = sys_get_temp_dir() . '/king_sdns_split_writer_a_' . getmypid() . '.php';
$writerBScript = sys_get_temp_dir() . '/king_sdns_split_writer_b_' . getmypid() . '.php';
$observerScript = sys_get_temp_dir() . '/king_sdns_split_observer_' . getmypid() . '.php';

$stateDirExisted = is_dir($stateDir);
$stateWasFile = is_file($stateFile) && !is_link($stateFile);
$stateBackup = $stateWasFile ? file_get_contents($stateFile) : null;
$lockWasFile = is_file($lockFile) && !is_link($lockFile);
$lockBackup = $lockWasFile ? file_get_contents($lockFile) : null;

if (!$stateDirExisted) {
    mkdir($stateDir, 0700, true);
}
chmod($stateDir, 0700);
@unlink($stateFile);
@unlink($lockFile);

$eastState = tempnam(sys_get_temp_dir(), 'king-semantic-dns-split-east-');
$westState = tempnam(sys_get_temp_dir(), 'king-semantic-dns-split-west-');

king_semantic_dns_live_probe_write_state($eastState, [
    'http_status' => 200,
    'status' => 'healthy',
    'current_load_percent' => 11,
    'active_connections' => 21,
    'total_requests' => 1001,
]);
king_semantic_dns_live_probe_write_state($westState, [
    'http_status' => 503,
    'status' => 'unhealthy',
    'current_load_percent' => 3,
    'active_connections' => 7,
    'total_requests' => 404,
]);

$eastServer = king_semantic_dns_live_probe_start_server($eastState);
$westServer = king_semantic_dns_live_probe_start_server($westState);

file_put_contents($writerAScript, <<<'PHP'
<?php
function king_sdns_require(bool $value, string $message): void
{
    if ($value !== true) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$eastPort = (int) $argv[1];
$westPort = (int) $argv[2];

king_sdns_require(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5455,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://split-brain-seed',
]), 'init failed');
king_sdns_require(king_semantic_dns_start_server(), 'start failed');

king_sdns_require(king_semantic_dns_register_service([
    'service_id' => 'svc-east',
    'service_name' => 'global-chat-api',
    'service_type' => 'http_server',
    'hostname' => 'east.internal',
    'port' => 8443,
    'status' => 'degraded',
    'current_load_percent' => 97,
    'active_connections' => 400,
    'total_requests' => 5000,
    'attributes' => [
        'region' => 'eu-central',
        'health_check_host' => '127.0.0.1',
        'health_check_port' => $eastPort,
        'health_check_path' => '/health',
    ],
]), 'east register failed');

king_sdns_require(king_semantic_dns_register_service([
    'service_id' => 'svc-west',
    'service_name' => 'global-chat-api',
    'service_type' => 'http_server',
    'hostname' => 'west.internal',
    'port' => 8444,
    'status' => 'healthy',
    'current_load_percent' => 2,
    'active_connections' => 4,
    'total_requests' => 40,
    'attributes' => [
        'region' => 'us-west',
        'health_check_host' => '127.0.0.1',
        'health_check_port' => $westPort,
        'health_check_path' => '/health',
    ],
]), 'west register failed');

king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'mother-east',
    'hostname' => 'mother-east.internal',
    'port' => 9555,
    'status' => 'healthy',
    'managed_services_count' => 1,
    'trust_score' => 0.91,
]), 'mother-east register failed');

king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'shared-core',
    'hostname' => 'shared-core.internal',
    'port' => 9556,
    'status' => 'healthy',
    'managed_services_count' => 2,
    'trust_score' => 0.88,
]), 'shared-core register failed');

echo "writer-a-complete\n";
PHP
);

file_put_contents($writerBScript, <<<'PHP'
<?php
function king_sdns_require(bool $value, string $message): void
{
    if ($value !== true) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$eastPort = (int) $argv[1];
$westPort = (int) $argv[2];

usleep(70000);

king_sdns_require(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5455,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://split-brain-seed',
]), 'init failed');
king_sdns_require(king_semantic_dns_start_server(), 'start failed');

king_sdns_require(king_semantic_dns_register_service([
    'service_id' => 'svc-east',
    'service_name' => 'global-chat-api',
    'service_type' => 'http_server',
    'hostname' => 'east.internal',
    'port' => 8443,
    'status' => 'healthy',
    'current_load_percent' => 55,
    'active_connections' => 140,
    'total_requests' => 5100,
    'attributes' => [
        'region' => 'eu-central',
        'health_check_host' => '127.0.0.1',
        'health_check_port' => $eastPort,
        'health_check_path' => '/health',
    ],
]), 'east overwrite failed');

king_sdns_require(king_semantic_dns_register_service([
    'service_id' => 'svc-west',
    'service_name' => 'global-chat-api',
    'service_type' => 'http_server',
    'hostname' => 'west.internal',
    'port' => 8444,
    'status' => 'healthy',
    'current_load_percent' => 1,
    'active_connections' => 2,
    'total_requests' => 50,
    'attributes' => [
        'region' => 'us-west',
        'health_check_host' => '127.0.0.1',
        'health_check_port' => $westPort,
        'health_check_path' => '/health',
    ],
]), 'west overwrite failed');

king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'mother-west',
    'hostname' => 'mother-west.internal',
    'port' => 9557,
    'status' => 'degraded',
    'managed_services_count' => 1,
    'trust_score' => 0.33,
]), 'mother-west register failed');

king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'shared-core',
    'hostname' => 'shared-core.internal',
    'port' => 9556,
    'status' => 'degraded',
    'managed_services_count' => 1,
    'trust_score' => 0.41,
]), 'shared-core overwrite failed');

echo "writer-b-complete\n";
PHP
);

file_put_contents($observerScript, <<<'PHP'
<?php
$mode = (string) $argv[1];

king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5455,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://split-brain-seed',
]);
king_semantic_dns_start_server();

$route = king_semantic_dns_get_optimal_route('global-chat-api');
$discovery = king_semantic_dns_discover_service('http_server');
$topology = king_semantic_dns_get_service_topology();
$servicesById = [];
$motherById = [];

foreach ($topology['services'] as $service) {
    $servicesById[$service['service_id']] = [
        'status' => $service['status'],
        'current_load_percent' => $service['current_load_percent'],
        'active_connections' => $service['active_connections'],
        'total_requests' => $service['total_requests'],
    ];
}
foreach ($topology['mother_nodes'] as $mother) {
    $motherById[$mother['node_id']] = [
        'status' => $mother['status'],
        'managed_services_count' => $mother['managed_services_count'],
        'trust_score' => $mother['trust_score'],
    ];
}

echo json_encode([
    'mode' => $mode,
    'statistics' => $topology['statistics'],
    'discovery_count' => $discovery['service_count'],
    'route' => [
        'service_id' => $route['service_id'] ?? null,
        'status' => $route['status'] ?? null,
        'current_load_percent' => $route['current_load_percent'] ?? null,
        'active_connections' => $route['active_connections'] ?? null,
        'total_requests' => $route['total_requests'] ?? null,
    ],
    'services' => $servicesById,
    'shared_core' => $motherById['shared-core'] ?? null,
    'mother_west' => $motherById['mother-west'] ?? null,
], JSON_UNESCAPED_SLASHES), "\n";
PHP
);

function king_build_semantic_dns_command(string $extensionPath, string $scriptPath, array $args = []): array
{
    $command = [
        PHP_BINARY,
        '-n',
        '-d', 'extension=' . $extensionPath,
        '-d', 'king.security_allow_config_override=1',
        '-d', 'king.dns_live_probe_allowed_hosts=127.0.0.1',
        $scriptPath,
    ];

    foreach ($args as $arg) {
        $command[] = (string) $arg;
    }

    return $command;
}

function king_finish_semantic_dns_process($process, array $pipes): array
{
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'status' => proc_close($process),
    ];
}

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

try {
    $writerA = proc_open(
        king_build_semantic_dns_command($extensionPath, $writerAScript, [$eastServer['port'], $westServer['port']]),
        $descriptors,
        $pipesA
    );
    $writerB = proc_open(
        king_build_semantic_dns_command($extensionPath, $writerBScript, [$eastServer['port'], $westServer['port']]),
        $descriptors,
        $pipesB
    );

    $writerAResult = king_finish_semantic_dns_process($writerA, $pipesA);
    $writerBResult = king_finish_semantic_dns_process($writerB, $pipesB);

    $observerA = proc_open(
        king_build_semantic_dns_command($extensionPath, $observerScript, ['after-writers']),
        $descriptors,
        $observerPipesA
    );
    $observerAResult = king_finish_semantic_dns_process($observerA, $observerPipesA);
    $observerAPayload = json_decode(trim($observerAResult['stdout']), true);

    $observerB = proc_open(
        king_build_semantic_dns_command($extensionPath, $observerScript, ['after-restart']),
        $descriptors,
        $observerPipesB
    );
    $observerBResult = king_finish_semantic_dns_process($observerB, $observerPipesB);
    $observerBPayload = json_decode(trim($observerBResult['stdout']), true);

    var_dump($writerAResult['status'] === 0);
    var_dump($writerBResult['status'] === 0);
    var_dump(trim($writerAResult['stdout']) === 'writer-a-complete');
    var_dump(trim($writerBResult['stdout']) === 'writer-b-complete');
    var_dump(trim($writerAResult['stderr']) === '');
    var_dump(trim($writerBResult['stderr']) === '');
    var_dump($observerAResult['status'] === 0);
    var_dump(trim($observerAResult['stderr']) === '');
    var_dump($observerBResult['status'] === 0);
    var_dump(trim($observerBResult['stderr']) === '');
    var_dump(is_array($observerAPayload));
    var_dump(is_array($observerBPayload));
    var_dump(is_file($stateFile));
    var_dump(!is_link($stateFile));

    var_dump(($observerAPayload['statistics']['total_services'] ?? null) === 2);
    var_dump(($observerAPayload['statistics']['healthy_services'] ?? null) === 1);
    var_dump(($observerAPayload['statistics']['unhealthy_services'] ?? null) === 1);
    var_dump(($observerAPayload['statistics']['mother_nodes'] ?? null) === 3);
    var_dump(($observerAPayload['statistics']['discovered_mother_nodes'] ?? null) === 3);
    var_dump(($observerAPayload['statistics']['synced_mother_nodes'] ?? null) === 3);
    var_dump(($observerAPayload['discovery_count'] ?? null) === 1);
    var_dump(($observerAPayload['route']['service_id'] ?? null) === 'svc-east');
    var_dump(($observerAPayload['route']['status'] ?? null) === 'healthy');
    var_dump(($observerAPayload['services']['svc-east']['status'] ?? null) === 'healthy');
    var_dump(($observerAPayload['services']['svc-west']['status'] ?? null) === 'unhealthy');
    var_dump(($observerAPayload['shared_core']['status'] ?? null) === 'degraded');
    var_dump(($observerAPayload['shared_core']['managed_services_count'] ?? null) === 1);
    var_dump(abs((float) (($observerAPayload['shared_core']['trust_score'] ?? 0.0) - 0.41)) < 0.0001);
    var_dump(($observerAPayload['mother_west']['status'] ?? null) === 'degraded');

    var_dump(($observerBPayload['statistics']['healthy_services'] ?? null) === 1);
    var_dump(($observerBPayload['statistics']['unhealthy_services'] ?? null) === 1);
    var_dump(($observerBPayload['discovery_count'] ?? null) === 1);
    var_dump(($observerBPayload['route']['service_id'] ?? null) === 'svc-east');
    var_dump(($observerBPayload['services']['svc-west']['status'] ?? null) === 'unhealthy');
    var_dump(($observerBPayload['shared_core']['status'] ?? null) === 'degraded');
} finally {
    $eastCapture = king_semantic_dns_live_probe_stop_server($eastServer);
    $westCapture = king_semantic_dns_live_probe_stop_server($westServer);

    var_dump(($westCapture['request_count'] ?? 0) >= 2);

    if ($stateWasFile) {
        file_put_contents($stateFile, $stateBackup);
    } else {
        @unlink($stateFile);
    }
    if ($lockWasFile) {
        file_put_contents($lockFile, $lockBackup);
    } else {
        @unlink($lockFile);
    }
    if (!$stateDirExisted) {
        @rmdir($stateDir);
    }

    @unlink($writerAScript);
    @unlink($writerBScript);
    @unlink($observerScript);
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
