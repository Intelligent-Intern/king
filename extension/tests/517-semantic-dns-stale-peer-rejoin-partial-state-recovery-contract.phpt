--TEST--
King Smart-DNS stale peers restore partial durable-state loss without overwriting newer shared topology
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) {
    echo "skip pcntl extension required for multi-process tests";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$stateDir = '/tmp/king_semantic_dns_state';
$stateFile = $stateDir . '/durable_state.bin';
$lockFile = $stateFile . '.lock';
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$fifoPath = sys_get_temp_dir() . '/king_sdns_rejoin_fifo_' . getmypid();
$readyPath = sys_get_temp_dir() . '/king_sdns_rejoin_ready_' . getmypid();
$stalePeerScript = sys_get_temp_dir() . '/king_sdns_stale_peer_' . getmypid() . '.php';
$currentWriterScript = sys_get_temp_dir() . '/king_sdns_current_writer_' . getmypid() . '.php';
$observerScript = sys_get_temp_dir() . '/king_sdns_rejoin_observer_' . getmypid() . '.php';

$stateDirExisted = is_dir($stateDir);
$stateWasFile = is_file($stateFile) && !is_link($stateFile);
$stateBackup = $stateWasFile ? file_get_contents($stateFile) : null;
$lockWasFile = is_file($lockFile) && !is_link($lockFile);
$lockBackup = $lockWasFile ? file_get_contents($lockFile) : null;

function king_semantic_dns_find_payload_offset(string $blob): array
{
    $blobLength = strlen($blob);

    for ($payloadOffset = 12; $payloadOffset <= $blobLength; $payloadOffset++) {
        $lengthBytes = substr($blob, $payloadOffset - 4, 4);
        $payloadLen = strlen($lengthBytes) === 4 ? unpack('Llen', $lengthBytes)['len'] : null;
        $serialized = '';
        $payload = null;

        if (!is_int($payloadLen) || $payloadOffset + $payloadLen !== $blobLength) {
            continue;
        }

        $serialized = substr($blob, $payloadOffset, $payloadLen);
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $payload = unserialize($serialized, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }

        if (
            is_array($payload)
            && isset($payload['services']) && is_array($payload['services'])
            && isset($payload['mother_nodes']) && is_array($payload['mother_nodes'])
        ) {
            return [$payloadOffset, $payload];
        }
    }

    throw new RuntimeException('Could not locate the Semantic-DNS serialized payload.');
}

function king_semantic_dns_rewrite_partial_payload(
    string $stateFile,
    array $dropServiceIds,
    array $dropMotherNodeIds
): void {
    $blob = (string) file_get_contents($stateFile);
    [$payloadOffset, $payload] = king_semantic_dns_find_payload_offset($blob);

    $payload['services'] = array_values(array_filter(
        $payload['services'],
        static function ($service) use ($dropServiceIds): bool {
            return !in_array((string) ($service['service_id'] ?? ''), $dropServiceIds, true);
        }
    ));
    $payload['mother_nodes'] = array_values(array_filter(
        $payload['mother_nodes'],
        static function ($motherNode) use ($dropMotherNodeIds): bool {
            return !in_array((string) ($motherNode['node_id'] ?? ''), $dropMotherNodeIds, true);
        }
    ));

    $serialized = serialize($payload);
    file_put_contents(
        $stateFile,
        substr($blob, 0, $payloadOffset - 4)
        . pack('L', strlen($serialized))
        . $serialized
    );
}

function king_semantic_dns_command(string $extensionPath, string $scriptPath, array $args = []): array
{
    $command = [
        PHP_BINARY,
        '-n',
        '-d', 'extension=' . $extensionPath,
        '-d', 'king.security_allow_config_override=1',
        $scriptPath,
    ];

    foreach ($args as $arg) {
        $command[] = (string) $arg;
    }

    return $command;
}

function king_semantic_dns_finish_process($process, array $pipes): array
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

if (!$stateDirExisted) {
    mkdir($stateDir, 0700, true);
}
chmod($stateDir, 0700);
@unlink($stateFile);
@unlink($lockFile);
@unlink($fifoPath);
@unlink($readyPath);
posix_mkfifo($fifoPath, 0600);

file_put_contents($stalePeerScript, <<<'PHP'
<?php
function king_sdns_require(bool $value, string $message): void
{
    if ($value !== true) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$fifoPath = (string) $argv[1];
$readyPath = (string) $argv[2];

king_sdns_require(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5457,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://rejoin-seed',
]), 'stale peer init failed');
king_sdns_require(king_semantic_dns_start_server(), 'stale peer start failed');

king_sdns_require(king_semantic_dns_register_service([
    'service_id' => 'svc-missing',
    'service_name' => 'global-chat-api',
    'service_type' => 'http_server',
    'hostname' => 'missing.internal',
    'port' => 8445,
    'status' => 'degraded',
    'current_load_percent' => 60,
    'active_connections' => 20,
    'total_requests' => 600,
]), 'stale peer missing register failed');
king_sdns_require(king_semantic_dns_register_service([
    'service_id' => 'svc-current',
    'service_name' => 'global-chat-api',
    'service_type' => 'http_server',
    'hostname' => 'current.internal',
    'port' => 8443,
    'status' => 'degraded',
    'current_load_percent' => 90,
    'active_connections' => 45,
    'total_requests' => 900,
]), 'stale peer current register failed');
king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'mother-missing',
    'hostname' => 'mother-missing.internal',
    'port' => 9558,
    'status' => 'healthy',
    'managed_services_count' => 1,
    'trust_score' => 0.44,
]), 'stale peer missing mother register failed');
king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'mother-shared',
    'hostname' => 'mother-shared.internal',
    'port' => 9559,
    'status' => 'healthy',
    'managed_services_count' => 2,
    'trust_score' => 0.80,
]), 'stale peer shared mother register failed');

file_put_contents($readyPath, "seeded\n");

$gate = fopen($fifoPath, 'rb');
fread($gate, 1);
fclose($gate);

king_sdns_require(king_semantic_dns_update_service_status('svc-missing', 'healthy', [
    'current_load_percent' => 12,
    'active_connections' => 4,
    'total_requests' => 1700,
]), 'stale peer rejoin update failed');

echo "stale-peer-complete\n";
PHP
);

file_put_contents($currentWriterScript, <<<'PHP'
<?php
function king_sdns_require(bool $value, string $message): void
{
    if ($value !== true) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

king_sdns_require(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5457,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://rejoin-seed',
]), 'current writer init failed');
king_sdns_require(king_semantic_dns_start_server(), 'current writer start failed');

king_sdns_require(king_semantic_dns_update_service_status('svc-current', 'healthy', [
    'current_load_percent' => 15,
    'active_connections' => 5,
    'total_requests' => 1500,
]), 'current writer current update failed');
king_sdns_require(king_semantic_dns_register_service([
    'service_id' => 'svc-fresh',
    'service_name' => 'global-chat-api',
    'service_type' => 'http_server',
    'hostname' => 'fresh.internal',
    'port' => 8446,
    'status' => 'healthy',
    'current_load_percent' => 3,
    'active_connections' => 1,
    'total_requests' => 1600,
]), 'current writer fresh register failed');
king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'mother-shared',
    'hostname' => 'mother-shared.internal',
    'port' => 9559,
    'status' => 'degraded',
    'managed_services_count' => 5,
    'trust_score' => 0.95,
]), 'current writer shared mother update failed');
king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'mother-fresh',
    'hostname' => 'mother-fresh.internal',
    'port' => 9560,
    'status' => 'healthy',
    'managed_services_count' => 3,
    'trust_score' => 0.66,
]), 'current writer fresh mother register failed');

echo "current-writer-complete\n";
PHP
);

file_put_contents($observerScript, <<<'PHP'
<?php
$mode = (string) $argv[1];

king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5457,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://rejoin-seed',
]);
king_semantic_dns_start_server();

$topology = king_semantic_dns_get_service_topology();
$discovery = king_semantic_dns_discover_service('http_server');
$route = king_semantic_dns_get_optimal_route('global-chat-api');
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
foreach ($topology['mother_nodes'] as $motherNode) {
    $motherById[$motherNode['node_id']] = [
        'status' => $motherNode['status'],
        'managed_services_count' => $motherNode['managed_services_count'],
        'trust_score' => $motherNode['trust_score'],
    ];
}
ksort($servicesById);
ksort($motherById);

echo json_encode([
    'mode' => $mode,
    'statistics' => $topology['statistics'],
    'discovery_count' => $discovery['service_count'],
    'route_service_id' => $route['service_id'] ?? null,
    'services' => $servicesById,
    'mother_nodes' => $motherById,
], JSON_UNESCAPED_SLASHES), "\n";
PHP
);

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

try {
    $stalePeer = proc_open(
        king_semantic_dns_command($extensionPath, $stalePeerScript, [$fifoPath, $readyPath]),
        $descriptors,
        $stalePeerPipes
    );

    for ($i = 0; $i < 200 && !is_file($readyPath); $i++) {
        usleep(10000);
    }

    $currentWriter = proc_open(
        king_semantic_dns_command($extensionPath, $currentWriterScript),
        $descriptors,
        $currentWriterPipes
    );
    $currentWriterResult = king_semantic_dns_finish_process($currentWriter, $currentWriterPipes);

    king_semantic_dns_rewrite_partial_payload(
        $stateFile,
        ['svc-missing'],
        ['mother-missing']
    );

    $partialObserver = proc_open(
        king_semantic_dns_command($extensionPath, $observerScript, ['partial-loss']),
        $descriptors,
        $partialObserverPipes
    );
    $partialObserverResult = king_semantic_dns_finish_process($partialObserver, $partialObserverPipes);
    $partialPayload = json_decode(trim($partialObserverResult['stdout']), true);

    $fifoWriter = fopen($fifoPath, 'wb');
    fwrite($fifoWriter, 'x');
    fclose($fifoWriter);

    $stalePeerResult = king_semantic_dns_finish_process($stalePeer, $stalePeerPipes);

    $rejoinObserver = proc_open(
        king_semantic_dns_command($extensionPath, $observerScript, ['after-rejoin']),
        $descriptors,
        $rejoinObserverPipes
    );
    $rejoinObserverResult = king_semantic_dns_finish_process($rejoinObserver, $rejoinObserverPipes);
    $rejoinPayload = json_decode(trim($rejoinObserverResult['stdout']), true);

    $restartObserver = proc_open(
        king_semantic_dns_command($extensionPath, $observerScript, ['after-restart']),
        $descriptors,
        $restartObserverPipes
    );
    $restartObserverResult = king_semantic_dns_finish_process($restartObserver, $restartObserverPipes);
    $restartPayload = json_decode(trim($restartObserverResult['stdout']), true);

    var_dump($currentWriterResult['status'] === 0);
    var_dump(trim($currentWriterResult['stdout']) === 'current-writer-complete');
    var_dump(trim($currentWriterResult['stderr']) === '');
    var_dump($partialObserverResult['status'] === 0);
    var_dump(trim($partialObserverResult['stderr']) === '');
    var_dump(is_array($partialPayload));
    var_dump(($partialPayload['statistics']['total_services'] ?? null) === 2);
    var_dump(($partialPayload['statistics']['mother_nodes'] ?? null) === 2);
    var_dump(($partialPayload['route_service_id'] ?? null) === 'svc-fresh');
    var_dump(!isset($partialPayload['services']['svc-missing']));
    var_dump(!isset($partialPayload['mother_nodes']['mother-missing']));

    var_dump($stalePeerResult['status'] === 0);
    var_dump(trim($stalePeerResult['stdout']) === 'stale-peer-complete');
    var_dump(trim($stalePeerResult['stderr']) === '');
    var_dump($rejoinObserverResult['status'] === 0);
    var_dump(trim($rejoinObserverResult['stderr']) === '');
    var_dump(is_array($rejoinPayload));
    var_dump(is_file($stateFile));
    var_dump(!is_link($stateFile));
    var_dump(($rejoinPayload['statistics']['total_services'] ?? null) === 3);
    var_dump(($rejoinPayload['statistics']['healthy_services'] ?? null) === 3);
    var_dump(($rejoinPayload['statistics']['mother_nodes'] ?? null) === 3);
    var_dump(($rejoinPayload['statistics']['discovered_mother_nodes'] ?? null) === 3);
    var_dump(($rejoinPayload['statistics']['synced_mother_nodes'] ?? null) === 3);
    var_dump(($rejoinPayload['discovery_count'] ?? null) === 3);
    var_dump(($rejoinPayload['route_service_id'] ?? null) === 'svc-fresh');
    var_dump(($rejoinPayload['services']['svc-current']['status'] ?? null) === 'healthy');
    var_dump(($rejoinPayload['services']['svc-current']['current_load_percent'] ?? null) === 15);
    var_dump(($rejoinPayload['services']['svc-current']['active_connections'] ?? null) === 5);
    var_dump(($rejoinPayload['services']['svc-current']['total_requests'] ?? null) === 1500);
    var_dump(($rejoinPayload['services']['svc-missing']['status'] ?? null) === 'healthy');
    var_dump(($rejoinPayload['services']['svc-missing']['current_load_percent'] ?? null) === 12);
    var_dump(($rejoinPayload['services']['svc-missing']['active_connections'] ?? null) === 4);
    var_dump(($rejoinPayload['services']['svc-missing']['total_requests'] ?? null) === 1700);
    var_dump(isset($rejoinPayload['services']['svc-fresh']));
    var_dump(($rejoinPayload['mother_nodes']['mother-shared']['status'] ?? null) === 'degraded');
    var_dump(abs((float) (($rejoinPayload['mother_nodes']['mother-shared']['trust_score'] ?? 0.0) - 0.95)) < 0.0001);
    var_dump(isset($rejoinPayload['mother_nodes']['mother-missing']));
    var_dump(abs((float) (($rejoinPayload['mother_nodes']['mother-missing']['trust_score'] ?? 0.0) - 0.44)) < 0.0001);
    var_dump(isset($rejoinPayload['mother_nodes']['mother-fresh']));

    var_dump($restartObserverResult['status'] === 0);
    var_dump(trim($restartObserverResult['stderr']) === '');
    var_dump(is_array($restartPayload));
    var_dump(($restartPayload['statistics']['total_services'] ?? null) === 3);
    var_dump(($restartPayload['statistics']['mother_nodes'] ?? null) === 3);
    var_dump(($restartPayload['route_service_id'] ?? null) === 'svc-fresh');
    var_dump(($restartPayload['services']['svc-current']['current_load_percent'] ?? null) === 15);
    var_dump(($restartPayload['services']['svc-missing']['status'] ?? null) === 'healthy');
    var_dump(($restartPayload['mother_nodes']['mother-shared']['status'] ?? null) === 'degraded');
    var_dump(isset($restartPayload['mother_nodes']['mother-missing']));
    var_dump(isset($restartPayload['mother_nodes']['mother-fresh']));
} finally {
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

    @unlink($fifoPath);
    @unlink($readyPath);
    @unlink($stalePeerScript);
    @unlink($currentWriterScript);
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
