--TEST--
King Semantic-DNS keeps real network listener discovery coherent through distributed split-brain writes, partial state loss, and stale-peer rejoin recovery
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/semantic_dns_wire_helper.inc';

function king_semantic_dns_647_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_semantic_dns_647_find_payload_offset(string $blob): array
{
    $blobLength = strlen($blob);

    for ($payloadOffset = 12; $payloadOffset <= $blobLength; $payloadOffset++) {
        $lengthBytes = substr($blob, $payloadOffset - 4, 4);
        $payloadLen = strlen($lengthBytes) === 4 ? unpack('Llen', $lengthBytes)['len'] : null;
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

function king_semantic_dns_647_rewrite_partial_payload(
    string $stateFile,
    array $dropServiceIds,
    array $dropMotherNodeIds
): void {
    $blob = (string) file_get_contents($stateFile);
    [$payloadOffset, $payload] = king_semantic_dns_647_find_payload_offset($blob);

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

function king_semantic_dns_647_command(string $extensionPath, string $scriptPath, array $args = []): array
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

function king_semantic_dns_647_finish_process($process, array $pipes): array
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

$stateDir = '/tmp/king_semantic_dns_state';
$stateFile = $stateDir . '/durable_state.bin';
$lockFile = $stateFile . '.lock';
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$wireHelperPath = __DIR__ . '/semantic_dns_wire_helper.inc';
$dnsPort = king_semantic_dns_wire_allocate_udp_port();

$writerAScript = sys_get_temp_dir() . '/king_sdns_647_writer_a_' . getmypid() . '.php';
$writerBScript = sys_get_temp_dir() . '/king_sdns_647_writer_b_' . getmypid() . '.php';
$stalePeerScript = sys_get_temp_dir() . '/king_sdns_647_stale_rejoin_' . getmypid() . '.php';
$observerScript = sys_get_temp_dir() . '/king_sdns_647_observer_' . getmypid() . '.php';

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

file_put_contents($writerAScript, <<<'PHP'
<?php
function king_sdns_647_require(bool $value, string $message): void
{
    if ($value !== true) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$dnsPort = (int) $argv[1];

king_sdns_647_require(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => $dnsPort,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://network-recovery-seed',
]), 'writer-a init failed');
king_sdns_647_require(king_semantic_dns_start_server(), 'writer-a start failed');

king_sdns_647_require(king_semantic_dns_register_service([
    'service_id' => 'svc-east',
    'service_name' => 'edge-api',
    'service_type' => 'http_server',
    'hostname' => '10.30.0.11',
    'port' => 8443,
    'status' => 'healthy',
    'current_load_percent' => 22,
    'active_connections' => 12,
    'total_requests' => 1200,
    'attributes' => [
        'region' => 'eu-central',
        'topology_scope' => 'writer-a',
    ],
]), 'writer-a east register failed');

king_sdns_647_require(king_semantic_dns_register_service([
    'service_id' => 'svc-west',
    'service_name' => 'edge-api',
    'service_type' => 'http_server',
    'hostname' => '10.30.0.12',
    'port' => 8443,
    'status' => 'degraded',
    'current_load_percent' => 70,
    'active_connections' => 40,
    'total_requests' => 800,
    'attributes' => [
        'region' => 'us-west',
        'topology_scope' => 'writer-a',
    ],
]), 'writer-a west register failed');

king_sdns_647_require(king_semantic_dns_register_mother_node([
    'node_id' => 'mother-east',
    'hostname' => 'mother-east.internal',
    'port' => 9555,
    'status' => 'healthy',
    'managed_services_count' => 1,
    'trust_score' => 0.88,
]), 'writer-a mother-east register failed');

king_sdns_647_require(king_semantic_dns_register_mother_node([
    'node_id' => 'shared-core',
    'hostname' => 'shared-core.internal',
    'port' => 9556,
    'status' => 'healthy',
    'managed_services_count' => 2,
    'trust_score' => 0.80,
]), 'writer-a shared-core register failed');

echo "writer-a-complete\n";
PHP
);

file_put_contents($writerBScript, <<<'PHP'
<?php
function king_sdns_647_require(bool $value, string $message): void
{
    if ($value !== true) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$dnsPort = (int) $argv[1];
usleep(70000);

king_sdns_647_require(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => $dnsPort,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://network-recovery-seed',
]), 'writer-b init failed');
king_sdns_647_require(king_semantic_dns_start_server(), 'writer-b start failed');

king_sdns_647_require(king_semantic_dns_register_service([
    'service_id' => 'svc-east',
    'service_name' => 'edge-api',
    'service_type' => 'http_server',
    'hostname' => '10.30.0.11',
    'port' => 8443,
    'status' => 'healthy',
    'current_load_percent' => 12,
    'active_connections' => 6,
    'total_requests' => 1500,
    'attributes' => [
        'region' => 'eu-central',
        'topology_scope' => 'writer-b',
    ],
]), 'writer-b east overwrite failed');

king_sdns_647_require(king_semantic_dns_register_service([
    'service_id' => 'svc-west',
    'service_name' => 'edge-api',
    'service_type' => 'http_server',
    'hostname' => '10.30.0.12',
    'port' => 8443,
    'status' => 'healthy',
    'current_load_percent' => 35,
    'active_connections' => 18,
    'total_requests' => 1400,
    'attributes' => [
        'region' => 'us-west',
        'topology_scope' => 'writer-b',
    ],
]), 'writer-b west overwrite failed');

king_sdns_647_require(king_semantic_dns_register_mother_node([
    'node_id' => 'mother-west',
    'hostname' => 'mother-west.internal',
    'port' => 9557,
    'status' => 'degraded',
    'managed_services_count' => 1,
    'trust_score' => 0.33,
]), 'writer-b mother-west register failed');

king_sdns_647_require(king_semantic_dns_register_mother_node([
    'node_id' => 'shared-core',
    'hostname' => 'shared-core.internal',
    'port' => 9556,
    'status' => 'degraded',
    'managed_services_count' => 1,
    'trust_score' => 0.41,
]), 'writer-b shared-core overwrite failed');

echo "writer-b-complete\n";
PHP
);

file_put_contents($stalePeerScript, <<<'PHP'
<?php
function king_sdns_647_require(bool $value, string $message): void
{
    if ($value !== true) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$dnsPort = (int) $argv[1];

king_sdns_647_require(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => $dnsPort,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://network-recovery-seed',
]), 'stale-rejoin init failed');
king_sdns_647_require(king_semantic_dns_start_server(), 'stale-rejoin start failed');

king_sdns_647_require(king_semantic_dns_register_service([
    'service_id' => 'svc-east',
    'service_name' => 'edge-api',
    'service_type' => 'http_server',
    'hostname' => '10.30.0.11',
    'port' => 8443,
    'status' => 'healthy',
    'current_load_percent' => 14,
    'active_connections' => 7,
    'total_requests' => 1700,
    'attributes' => [
        'region' => 'eu-central',
        'topology_scope' => 'stale-rejoin',
    ],
]), 'stale-rejoin east restore failed');

king_sdns_647_require(king_semantic_dns_register_mother_node([
    'node_id' => 'mother-east',
    'hostname' => 'mother-east.internal',
    'port' => 9555,
    'status' => 'healthy',
    'managed_services_count' => 1,
    'trust_score' => 0.77,
]), 'stale-rejoin mother-east restore failed');

echo "stale-rejoin-complete\n";
PHP
);

file_put_contents($observerScript, <<<'PHP'
<?php
$mode = (string) $argv[1];
$dnsPort = (int) $argv[2];
$wireHelperPath = (string) $argv[3];

require $wireHelperPath;

king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => $dnsPort,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://network-recovery-seed',
]);
king_semantic_dns_start_server();

$topology = king_semantic_dns_get_service_topology();
$discovery = king_semantic_dns_discover_service('http_server');
$route = king_semantic_dns_get_optimal_route('edge-api');
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

$wire = king_semantic_dns_wire_query('127.0.0.1', $dnsPort, 'edge-api');
$wireParsed = null;
if (!($wire['timed_out'] ?? true)) {
    $wireParsed = king_semantic_dns_wire_parse_response((string) ($wire['response'] ?? ''));
    if (is_array($wireParsed) && isset($wireParsed['answers']) && is_array($wireParsed['answers'])) {
        sort($wireParsed['answers']);
    }
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
    'wire' => [
        'timed_out' => $wire['timed_out'] ?? null,
        'parsed' => $wireParsed,
    ],
], JSON_UNESCAPED_SLASHES), "\n";
PHP
);

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

try {
    $writerA = proc_open(
        king_semantic_dns_647_command($extensionPath, $writerAScript, [$dnsPort]),
        $descriptors,
        $writerAPipes
    );
    $writerB = proc_open(
        king_semantic_dns_647_command($extensionPath, $writerBScript, [$dnsPort]),
        $descriptors,
        $writerBPipes
    );

    $writerAResult = king_semantic_dns_647_finish_process($writerA, $writerAPipes);
    $writerBResult = king_semantic_dns_647_finish_process($writerB, $writerBPipes);

    king_semantic_dns_647_assert($writerAResult['status'] === 0, 'writer-a process failed');
    king_semantic_dns_647_assert($writerBResult['status'] === 0, 'writer-b process failed');
    king_semantic_dns_647_assert(trim($writerAResult['stdout']) === 'writer-a-complete', 'writer-a stdout drifted');
    king_semantic_dns_647_assert(trim($writerBResult['stdout']) === 'writer-b-complete', 'writer-b stdout drifted');
    king_semantic_dns_647_assert(trim($writerAResult['stderr']) === '', 'writer-a stderr drifted');
    king_semantic_dns_647_assert(trim($writerBResult['stderr']) === '', 'writer-b stderr drifted');
    king_semantic_dns_647_assert(is_file($stateFile) && !is_link($stateFile), 'durable state file missing after distributed writers');

    $observerAfterWriters = proc_open(
        king_semantic_dns_647_command($extensionPath, $observerScript, ['after-writers', $dnsPort, $wireHelperPath]),
        $descriptors,
        $observerAfterWritersPipes
    );
    $afterWritersResult = king_semantic_dns_647_finish_process($observerAfterWriters, $observerAfterWritersPipes);
    $afterWriters = json_decode(trim($afterWritersResult['stdout']), true);

    king_semantic_dns_647_assert($afterWritersResult['status'] === 0, 'after-writers observer failed');
    king_semantic_dns_647_assert(trim($afterWritersResult['stderr']) === '', 'after-writers observer wrote stderr');
    king_semantic_dns_647_assert(is_array($afterWriters), 'after-writers observer did not emit JSON');
    king_semantic_dns_647_assert(($afterWriters['statistics']['total_services'] ?? null) === 2, 'after-writers total_services drifted');
    king_semantic_dns_647_assert(($afterWriters['statistics']['healthy_services'] ?? null) === 2, 'after-writers healthy_services drifted');
    king_semantic_dns_647_assert(($afterWriters['statistics']['mother_nodes'] ?? null) === 3, 'after-writers mother_nodes drifted');
    king_semantic_dns_647_assert(($afterWriters['statistics']['discovered_mother_nodes'] ?? null) === 3, 'after-writers discovered_mother_nodes drifted');
    king_semantic_dns_647_assert(($afterWriters['statistics']['synced_mother_nodes'] ?? null) === 3, 'after-writers synced_mother_nodes drifted');
    king_semantic_dns_647_assert(($afterWriters['discovery_count'] ?? null) === 2, 'after-writers discovery_count drifted');
    king_semantic_dns_647_assert(($afterWriters['route_service_id'] ?? null) === 'svc-east', 'after-writers route drifted');
    king_semantic_dns_647_assert(($afterWriters['services']['svc-east']['status'] ?? null) === 'healthy', 'after-writers svc-east status drifted');
    king_semantic_dns_647_assert(($afterWriters['services']['svc-west']['status'] ?? null) === 'healthy', 'after-writers svc-west status drifted');
    king_semantic_dns_647_assert(($afterWriters['services']['svc-west']['current_load_percent'] ?? null) === 35, 'after-writers svc-west load drifted');
    king_semantic_dns_647_assert(($afterWriters['mother_nodes']['shared-core']['status'] ?? null) === 'degraded', 'after-writers shared-core status drifted');
    king_semantic_dns_647_assert(abs((float) (($afterWriters['mother_nodes']['shared-core']['trust_score'] ?? 0.0) - 0.41)) < 0.0001, 'after-writers shared-core trust_score drifted');
    king_semantic_dns_647_assert(($afterWriters['wire']['timed_out'] ?? true) === false, 'after-writers wire query timed out');
    king_semantic_dns_647_assert(($afterWriters['wire']['parsed']['rcode'] ?? null) === 0, 'after-writers wire rcode drifted');
    king_semantic_dns_647_assert(($afterWriters['wire']['parsed']['answer_count'] ?? null) === 2, 'after-writers wire answer_count drifted');
    king_semantic_dns_647_assert(($afterWriters['wire']['parsed']['answers'] ?? null) === ['10.30.0.11', '10.30.0.12'], 'after-writers wire answers drifted');

    king_semantic_dns_647_rewrite_partial_payload($stateFile, ['svc-east'], ['mother-east']);

    $observerAfterPartial = proc_open(
        king_semantic_dns_647_command($extensionPath, $observerScript, ['after-partial-loss', $dnsPort, $wireHelperPath]),
        $descriptors,
        $observerAfterPartialPipes
    );
    $afterPartialResult = king_semantic_dns_647_finish_process($observerAfterPartial, $observerAfterPartialPipes);
    $afterPartial = json_decode(trim($afterPartialResult['stdout']), true);

    king_semantic_dns_647_assert($afterPartialResult['status'] === 0, 'after-partial observer failed');
    king_semantic_dns_647_assert(trim($afterPartialResult['stderr']) === '', 'after-partial observer wrote stderr');
    king_semantic_dns_647_assert(is_array($afterPartial), 'after-partial observer did not emit JSON');
    king_semantic_dns_647_assert(($afterPartial['statistics']['total_services'] ?? null) === 1, 'after-partial total_services drifted');
    king_semantic_dns_647_assert(($afterPartial['statistics']['mother_nodes'] ?? null) === 2, 'after-partial mother_nodes drifted');
    king_semantic_dns_647_assert(($afterPartial['discovery_count'] ?? null) === 1, 'after-partial discovery_count drifted');
    king_semantic_dns_647_assert(($afterPartial['route_service_id'] ?? null) === 'svc-west', 'after-partial route drifted');
    king_semantic_dns_647_assert(!isset($afterPartial['services']['svc-east']), 'after-partial still exposed dropped svc-east');
    king_semantic_dns_647_assert(!isset($afterPartial['mother_nodes']['mother-east']), 'after-partial still exposed dropped mother-east');
    king_semantic_dns_647_assert(($afterPartial['wire']['timed_out'] ?? true) === false, 'after-partial wire query timed out');
    king_semantic_dns_647_assert(($afterPartial['wire']['parsed']['answer_count'] ?? null) === 1, 'after-partial wire answer_count drifted');
    king_semantic_dns_647_assert(($afterPartial['wire']['parsed']['answers'] ?? null) === ['10.30.0.12'], 'after-partial wire answers drifted');

    $staleRejoin = proc_open(
        king_semantic_dns_647_command($extensionPath, $stalePeerScript, [$dnsPort]),
        $descriptors,
        $staleRejoinPipes
    );
    $staleRejoinResult = king_semantic_dns_647_finish_process($staleRejoin, $staleRejoinPipes);

    king_semantic_dns_647_assert($staleRejoinResult['status'] === 0, 'stale-rejoin process failed');
    king_semantic_dns_647_assert(trim($staleRejoinResult['stdout']) === 'stale-rejoin-complete', 'stale-rejoin stdout drifted');
    king_semantic_dns_647_assert(trim($staleRejoinResult['stderr']) === '', 'stale-rejoin stderr drifted');

    $observerAfterRejoin = proc_open(
        king_semantic_dns_647_command($extensionPath, $observerScript, ['after-rejoin', $dnsPort, $wireHelperPath]),
        $descriptors,
        $observerAfterRejoinPipes
    );
    $afterRejoinResult = king_semantic_dns_647_finish_process($observerAfterRejoin, $observerAfterRejoinPipes);
    $afterRejoin = json_decode(trim($afterRejoinResult['stdout']), true);

    king_semantic_dns_647_assert($afterRejoinResult['status'] === 0, 'after-rejoin observer failed');
    king_semantic_dns_647_assert(trim($afterRejoinResult['stderr']) === '', 'after-rejoin observer wrote stderr');
    king_semantic_dns_647_assert(is_array($afterRejoin), 'after-rejoin observer did not emit JSON');
    king_semantic_dns_647_assert(($afterRejoin['statistics']['total_services'] ?? null) === 2, 'after-rejoin total_services drifted');
    king_semantic_dns_647_assert(($afterRejoin['statistics']['healthy_services'] ?? null) === 2, 'after-rejoin healthy_services drifted');
    king_semantic_dns_647_assert(($afterRejoin['statistics']['mother_nodes'] ?? null) === 3, 'after-rejoin mother_nodes drifted');
    king_semantic_dns_647_assert(($afterRejoin['statistics']['discovered_mother_nodes'] ?? null) === 3, 'after-rejoin discovered_mother_nodes drifted');
    king_semantic_dns_647_assert(($afterRejoin['statistics']['synced_mother_nodes'] ?? null) === 3, 'after-rejoin synced_mother_nodes drifted');
    king_semantic_dns_647_assert(($afterRejoin['discovery_count'] ?? null) === 2, 'after-rejoin discovery_count drifted');
    king_semantic_dns_647_assert(($afterRejoin['route_service_id'] ?? null) === 'svc-east', 'after-rejoin route drifted');
    king_semantic_dns_647_assert(($afterRejoin['services']['svc-east']['total_requests'] ?? null) === 1700, 'after-rejoin svc-east did not recover latest stale-peer replay');
    king_semantic_dns_647_assert(isset($afterRejoin['mother_nodes']['mother-east']), 'after-rejoin did not restore mother-east');
    king_semantic_dns_647_assert(($afterRejoin['mother_nodes']['shared-core']['status'] ?? null) === 'degraded', 'after-rejoin shared-core status drifted');
    king_semantic_dns_647_assert(abs((float) (($afterRejoin['mother_nodes']['shared-core']['trust_score'] ?? 0.0) - 0.41)) < 0.0001, 'after-rejoin shared-core trust_score drifted');
    king_semantic_dns_647_assert(($afterRejoin['wire']['timed_out'] ?? true) === false, 'after-rejoin wire query timed out');
    king_semantic_dns_647_assert(($afterRejoin['wire']['parsed']['answer_count'] ?? null) === 2, 'after-rejoin wire answer_count drifted');
    king_semantic_dns_647_assert(($afterRejoin['wire']['parsed']['answers'] ?? null) === ['10.30.0.11', '10.30.0.12'], 'after-rejoin wire answers drifted');

    $observerAfterRestart = proc_open(
        king_semantic_dns_647_command($extensionPath, $observerScript, ['after-restart', $dnsPort, $wireHelperPath]),
        $descriptors,
        $observerAfterRestartPipes
    );
    $afterRestartResult = king_semantic_dns_647_finish_process($observerAfterRestart, $observerAfterRestartPipes);
    $afterRestart = json_decode(trim($afterRestartResult['stdout']), true);

    king_semantic_dns_647_assert($afterRestartResult['status'] === 0, 'after-restart observer failed');
    king_semantic_dns_647_assert(trim($afterRestartResult['stderr']) === '', 'after-restart observer wrote stderr');
    king_semantic_dns_647_assert(is_array($afterRestart), 'after-restart observer did not emit JSON');
    king_semantic_dns_647_assert(($afterRestart['statistics']['total_services'] ?? null) === 2, 'after-restart total_services drifted');
    king_semantic_dns_647_assert(($afterRestart['statistics']['mother_nodes'] ?? null) === 3, 'after-restart mother_nodes drifted');
    king_semantic_dns_647_assert(($afterRestart['route_service_id'] ?? null) === 'svc-east', 'after-restart route drifted');
    king_semantic_dns_647_assert(($afterRestart['services']['svc-east']['total_requests'] ?? null) === 1700, 'after-restart svc-east drifted');
    king_semantic_dns_647_assert(($afterRestart['mother_nodes']['shared-core']['status'] ?? null) === 'degraded', 'after-restart shared-core status drifted');
    king_semantic_dns_647_assert(($afterRestart['wire']['timed_out'] ?? true) === false, 'after-restart wire query timed out');
    king_semantic_dns_647_assert(($afterRestart['wire']['parsed']['answer_count'] ?? null) === 2, 'after-restart wire answer_count drifted');
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

    @unlink($writerAScript);
    @unlink($writerBScript);
    @unlink($stalePeerScript);
    @unlink($observerScript);
}

echo "OK\n";
?>
--EXPECT--
OK
