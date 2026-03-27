--TEST--
King Smart-DNS preserves coherent discovery and routing state across concurrent service and status writers
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$stateDir = '/tmp/king_semantic_dns_state';
$stateFile = $stateDir . '/durable_state.bin';
$lockFile = $stateFile . '.lock';
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$writerScript = sys_get_temp_dir() . '/king_sdns_concurrent_writer_' . getmypid() . '.php';
$observerScript = sys_get_temp_dir() . '/king_sdns_concurrent_observer_' . getmypid() . '.php';

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

file_put_contents($writerScript, <<<'PHP'
<?php
function king_require_true(bool $value, string $message): void
{
    if ($value !== true) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$serviceId = (string) $argv[1];
$serviceName = (string) $argv[2];
$finalStatus = (string) $argv[3];
$baseLoad = (int) $argv[4];
$baseConnections = (int) $argv[5];
$baseRequests = (int) $argv[6];
$sleepUs = (int) $argv[7];

king_require_true(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5453,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://concurrency-seed',
]), 'init failed');
king_require_true(king_semantic_dns_start_server(), 'start failed');
king_require_true(king_semantic_dns_register_service([
    'service_id' => $serviceId,
    'service_name' => $serviceName,
    'service_type' => 'http_server',
    'status' => 'healthy',
    'hostname' => $serviceId . '.internal',
    'port' => 8443,
    'current_load_percent' => 99,
    'active_connections' => 999,
    'total_requests' => 0,
]), 'register failed');

for ($i = 0; $i < 10; $i++) {
    $status = ($i === 9)
        ? $finalStatus
        : (($i % 2) === 0 ? 'healthy' : 'degraded');

    king_require_true(king_semantic_dns_update_service_status($serviceId, $status, [
        'current_load_percent' => $baseLoad + $i,
        'active_connections' => $baseConnections + $i,
        'total_requests' => $baseRequests + $i,
    ]), 'status update failed');

    usleep($sleepUs);
}

echo "writer-complete\n";
PHP
);

file_put_contents($observerScript, <<<'PHP'
<?php
king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5453,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://concurrency-seed',
]);
king_semantic_dns_start_server();

$topology = king_semantic_dns_get_service_topology();
$discovery = king_semantic_dns_discover_service('http_server');
$route = king_semantic_dns_get_optimal_route('global-chat-api');
$servicesById = [];

foreach ($topology['services'] as $service) {
    $servicesById[$service['service_id']] = [
        'status' => $service['status'],
        'current_load_percent' => $service['current_load_percent'],
        'active_connections' => $service['active_connections'],
        'total_requests' => $service['total_requests'],
    ];
}

ksort($servicesById);

echo json_encode([
    'statistics' => $topology['statistics'],
    'discovery_count' => $discovery['service_count'],
    'route_service_id' => $route['service_id'] ?? null,
    'services' => $servicesById,
], JSON_UNESCAPED_SLASHES), "\n";
PHP
);

function king_build_semantic_dns_command(
    string $phpBinary,
    string $extensionPath,
    string $scriptPath,
    array $args = []
): string {
    $parts = [
        escapeshellarg($phpBinary),
        '-n',
        '-d', escapeshellarg('extension=' . $extensionPath),
        '-d', escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg($scriptPath),
    ];

    foreach ($args as $arg) {
        $parts[] = escapeshellarg((string) $arg);
    }

    return implode(' ', $parts);
}

function king_spawn_semantic_dns_writer(string $command)
{
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    return proc_open($command, $descriptors, $pipes);
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

$writerACommand = king_build_semantic_dns_command(
    PHP_BINARY,
    $extensionPath,
    $writerScript,
    ['svc-a', 'global-chat-api', 'degraded', 40, 80, 2000, 12000]
);
$writerBCommand = king_build_semantic_dns_command(
    PHP_BINARY,
    $extensionPath,
    $writerScript,
    ['svc-b', 'global-chat-api', 'healthy', 5, 20, 3000, 9000]
);
$observerCommand = king_build_semantic_dns_command(
    PHP_BINARY,
    $extensionPath,
    $observerScript
);

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$writerA = proc_open($writerACommand, $descriptors, $pipesA);
$writerB = proc_open($writerBCommand, $descriptors, $pipesB);

$writerAResult = king_finish_semantic_dns_process($writerA, $pipesA);
$writerBResult = king_finish_semantic_dns_process($writerB, $pipesB);

$observer = proc_open($observerCommand, $descriptors, $observerPipes);
$observerResult = king_finish_semantic_dns_process($observer, $observerPipes);
$observerPayload = json_decode(trim($observerResult['stdout']), true);

var_dump($writerAResult['status'] === 0);
var_dump($writerBResult['status'] === 0);
var_dump(trim($writerAResult['stderr']) === '');
var_dump(trim($writerBResult['stderr']) === '');
var_dump($observerResult['status'] === 0);
var_dump(trim($observerResult['stderr']) === '');
var_dump(is_array($observerPayload));
var_dump(is_file($stateFile));
var_dump(!is_link($stateFile));
var_dump(($observerPayload['statistics']['total_services'] ?? null) === 2);
var_dump(($observerPayload['statistics']['healthy_services'] ?? null) === 1);
var_dump(($observerPayload['statistics']['degraded_services'] ?? null) === 1);
var_dump(($observerPayload['discovery_count'] ?? null) === 2);
var_dump(($observerPayload['route_service_id'] ?? null) === 'svc-b');
var_dump(($observerPayload['services']['svc-a']['status'] ?? null) === 'degraded');
var_dump(($observerPayload['services']['svc-a']['current_load_percent'] ?? null) === 49);
var_dump(($observerPayload['services']['svc-a']['active_connections'] ?? null) === 89);
var_dump(($observerPayload['services']['svc-a']['total_requests'] ?? null) === 2009);
var_dump(($observerPayload['services']['svc-b']['status'] ?? null) === 'healthy');
var_dump(($observerPayload['services']['svc-b']['current_load_percent'] ?? null) === 14);
var_dump(($observerPayload['services']['svc-b']['active_connections'] ?? null) === 29);
var_dump(($observerPayload['services']['svc-b']['total_requests'] ?? null) === 3009);

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

@unlink($writerScript);
@unlink($observerScript);
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
