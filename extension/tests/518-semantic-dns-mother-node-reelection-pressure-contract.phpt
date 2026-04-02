--TEST--
King Smart-DNS preserves counters and route stability across mother-node departure replacement and rejoin pressure
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$stateDir = '/tmp/king_semantic_dns_state';
$stateFile = $stateDir . '/durable_state.bin';
$lockFile = $stateFile . '.lock';
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$fifoPath = sys_get_temp_dir() . '/king_sdns_reelection_fifo_' . getmypid();
$readyPath = sys_get_temp_dir() . '/king_sdns_reelection_ready_' . getmypid();
$stalePeerScript = sys_get_temp_dir() . '/king_sdns_reelection_stale_' . getmypid() . '.php';
$rejoinWriterScript = sys_get_temp_dir() . '/king_sdns_reelection_rejoin_' . getmypid() . '.php';
$observerScript = sys_get_temp_dir() . '/king_sdns_reelection_observer_' . getmypid() . '.php';

$stateDirExisted = is_dir($stateDir);
$stateWasFile = is_file($stateFile) && !is_link($stateFile);
$stateBackup = $stateWasFile ? file_get_contents($stateFile) : null;
$lockWasFile = is_file($lockFile) && !is_link($lockFile);
$lockBackup = $lockWasFile ? file_get_contents($lockFile) : null;

function king_semantic_dns_518_find_payload_offset(string $blob): array
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

function king_semantic_dns_518_apply_re_election_payload(string $stateFile): void
{
    $blob = (string) file_get_contents($stateFile);
    [$payloadOffset, $payload] = king_semantic_dns_518_find_payload_offset($blob);

    $payload['mother_nodes'] = array_values(array_filter(
        $payload['mother_nodes'],
        static function (array $motherNode): bool {
            return (string) ($motherNode['node_id'] ?? '') !== 'leader-a';
        }
    ));
    $payload['mother_nodes'][] = [
        'node_id' => 'leader-b',
        'hostname' => 'leader-b.internal',
        'port' => 9559,
        'status' => 'healthy',
        'managed_services_count' => 6,
        'trust_score' => 0.81,
        'registered_at' => time(),
    ];
    $payload['mother_node_tombstones'] = ['leader-a'];

    $serialized = serialize($payload);
    file_put_contents(
        $stateFile,
        substr($blob, 0, $payloadOffset - 4)
        . pack('L', strlen($serialized))
        . $serialized
    );
}

function king_semantic_dns_518_command(string $extensionPath, string $scriptPath, array $args = []): array
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

function king_semantic_dns_518_finish_process($process, array $pipes): array
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
    'dns_port' => 5458,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://reelection-seed',
]), 'stale peer init failed');
king_sdns_require(king_semantic_dns_start_server(), 'stale peer start failed');

king_sdns_require(king_semantic_dns_register_service([
    'service_id' => 'svc-primary',
    'service_name' => 'chat-router',
    'service_type' => 'http_server',
    'hostname' => '10.10.0.10',
    'port' => 8443,
    'status' => 'healthy',
    'current_load_percent' => 4,
    'active_connections' => 3,
    'total_requests' => 1000,
]), 'primary register failed');
king_sdns_require(king_semantic_dns_register_service([
    'service_id' => 'svc-secondary',
    'service_name' => 'chat-router',
    'service_type' => 'http_server',
    'hostname' => '10.10.0.11',
    'port' => 8444,
    'status' => 'healthy',
    'current_load_percent' => 35,
    'active_connections' => 20,
    'total_requests' => 800,
]), 'secondary register failed');

king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'leader-a',
    'hostname' => 'leader-a.internal',
    'port' => 9558,
    'status' => 'healthy',
    'managed_services_count' => 5,
    'trust_score' => 0.92,
]), 'leader-a register failed');
king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'stable-peer',
    'hostname' => 'stable-peer.internal',
    'port' => 9560,
    'status' => 'healthy',
    'managed_services_count' => 4,
    'trust_score' => 0.71,
]), 'stable-peer register failed');

file_put_contents($readyPath, "seeded\n");

$gate = fopen($fifoPath, 'rb');
fread($gate, 1);
fclose($gate);

king_sdns_require(king_semantic_dns_update_service_status('svc-primary', 'healthy', [
    'current_load_percent' => 5,
    'active_connections' => 4,
    'total_requests' => 1100,
]), 'stale peer update failed');

echo "stale-peer-complete\n";
PHP
);

file_put_contents($rejoinWriterScript, <<<'PHP'
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
    'dns_port' => 5458,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://reelection-seed',
]), 'rejoin writer init failed');
king_sdns_require(king_semantic_dns_start_server(), 'rejoin writer start failed');

king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'leader-a',
    'hostname' => 'leader-a-return.internal',
    'port' => 9561,
    'status' => 'healthy',
    'managed_services_count' => 2,
    'trust_score' => 0.57,
]), 'leader-a rejoin register failed');

echo "rejoin-writer-complete\n";
PHP
);

file_put_contents($observerScript, <<<'PHP'
<?php
$mode = (string) $argv[1];

king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5458,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://reelection-seed',
]);
king_semantic_dns_start_server();

$topology = king_semantic_dns_get_service_topology();
$discovery = king_semantic_dns_discover_service('http_server');
$route = king_semantic_dns_get_optimal_route('chat-router');
$motherById = [];

foreach ($topology['mother_nodes'] as $motherNode) {
    $motherById[$motherNode['node_id']] = [
        'hostname' => $motherNode['hostname'],
        'status' => $motherNode['status'],
        'managed_services_count' => $motherNode['managed_services_count'],
        'trust_score' => $motherNode['trust_score'],
    ];
}
ksort($motherById);

echo json_encode([
    'mode' => $mode,
    'statistics' => $topology['statistics'],
    'discovery_count' => $discovery['service_count'],
    'route_service_id' => $route['service_id'] ?? null,
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
        king_semantic_dns_518_command($extensionPath, $stalePeerScript, [$fifoPath, $readyPath]),
        $descriptors,
        $stalePeerPipes
    );

    for ($i = 0; $i < 200 && !is_file($readyPath); $i++) {
        usleep(10000);
    }

    king_semantic_dns_518_apply_re_election_payload($stateFile);

    $replacementObserver = proc_open(
        king_semantic_dns_518_command($extensionPath, $observerScript, ['replacement']),
        $descriptors,
        $replacementObserverPipes
    );
    $replacementObserverResult = king_semantic_dns_518_finish_process($replacementObserver, $replacementObserverPipes);
    $replacementPayload = json_decode(trim($replacementObserverResult['stdout']), true);

    $fifoWriter = fopen($fifoPath, 'wb');
    fwrite($fifoWriter, 'x');
    fclose($fifoWriter);

    $stalePeerResult = king_semantic_dns_518_finish_process($stalePeer, $stalePeerPipes);

    $postStaleObserver = proc_open(
        king_semantic_dns_518_command($extensionPath, $observerScript, ['post-stale']),
        $descriptors,
        $postStaleObserverPipes
    );
    $postStaleObserverResult = king_semantic_dns_518_finish_process($postStaleObserver, $postStaleObserverPipes);
    $postStalePayload = json_decode(trim($postStaleObserverResult['stdout']), true);

    $rejoinWriter = proc_open(
        king_semantic_dns_518_command($extensionPath, $rejoinWriterScript),
        $descriptors,
        $rejoinWriterPipes
    );
    $rejoinWriterResult = king_semantic_dns_518_finish_process($rejoinWriter, $rejoinWriterPipes);

    $rejoinObserver = proc_open(
        king_semantic_dns_518_command($extensionPath, $observerScript, ['post-rejoin']),
        $descriptors,
        $rejoinObserverPipes
    );
    $rejoinObserverResult = king_semantic_dns_518_finish_process($rejoinObserver, $rejoinObserverPipes);
    $rejoinPayload = json_decode(trim($rejoinObserverResult['stdout']), true);

    $restartObserver = proc_open(
        king_semantic_dns_518_command($extensionPath, $observerScript, ['after-restart']),
        $descriptors,
        $restartObserverPipes
    );
    $restartObserverResult = king_semantic_dns_518_finish_process($restartObserver, $restartObserverPipes);
    $restartPayload = json_decode(trim($restartObserverResult['stdout']), true);

    var_dump($replacementObserverResult['status'] === 0);
    var_dump(trim($replacementObserverResult['stderr']) === '');
    var_dump(is_array($replacementPayload));
    var_dump(($replacementPayload['statistics']['mother_nodes'] ?? null) === 2);
    var_dump(($replacementPayload['statistics']['discovered_mother_nodes'] ?? null) === 2);
    var_dump(($replacementPayload['statistics']['synced_mother_nodes'] ?? null) === 2);
    var_dump(($replacementPayload['route_service_id'] ?? null) === 'svc-primary');
    var_dump(isset($replacementPayload['mother_nodes']['leader-b']));
    var_dump(isset($replacementPayload['mother_nodes']['stable-peer']));
    var_dump(!isset($replacementPayload['mother_nodes']['leader-a']));

    var_dump($stalePeerResult['status'] === 0);
    var_dump(trim($stalePeerResult['stdout']) === 'stale-peer-complete');
    var_dump(trim($stalePeerResult['stderr']) === '');
    var_dump($postStaleObserverResult['status'] === 0);
    var_dump(trim($postStaleObserverResult['stderr']) === '');
    var_dump(is_array($postStalePayload));
    var_dump(is_file($stateFile));
    var_dump(!is_link($stateFile));
    var_dump(($postStalePayload['statistics']['mother_nodes'] ?? null) === 2);
    var_dump(($postStalePayload['statistics']['discovered_mother_nodes'] ?? null) === 2);
    var_dump(($postStalePayload['statistics']['synced_mother_nodes'] ?? null) === 2);
    var_dump(($postStalePayload['discovery_count'] ?? null) === 2);
    var_dump(($postStalePayload['route_service_id'] ?? null) === 'svc-primary');
    var_dump(isset($postStalePayload['mother_nodes']['leader-b']));
    var_dump(isset($postStalePayload['mother_nodes']['stable-peer']));
    var_dump(!isset($postStalePayload['mother_nodes']['leader-a']));
    var_dump(($postStalePayload['mother_nodes']['leader-b']['hostname'] ?? null) === 'leader-b.internal');

    var_dump($rejoinWriterResult['status'] === 0);
    var_dump(trim($rejoinWriterResult['stdout']) === 'rejoin-writer-complete');
    var_dump(trim($rejoinWriterResult['stderr']) === '');
    var_dump($rejoinObserverResult['status'] === 0);
    var_dump(trim($rejoinObserverResult['stderr']) === '');
    var_dump(is_array($rejoinPayload));
    var_dump(($rejoinPayload['statistics']['mother_nodes'] ?? null) === 3);
    var_dump(($rejoinPayload['statistics']['discovered_mother_nodes'] ?? null) === 3);
    var_dump(($rejoinPayload['statistics']['synced_mother_nodes'] ?? null) === 3);
    var_dump(($rejoinPayload['route_service_id'] ?? null) === 'svc-primary');
    var_dump(isset($rejoinPayload['mother_nodes']['leader-a']));
    var_dump(($rejoinPayload['mother_nodes']['leader-a']['hostname'] ?? null) === 'leader-a-return.internal');
    var_dump(abs((float) (($rejoinPayload['mother_nodes']['leader-a']['trust_score'] ?? 0.0) - 0.57)) < 0.0001);
    var_dump(isset($rejoinPayload['mother_nodes']['leader-b']));
    var_dump(isset($rejoinPayload['mother_nodes']['stable-peer']));

    var_dump($restartObserverResult['status'] === 0);
    var_dump(trim($restartObserverResult['stderr']) === '');
    var_dump(is_array($restartPayload));
    var_dump(($restartPayload['statistics']['mother_nodes'] ?? null) === 3);
    var_dump(($restartPayload['statistics']['discovered_mother_nodes'] ?? null) === 3);
    var_dump(($restartPayload['statistics']['synced_mother_nodes'] ?? null) === 3);
    var_dump(($restartPayload['route_service_id'] ?? null) === 'svc-primary');
    var_dump(isset($restartPayload['mother_nodes']['leader-a']));
    var_dump(isset($restartPayload['mother_nodes']['leader-b']));
    var_dump(isset($restartPayload['mother_nodes']['stable-peer']));
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
    @unlink($rejoinWriterScript);
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
bool(true)
