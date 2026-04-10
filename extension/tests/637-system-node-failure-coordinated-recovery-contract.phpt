--TEST--
King coordinated runtime recovers from unclean prior node loss through persisted state-root takeover on a replacement node
--SKIPIF--
<?php
if (!function_exists('proc_open')) {
    echo "skip proc_open is required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/system_node_failover_harness.inc';

function king_system_node_failover_decode_json(array $result, string $label): array
{
    if (($result['status'] ?? 1) !== 0) {
        throw new RuntimeException($label . ' exited with status ' . json_encode($result['status']) . ' and stderr ' . json_encode($result['stderr']));
    }

    if (trim((string) ($result['stderr'] ?? '')) !== '') {
        throw new RuntimeException($label . ' wrote unexpected stderr: ' . json_encode($result['stderr']));
    }

    $decoded = json_decode(trim((string) ($result['stdout'] ?? '')), true);
    if (!is_array($decoded)) {
        throw new RuntimeException($label . ' did not return valid JSON: ' . json_encode($result['stdout']));
    }

    return $decoded;
}

$harness = king_system_node_failover_harness_create();
$nodeA = null;

try {
    $readyPath = $harness['root'] . '/node-a-ready.json';

    $nodeAScript = king_system_node_failover_harness_write_script($harness, 'node-a', <<<'PHP'
<?php
function wait_until_ready(): array
{
    for ($i = 0; $i < 12; $i++) {
        $status = king_system_get_status();
        if (($status['lifecycle'] ?? null) === 'ready') {
            return $status;
        }

        sleep(1);
    }

    throw new RuntimeException('node-a runtime did not become ready');
}

$root = $argv[1] ?? '';
$readyPath = $argv[2] ?? '';

king_system_init([
    'component_timeout_seconds' => 1,
    'state_root_path' => $root,
    'cluster_id' => 'cluster-a',
    'node_id' => 'node-a',
]);
$status = wait_until_ready();

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'distributed',
]);
king_object_store_put('node-failure-doc', 'node-failure-payload');

file_put_contents($readyPath, json_encode([
    'status' => $status,
    'object_store' => king_object_store_get_stats()['object_store'] ?? null,
]));

sleep(30);
PHP);

    $nodeBScript = king_system_node_failover_harness_write_script($harness, 'node-b', <<<'PHP'
<?php
function wait_until_ready(): array
{
    for ($i = 0; $i < 12; $i++) {
        $status = king_system_get_status();
        if (($status['lifecycle'] ?? null) === 'ready') {
            return $status;
        }

        sleep(1);
    }

    throw new RuntimeException('node-b runtime did not become ready');
}

$root = $argv[1] ?? '';

king_system_init([
    'component_timeout_seconds' => 1,
    'state_root_path' => $root,
    'cluster_id' => 'cluster-a',
    'node_id' => 'node-b',
]);

$starting = king_system_get_status();
$ready = wait_until_ready();

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'distributed',
]);

$list = array_filter(
    king_object_store_list(),
    static fn(array $entry): bool => ($entry['object_id'] ?? '') === 'node-failure-doc'
);

echo json_encode([
    'starting' => $starting,
    'ready' => $ready,
    'payload' => king_object_store_get('node-failure-doc'),
    'has_doc' => count($list) === 1,
    'object_store' => king_object_store_get_stats()['object_store'] ?? null,
]), "\n";
PHP);

    $nodeA = king_system_node_failover_harness_spawn(
        $harness,
        $nodeAScript,
        [$harness['root'], $readyPath]
    );

    var_dump(king_system_node_failover_harness_wait_for_file($readyPath));
    $nodeAReady = json_decode((string) file_get_contents($readyPath), true);
    var_dump(is_array($nodeAReady));
    var_dump(($nodeAReady['status']['lifecycle'] ?? null) === 'ready');
    var_dump(($nodeAReady['status']['recovery']['active'] ?? null) === false);
    var_dump(($nodeAReady['status']['recovery']['recovered'] ?? null) === false);
    var_dump(($nodeAReady['status']['recovery']['mode'] ?? null) === 'none');
    var_dump(($nodeAReady['status']['recovery']['coordinator_state_present'] ?? null) === true);
    var_dump(($nodeAReady['status']['recovery']['coordinator_state_status'] ?? null) === 'initialized');
    var_dump(($nodeAReady['object_store']['runtime_distributed_coordinator_state_status'] ?? null) === 'initialized');
    var_dump(($nodeAReady['object_store']['runtime_distributed_coordinator_state_recovered'] ?? null) === false);

    $firstGeneration = (int) ($nodeAReady['status']['recovery']['coordinator_generation'] ?? 0);

    $crash = king_system_node_failover_harness_crash_process($nodeA);
    $nodeA = null;
    var_dump(($crash['status'] ?? 0) !== 0);
    var_dump(trim((string) ($crash['stdout'] ?? '')) === '');
    var_dump(trim((string) ($crash['stderr'] ?? '')) === '');

    $nodeBResult = king_system_node_failover_decode_json(
        king_system_node_failover_harness_exec($harness, $nodeBScript, [$harness['root']]),
        'node-b'
    );

    var_dump(($nodeBResult['starting']['lifecycle'] ?? null) === 'starting');
    var_dump(($nodeBResult['starting']['recovery']['active'] ?? null) === true);
    var_dump(($nodeBResult['starting']['recovery']['recovered'] ?? null) === true);
    var_dump(($nodeBResult['starting']['recovery']['reason'] ?? null) === 'node_failure');
    var_dump(($nodeBResult['starting']['recovery']['mode'] ?? null) === 'node_failure');
    var_dump(($nodeBResult['starting']['recovery']['source_node_id'] ?? null) === 'node-a');
    var_dump(($nodeBResult['starting']['recovery']['active_node_id'] ?? null) === 'node-b');
    var_dump(($nodeBResult['starting']['recovery']['cluster_id'] ?? null) === 'cluster-a');
    var_dump((bool) ($nodeBResult['starting']['recovery']['plan_id'] ?? null));
    var_dump(str_starts_with($nodeBResult['starting']['recovery']['plan_id'] ?? '', 'node_failure:node-a:'));
    var_dump(($nodeBResult['starting']['recovery']['plan_window_seconds'] ?? null) === 30);
    var_dump(($nodeBResult['starting']['recovery']['coordinator_state_status'] ?? null) === 'recovered');
    var_dump((int) ($nodeBResult['starting']['recovery']['coordinator_generation'] ?? 0) > $firstGeneration);
    var_dump(($nodeBResult['starting']['admission']['process_requests'] ?? null) === false);

    var_dump(($nodeBResult['ready']['lifecycle'] ?? null) === 'ready');
    var_dump(($nodeBResult['ready']['recovery']['active'] ?? null) === false);
    var_dump(($nodeBResult['ready']['recovery']['recovered'] ?? null) === true);
    var_dump(($nodeBResult['ready']['recovery']['reason'] ?? null) === 'node_failure');
    var_dump(($nodeBResult['ready']['recovery']['mode'] ?? null) === 'node_failure');
    var_dump(($nodeBResult['ready']['admission']['process_requests'] ?? null) === true);
    var_dump(($nodeBResult['payload'] ?? null) === 'node-failure-payload');
    var_dump(($nodeBResult['has_doc'] ?? null) === true);
    var_dump(($nodeBResult['object_store']['runtime_distributed_coordinator_state_status'] ?? null) === 'recovered');
    var_dump(($nodeBResult['object_store']['runtime_distributed_coordinator_state_recovered'] ?? null) === true);
} finally {
    if (is_array($nodeA)) {
        king_system_node_failover_harness_crash_process($nodeA);
    }
    king_system_node_failover_harness_destroy($harness);
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
