--TEST--
King Smart-DNS keeps distributed service registration visible on live topology and on-wire listener paths without restart
--SKIPIF--
<?php
foreach (['unshare', 'slirp4netns', 'ip'] as $binary) {
    if (trim((string) shell_exec('command -v ' . escapeshellarg($binary))) === '') {
        echo "skip {$binary} is required for the Semantic-DNS distributed topology harness";
        return;
    }
}

$probe = @shell_exec('unshare -Urn true 2>/dev/null; printf %s $?');
if (trim((string) $probe) !== '0') {
    echo "skip unprivileged user and network namespaces are unavailable";
    return;
}

$ipOutput = trim((string) shell_exec('ip -4 -o addr show up scope global 2>/dev/null'));
if ($ipOutput === '') {
    $ipOutput = trim((string) shell_exec('ip -4 -o addr show up 2>/dev/null'));
}
if (!preg_match('/\\sinet\\s+((?!127\\.)[0-9]+(?:\\.[0-9]+){3})\\//', $ipOutput)) {
    echo "skip a non-loopback IPv4 address is required for the Semantic-DNS distributed topology harness";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/multi_host_test_helper.inc';
require __DIR__ . '/semantic_dns_wire_helper.inc';

$stateDir = '/tmp/king_semantic_dns_state';
$stateFile = $stateDir . '/durable_state.bin';
$lockFile = $stateFile . '.lock';
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$writerScript = sys_get_temp_dir() . '/king_sdns_distributed_writer_' . getmypid() . '.php';

$stateDirExisted = is_dir($stateDir);
$stateWasFile = is_file($stateFile) && !is_link($stateFile);
$stateBackup = $stateWasFile ? file_get_contents($stateFile) : null;
$lockWasFile = is_file($lockFile) && !is_link($lockFile);
$lockBackup = $lockWasFile ? file_get_contents($lockFile) : null;

function king_sdns_distributed_command(string $extensionPath, string $scriptPath, array $args = []): array
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

function king_sdns_distributed_finish_process($process, array $pipes): array
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

file_put_contents($writerScript, <<<'PHP'
<?php
function king_sdns_require(bool $value, string $message): void
{
    if ($value !== true) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$payload = json_decode(base64_decode((string) $argv[1]), true);
$delayUs = (int) ($argv[2] ?? 0);

if (!is_array($payload) || !isset($payload['service'], $payload['mother_node'])) {
    fwrite(STDERR, "invalid payload\n");
    exit(1);
}

if ($delayUs > 0) {
    usleep($delayUs);
}

king_sdns_require(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5459,
    'default_record_ttl_sec' => 60,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://distributed-seed',
]), 'writer init failed');

king_sdns_require(
    king_semantic_dns_register_service($payload['service']),
    'distributed service registration failed'
);
king_sdns_require(
    king_semantic_dns_register_mother_node($payload['mother_node']),
    'distributed mother-node registration failed'
);

echo "writer-complete\n";
PHP
);

$writerA = null;
$writerB = null;
$writerAPipes = [];
$writerBPipes = [];
$client = [];

try {
    $port = king_semantic_dns_wire_allocate_udp_port();

    var_dump(king_semantic_dns_init([
        'enabled' => true,
        'bind_address' => '0.0.0.0',
        'dns_port' => $port,
        'default_record_ttl_sec' => 60,
        'service_discovery_max_ips_per_response' => 8,
        'semantic_mode_enable' => true,
        'mothernode_uri' => 'mother://distributed-seed',
    ]));
    var_dump(king_semantic_dns_start_server());

    $eastPayload = base64_encode(json_encode([
        'service' => [
            'service_id' => 'edge-east',
            'service_name' => 'edge-api',
            'service_type' => 'http_server',
            'hostname' => '10.10.0.11',
            'port' => 8443,
            'status' => 'healthy',
            'current_load_percent' => 12,
            'active_connections' => 9,
            'total_requests' => 120,
            'attributes' => [
                'region' => 'eu-central',
                'topology_scope' => 'distributed-east',
            ],
        ],
        'mother_node' => [
            'node_id' => 'mother-east',
            'hostname' => 'mother-east.internal',
            'port' => 7443,
            'status' => 'healthy',
            'managed_services_count' => 1,
            'trust_score' => 0.91,
        ],
    ], JSON_THROW_ON_ERROR));
    $westPayload = base64_encode(json_encode([
        'service' => [
            'service_id' => 'edge-west',
            'service_name' => 'edge-api',
            'service_type' => 'http_server',
            'hostname' => '10.10.0.12',
            'port' => 8443,
            'status' => 'healthy',
            'current_load_percent' => 34,
            'active_connections' => 17,
            'total_requests' => 280,
            'attributes' => [
                'region' => 'us-west',
                'topology_scope' => 'distributed-west',
            ],
        ],
        'mother_node' => [
            'node_id' => 'mother-west',
            'hostname' => 'mother-west.internal',
            'port' => 7444,
            'status' => 'healthy',
            'managed_services_count' => 1,
            'trust_score' => 0.83,
        ],
    ], JSON_THROW_ON_ERROR));

    $writerA = proc_open(
        king_sdns_distributed_command($extensionPath, $writerScript, [$eastPayload, '0']),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $writerAPipes
    );
    $writerB = proc_open(
        king_sdns_distributed_command($extensionPath, $writerScript, [$westPayload, '70000']),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $writerBPipes
    );

    if (!is_resource($writerA) || !is_resource($writerB)) {
        throw new RuntimeException('failed to launch Semantic-DNS distributed writers');
    }

    $writerAResult = king_sdns_distributed_finish_process($writerA, $writerAPipes);
    $writerA = null;
    $writerBResult = king_sdns_distributed_finish_process($writerB, $writerBPipes);
    $writerB = null;

    var_dump($writerAResult['status'] === 0);
    var_dump($writerBResult['status'] === 0);

    $client = king_multi_host_test_run_namespaced_php_script(
        __DIR__ . '/semantic_dns_multi_host_client.inc',
        $port,
        ['edge-api'],
        [],
        'Semantic-DNS distributed topology'
    );

    var_dump(($client['__exit_code'] ?? 1) === 0);
    var_dump(!isset($client['exception_class']));
    var_dump(($client['timed_out'] ?? true) === false);
    var_dump(($client['parsed']['answer_count'] ?? null) === 2);
    var_dump(($client['parsed']['answers'][0] ?? null) === '10.10.0.11');
    var_dump(($client['parsed']['answers'][1] ?? null) === '10.10.0.12');

    $topology = king_semantic_dns_get_service_topology();
    $discovery = king_semantic_dns_discover_service('http_server');
    $route = king_semantic_dns_get_optimal_route('edge-api');

    $servicesById = [];
    foreach ($topology['services'] as $service) {
        $servicesById[$service['service_id']] = $service;
    }

    $motherNodesById = [];
    foreach ($topology['mother_nodes'] as $motherNode) {
        $motherNodesById[$motherNode['node_id']] = $motherNode;
    }

    var_dump(($topology['statistics']['total_services'] ?? null) === 2);
    var_dump(($topology['statistics']['healthy_services'] ?? null) === 2);
    var_dump(($topology['statistics']['mother_nodes'] ?? null) === 2);
    var_dump(($topology['statistics']['discovered_mother_nodes'] ?? null) === 2);
    var_dump(($topology['statistics']['synced_mother_nodes'] ?? null) === 2);
    var_dump(($discovery['service_count'] ?? null) === 2);
    var_dump(($route['service_id'] ?? null) === 'edge-east');
    var_dump(($route['hostname'] ?? null) === '10.10.0.11');
    var_dump(($servicesById['edge-east']['attributes']['topology_scope'] ?? null) === 'distributed-east');
    var_dump(($servicesById['edge-west']['attributes']['topology_scope'] ?? null) === 'distributed-west');
    var_dump(($motherNodesById['mother-east']['managed_services_count'] ?? null) === 1);
    var_dump(($motherNodesById['mother-west']['managed_services_count'] ?? null) === 1);
} finally {
    if (is_array($client) && isset($client['__workdir']) && is_dir($client['__workdir'])) {
        // The multi-host helper already cleans up its own workspace.
    }

    if (is_resource($writerA)) {
        foreach ($writerAPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($writerA);
        proc_close($writerA);
    }

    if (is_resource($writerB)) {
        foreach ($writerBPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($writerB);
        proc_close($writerB);
    }

    @unlink($writerScript);

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
