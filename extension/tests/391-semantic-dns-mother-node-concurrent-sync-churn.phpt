--TEST--
King Smart-DNS keeps mother-node sync statistics coherent under concurrent larger-topology churn
--SKIPIF--
<?php
if (!function_exists('proc_open')) {
    echo "skip proc_open is required for mother-node concurrent sync tests";
}
if (!function_exists('pcntl_signal')) {
    echo "skip pcntl is required for process management in concurrent DNS tests";
}
if (!extension_loaded('king')) {
    echo "skip king extension is required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/semantic_dns_wire_helper.inc';

$dnsPort = king_semantic_dns_wire_allocate_udp_port();
$stateDir = '/tmp/king_semantic_dns_state';
$stateFile = $stateDir . '/durable_state.bin';
$lockFile = $stateFile . '.lock';
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$writerAScript = sys_get_temp_dir() . '/king_sdns_mother_writer_a_' . getmypid() . '.php';
$writerBScript = sys_get_temp_dir() . '/king_sdns_mother_writer_b_' . getmypid() . '.php';
$observerScript = sys_get_temp_dir() . '/king_sdns_mother_observer_' . getmypid() . '.php';

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

$writerATemplate = <<<'PHP'
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
    'dns_port' => __DNS_PORT__,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://sync-large-topology',
]), 'init failed');
king_sdns_require(king_semantic_dns_start_server(), 'start failed');

for ($i = 0; $i < 10; $i++) {
    king_sdns_require(king_semantic_dns_register_mother_node([
        'node_id' => sprintf('north-%02d', $i),
        'hostname' => sprintf('north-%02d.internal', $i),
        'port' => 9500 + $i,
        'status' => ($i % 2 === 0) ? 'healthy' : 'degraded',
        'managed_services_count' => 20 + $i,
        'trust_score' => 0.95 - ($i * 0.03),
    ]), 'north register failed');
    usleep(15000);
}

king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'shared-west',
    'hostname' => 'shared-west.internal',
    'port' => 9701,
    'status' => 'healthy',
    'managed_services_count' => 41,
    'trust_score' => 0.71,
]), 'shared-west register failed');
usleep(15000);
king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'shared-east',
    'hostname' => 'shared-east.internal',
    'port' => 9702,
    'status' => 'degraded',
    'managed_services_count' => 52,
    'trust_score' => 0.62,
]), 'shared-east register failed');

echo "writer-a-complete\n";
PHP
;
file_put_contents($writerAScript, str_replace('__DNS_PORT__', (string) $dnsPort, $writerATemplate));

$writerBTemplate = <<<'PHP'
<?php
function king_sdns_require(bool $value, string $message): void
{
    if ($value !== true) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

usleep(40000);

king_sdns_require(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => __DNS_PORT__,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://sync-large-topology',
]), 'init failed');
king_sdns_require(king_semantic_dns_start_server(), 'start failed');

for ($i = 0; $i < 10; $i++) {
    king_sdns_require(king_semantic_dns_register_mother_node([
        'node_id' => sprintf('south-%02d', $i),
        'hostname' => sprintf('south-%02d.internal', $i),
        'port' => 9600 + $i,
        'status' => ($i % 3 === 0) ? 'healthy' : 'unknown',
        'managed_services_count' => 40 + $i,
        'trust_score' => 0.88 - ($i * 0.02),
    ]), 'south register failed');
    usleep(15000);
}

king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'shared-west',
    'hostname' => 'shared-west.internal',
    'port' => 9701,
    'status' => 'degraded',
    'managed_services_count' => 88,
    'trust_score' => 0.42,
]), 'shared-west overwrite failed');
usleep(15000);
king_sdns_require(king_semantic_dns_register_mother_node([
    'node_id' => 'shared-east',
    'hostname' => 'shared-east.internal',
    'port' => 9702,
    'status' => 'healthy',
    'managed_services_count' => 99,
    'trust_score' => 0.93,
]), 'shared-east overwrite failed');

echo "writer-b-complete\n";
PHP
;
file_put_contents($writerBScript, str_replace('__DNS_PORT__', (string) $dnsPort, $writerBTemplate));

$observerTemplate = <<<'PHP'
<?php
king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => __DNS_PORT__,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://sync-large-topology',
]);
king_semantic_dns_start_server();

$topology = king_semantic_dns_get_service_topology();
$motherById = [];
foreach ($topology['mother_nodes'] as $mother) {
    $motherById[$mother['node_id']] = [
        'status' => $mother['status'],
        'managed_services_count' => $mother['managed_services_count'],
        'trust_score' => $mother['trust_score'],
    ];
}
ksort($motherById);

echo json_encode([
    'statistics' => $topology['statistics'],
    'mother_count' => count($topology['mother_nodes']),
    'mother_ids' => array_keys($motherById),
    'shared_west' => $motherById['shared-west'] ?? null,
    'shared_east' => $motherById['shared-east'] ?? null,
], JSON_UNESCAPED_SLASHES), "\n";
PHP
;
file_put_contents($observerScript, str_replace('__DNS_PORT__', (string) $dnsPort, $observerTemplate));

function king_build_sdns_command(string $phpBinary, string $extensionPath, string $scriptPath): array
{
    return [
        $phpBinary,
        '-n',
        '-d', 'extension=' . $extensionPath,
        '-d', 'king.security_allow_config_override=1',
        $scriptPath,
    ];
}

function king_finish_sdns_process($process, array $pipes): array
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

$writerA = proc_open(king_build_sdns_command(PHP_BINARY, $extensionPath, $writerAScript), $descriptors, $pipesA);
$writerB = proc_open(king_build_sdns_command(PHP_BINARY, $extensionPath, $writerBScript), $descriptors, $pipesB);

$writerAResult = king_finish_sdns_process($writerA, $pipesA);
$writerBResult = king_finish_sdns_process($writerB, $pipesB);

$observer = proc_open(king_build_sdns_command(PHP_BINARY, $extensionPath, $observerScript), $descriptors, $observerPipes);
$observerResult = king_finish_sdns_process($observer, $observerPipes);
$observerPayload = json_decode(trim($observerResult['stdout']), true);

var_dump($writerAResult['status'] === 0);
var_dump($writerBResult['status'] === 0);
var_dump(trim($writerAResult['stdout']) === 'writer-a-complete');
var_dump(trim($writerBResult['stdout']) === 'writer-b-complete');
var_dump(trim($writerAResult['stderr']) === '');
var_dump(trim($writerBResult['stderr']) === '');
var_dump($observerResult['status'] === 0);
var_dump(trim($observerResult['stderr']) === '');
var_dump(is_array($observerPayload));
var_dump(is_file($stateFile));
var_dump(!is_link($stateFile));
var_dump(($observerPayload['mother_count'] ?? null) === 22);
var_dump(($observerPayload['statistics']['mother_nodes'] ?? null) === 22);
var_dump(($observerPayload['statistics']['discovered_mother_nodes'] ?? null) === 22);
var_dump(($observerPayload['statistics']['synced_mother_nodes'] ?? null) === 22);
var_dump(count($observerPayload['mother_ids'] ?? []) === 22);
var_dump(($observerPayload['mother_ids'][0] ?? null) === 'north-00');
var_dump(in_array('south-09', $observerPayload['mother_ids'] ?? [], true));
var_dump(($observerPayload['shared_west']['status'] ?? null) === 'degraded');
var_dump(($observerPayload['shared_west']['managed_services_count'] ?? null) === 88);
var_dump(abs((float) (($observerPayload['shared_west']['trust_score'] ?? 0.0) - 0.42)) < 0.0001);
var_dump(($observerPayload['shared_east']['status'] ?? null) === 'healthy');
var_dump(($observerPayload['shared_east']['managed_services_count'] ?? null) === 99);
var_dump(abs((float) (($observerPayload['shared_east']['trust_score'] ?? 0.0) - 0.93)) < 0.0001);

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
