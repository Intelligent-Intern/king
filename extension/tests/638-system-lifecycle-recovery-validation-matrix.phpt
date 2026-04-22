--TEST--
King repo-local lifecycle matrix validates rolling restart, coordinated recovery surfaces, and public named-peer failover claims
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
$probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe === false) {
    echo "skip loopback tcp listener unavailable: $errstr";
    return;
}
fclose($probe);
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/system_node_failover_harness.inc';
require __DIR__ . '/object_store_failover_harness.inc';
require __DIR__ . '/mcp_failover_harness.inc';
require __DIR__ . '/orchestrator_failover_harness.inc';

function king_system_lifecycle_matrix_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_system_lifecycle_matrix_decode_json(array $result, string $label): array
{
    king_system_lifecycle_matrix_assert(
        ($result['status'] ?? 1) === 0,
        $label . ' exited with status ' . json_encode($result['status'] ?? null) . ' and stderr ' . json_encode($result['stderr'] ?? null)
    );
    king_system_lifecycle_matrix_assert(
        trim((string) ($result['stderr'] ?? '')) === '',
        $label . ' wrote unexpected stderr: ' . json_encode($result['stderr'] ?? null)
    );

    $decoded = json_decode(trim((string) ($result['stdout'] ?? '')), true);
    king_system_lifecycle_matrix_assert(
        is_array($decoded),
        $label . ' did not return valid JSON: ' . json_encode($result['stdout'] ?? null)
    );

    return $decoded;
}

function king_system_lifecycle_matrix_run_rolling_restart_scenario(): array
{
    $harness = king_system_node_failover_harness_create();

    try {
        $nodeAScript = king_system_node_failover_harness_write_script($harness, 'rolling-node-a', <<<'PHP'
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

    throw new RuntimeException('rolling-restart node-a runtime did not become ready');
}

function wait_until_stopped(): array
{
    for ($i = 0; $i < 12; $i++) {
        $status = king_system_get_status();
        if (($status['initialized'] ?? true) === false) {
            return $status;
        }

        sleep(1);
    }

    throw new RuntimeException('rolling-restart node-a runtime did not stop cleanly');
}

$root = $argv[1] ?? '';

king_system_init([
    'component_timeout_seconds' => 1,
    'state_root_path' => $root,
    'cluster_id' => 'cluster-roll',
    'node_id' => 'node-a',
]);

$starting = king_system_get_status();
$ready = wait_until_ready();

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'distributed',
]);
king_object_store_put('rolling-restart-doc', 'rolling-restart-payload');

$shutdownResult = king_system_shutdown();
$stopped = wait_until_stopped();

echo json_encode([
    'starting' => $starting,
    'ready' => $ready,
    'shutdown_result' => $shutdownResult,
    'stopped' => $stopped,
]), "\n";
PHP);
        $nodeBScript = king_system_node_failover_harness_write_script($harness, 'rolling-node-b', <<<'PHP'
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

    throw new RuntimeException('rolling-restart node-b runtime did not become ready');
}

$root = $argv[1] ?? '';

king_system_init([
    'component_timeout_seconds' => 1,
    'state_root_path' => $root,
    'cluster_id' => 'cluster-roll',
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
    static fn(array $entry): bool => ($entry['object_id'] ?? '') === 'rolling-restart-doc'
);

echo json_encode([
    'starting' => $starting,
    'ready' => $ready,
    'payload' => king_object_store_get('rolling-restart-doc'),
    'has_doc' => count($list) === 1,
    'object_store' => king_object_store_get_stats()['object_store'] ?? null,
]), "\n";
PHP);

        return [
            'node_a' => king_system_lifecycle_matrix_decode_json(
                king_system_node_failover_harness_exec($harness, $nodeAScript, [$harness['root']]),
                'rolling/node-a'
            ),
            'node_b' => king_system_lifecycle_matrix_decode_json(
                king_system_node_failover_harness_exec($harness, $nodeBScript, [$harness['root']]),
                'rolling/node-b'
            ),
        ];
    } finally {
        king_system_node_failover_harness_destroy($harness);
    }
}

function king_system_lifecycle_matrix_run_object_store_scenario(): array
{
    $harness = king_object_store_failover_harness_create([
        'bucket' => 'system-lifecycle-matrix',
        'object_id' => 'matrix-doc',
    ]);

    try {
        $initialized = king_object_store_failover_harness_start($harness, [
            'component_timeout_seconds' => 1,
        ]);
        $primed = king_object_store_failover_harness_prime_object($harness, 'alpha');
        $outage = king_object_store_failover_harness_simulate_primary_outage($harness);
        $healed = king_object_store_failover_harness_heal_from_backup($harness);
    } finally {
        $stopped = king_object_store_failover_harness_shutdown($harness);
        $history = king_object_store_failover_harness_capture_history($harness);
        king_object_store_failover_harness_destroy($harness);
    }

    return [
        'initialized' => $initialized,
        'primed' => $primed,
        'outage' => $outage,
        'healed' => $healed,
        'stopped' => $stopped,
        'history' => $history,
    ];
}

function king_system_lifecycle_matrix_run_mcp_named_peer_scenario(): array
{
    $harness = king_mcp_failover_harness_create(['alpha', 'beta']);
    $captures = [];
    $alphaConnection = null;
    $betaConnection = null;
    $alphaCrash = [];

    try {
        $alphaConnection = king_mcp_failover_harness_connect_peer($harness, 'alpha');
        $betaConnection = king_mcp_failover_harness_connect_peer($harness, 'beta');

        $source = fopen('php://temp', 'w+');
        fwrite($source, 'alpha-persisted-before-crash');
        rewind($source);
        $uploadAlpha = king_mcp_upload_from_stream($alphaConnection, 'svc', 'blob', 'asset-alpha', $source);
        fclose($source);

        $source = fopen('php://temp', 'w+');
        fwrite($source, 'beta-remains-available');
        rewind($source);
        $uploadBeta = king_mcp_upload_from_stream($betaConnection, 'svc', 'blob', 'asset-beta', $source);
        fclose($source);

        $alphaBeforeCrash = king_mcp_request($alphaConnection, 'svc', 'ping', 'alpha-before-crash');
        $betaBeforeCrash = king_mcp_request($betaConnection, 'svc', 'ping', 'beta-before-crash');

        $alphaCrash = king_mcp_failover_harness_crash_peer($harness, 'alpha');
        $alphaWhileDown = king_mcp_request($alphaConnection, 'svc', 'ping', 'alpha-while-down');
        $alphaError = king_mcp_get_error();
        $betaAfterAlphaCrash = king_mcp_request($betaConnection, 'svc', 'ping', 'beta-after-alpha-crash');

        king_mcp_failover_harness_restart_peer($harness, 'alpha');

        $destination = fopen('php://temp', 'w+');
        $downloadAlpha = king_mcp_download_to_stream($alphaConnection, 'svc', 'blob', 'asset-alpha', $destination);
        rewind($destination);
        $alphaPayload = stream_get_contents($destination);
        fclose($destination);

        $alphaAfterRejoin = king_mcp_request($alphaConnection, 'svc', 'ping', 'alpha-after-rejoin');
        $closeAlpha = king_mcp_close($alphaConnection);
        $closeBeta = king_mcp_close($betaConnection);
    } finally {
        $captures = king_mcp_failover_harness_shutdown($harness);
        king_mcp_failover_harness_destroy($harness);
    }

    return [
        'upload_alpha' => $uploadAlpha,
        'upload_beta' => $uploadBeta,
        'alpha_before_crash' => $alphaBeforeCrash,
        'beta_before_crash' => $betaBeforeCrash,
        'alpha_crash' => $alphaCrash,
        'alpha_while_down' => $alphaWhileDown,
        'alpha_error' => $alphaError,
        'beta_after_alpha_crash' => $betaAfterAlphaCrash,
        'download_alpha' => $downloadAlpha,
        'alpha_payload' => $alphaPayload,
        'alpha_after_rejoin' => $alphaAfterRejoin,
        'close_alpha' => $closeAlpha,
        'close_beta' => $closeBeta,
        'captures' => $captures,
    ];
}

function king_system_lifecycle_matrix_run_orchestrator_remote_return_scenario(): array
{
    $harness = king_orchestrator_failover_harness_create();

    try {
        king_orchestrator_failover_harness_remote_peer_start($harness);

        $controllerScript = king_orchestrator_failover_harness_write_script($harness, 'matrix-remote-controller', <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_run(
    ['text' => 'remote-failover'],
    [['tool' => 'summarizer', 'delay_ms' => 15000]],
    ['trace_id' => 'remote-failover-run']
);
PHP);
        $observerScript = king_orchestrator_failover_harness_write_script($harness, 'matrix-remote-observer', <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1]);
if ($run === false) {
    echo json_encode(['exists' => false]), "\n";
    return;
}
echo json_encode($run), "\n";
PHP);
        $resumeScript = king_orchestrator_failover_harness_write_script($harness, 'matrix-remote-resume', <<<'PHP'
<?php
$runId = $argv[1];
$infoBefore = king_system_get_component_info('pipeline_orchestrator');
$result = king_pipeline_orchestrator_resume_run($runId);
$run = king_pipeline_orchestrator_get_run($runId);
$infoAfter = king_system_get_component_info('pipeline_orchestrator');
echo json_encode([
    'recovered_from_state' => $infoBefore['configuration']['recovered_from_state'],
    'execution_backend' => $infoBefore['configuration']['execution_backend'],
    'topology_scope' => $infoBefore['configuration']['topology_scope'],
    'result_text' => $result['text'] ?? null,
    'run' => $run,
    'last_run_status' => $infoAfter['configuration']['last_run_status'],
]), "\n";
PHP);

        $controller = king_orchestrator_failover_harness_spawn($harness, 'remote_peer', $controllerScript);

        $attemptObserved = king_orchestrator_failover_harness_wait_for(
            static function () use ($harness, $observerScript): bool {
                $snapshot = king_system_lifecycle_matrix_decode_json(
                    king_orchestrator_failover_harness_exec($harness, 'remote_peer', $observerScript, ['run-1']),
                    'matrix/orchestrator-observer'
                );
                $capturePath = $harness['remote_peer']['server']['capture'] ?? null;
                $capture = is_string($capturePath) && is_file($capturePath)
                    ? json_decode((string) file_get_contents($capturePath), true)
                    : null;

                return ($snapshot['run_id'] ?? null) === 'run-1'
                    && ($snapshot['status'] ?? null) === 'running'
                    && ($snapshot['finished_at'] ?? null) === 0
                    && ($snapshot['error'] ?? null) === null
                    && is_array($capture)
                    && (($capture['events'][0]['run_id'] ?? null) === 'run-1');
            }
        );
        king_system_lifecycle_matrix_assert(
            $attemptObserved,
            'orchestrator matrix never observed a running remote-peer attempt'
        );

        $controllerCrash = king_orchestrator_failover_harness_crash_process($controller);
        king_system_lifecycle_matrix_assert(
            ($controllerCrash['status'] ?? 0) !== 0,
            'orchestrator matrix controller exited cleanly after forced crash'
        );
        king_system_lifecycle_matrix_assert(
            trim((string) ($controllerCrash['stdout'] ?? '')) === '',
            'orchestrator matrix controller wrote unexpected stdout'
        );
        king_system_lifecycle_matrix_assert(
            trim((string) ($controllerCrash['stderr'] ?? '')) === '',
            'orchestrator matrix controller wrote unexpected stderr'
        );

        $firstRemoteCapture = king_orchestrator_failover_harness_remote_peer_crash($harness);
        king_orchestrator_failover_harness_remote_peer_start($harness);

        $afterRestart = king_system_lifecycle_matrix_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'remote_peer', $observerScript, ['run-1']),
            'matrix/orchestrator-after-restart'
        );
        $resume = king_system_lifecycle_matrix_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'remote_peer', $resumeScript, ['run-1']),
            'matrix/orchestrator-resume'
        );
        $secondRemoteCapture = king_orchestrator_failover_harness_remote_peer_stop($harness);
        $history = king_orchestrator_failover_harness_remote_peer_history($harness);
    } finally {
        king_orchestrator_failover_harness_destroy($harness);
    }

    return [
        'first_remote_capture' => $firstRemoteCapture,
        'after_restart' => $afterRestart,
        'resume' => $resume,
        'second_remote_capture' => $secondRemoteCapture,
        'history' => $history,
    ];
}

$rolling = king_system_lifecycle_matrix_run_rolling_restart_scenario();
var_dump(($rolling['node_a']['starting']['lifecycle'] ?? null) === 'starting');
var_dump(($rolling['node_a']['ready']['lifecycle'] ?? null) === 'ready');
var_dump(($rolling['node_a']['ready']['recovery']['recovered'] ?? null) === false);
var_dump(($rolling['node_a']['ready']['recovery']['reason'] ?? null) === 'none');
var_dump(($rolling['node_a']['shutdown_result'] ?? null) === true);
var_dump(($rolling['node_a']['stopped']['initialized'] ?? null) === false);
var_dump(($rolling['node_b']['starting']['lifecycle'] ?? null) === 'starting');
var_dump(($rolling['node_b']['ready']['lifecycle'] ?? null) === 'ready');
var_dump(($rolling['node_b']['ready']['recovery']['recovered'] ?? null) === false);
var_dump(($rolling['node_b']['ready']['recovery']['reason'] ?? null) === 'none');
var_dump(array_key_exists('source_node_id', $rolling['node_b']['ready']['recovery']));
var_dump(
    array_key_exists('source_node_id', $rolling['node_b']['ready']['recovery'])
    && $rolling['node_b']['ready']['recovery']['source_node_id'] === null
);
var_dump(($rolling['node_b']['ready']['recovery']['active_node_id'] ?? null) === 'node-b');
var_dump((int) ($rolling['node_b']['ready']['recovery']['coordinator_generation'] ?? 0) > (int) ($rolling['node_a']['ready']['recovery']['coordinator_generation'] ?? 0));
var_dump(($rolling['node_b']['payload'] ?? null) === 'rolling-restart-payload');
var_dump(($rolling['node_b']['has_doc'] ?? null) === true);

$objectStore = king_system_lifecycle_matrix_run_object_store_scenario();
var_dump(($objectStore['initialized']['system_status']['lifecycle'] ?? null) === 'ready');
var_dump(($objectStore['primed']['metadata']['is_backed_up'] ?? null) === 1);
var_dump(($objectStore['healed']['payload'] ?? null) === 'alpha');
var_dump(($objectStore['stopped']['system_stopped'] ?? null) === true);
echo json_encode(array_column($objectStore['history'], 'phase')), "\n";

$mcp = king_system_lifecycle_matrix_run_mcp_named_peer_scenario();
$alphaHistory = $mcp['captures']['alpha'] ?? [];
$betaHistory = $mcp['captures']['beta'] ?? [];
var_dump(($mcp['upload_alpha'] ?? null) === true);
var_dump(($mcp['upload_beta'] ?? null) === true);
var_dump(($mcp['alpha_before_crash'] ?? null) === '{"res":"alpha-before-crash"}');
var_dump(($mcp['beta_before_crash'] ?? null) === '{"res":"beta-before-crash"}');
var_dump(($mcp['alpha_while_down'] ?? null) === false);
var_dump(($mcp['alpha_error'] ?? '') !== '');
var_dump(($mcp['beta_after_alpha_crash'] ?? null) === '{"res":"beta-after-alpha-crash"}');
var_dump(($mcp['download_alpha'] ?? null) === true);
var_dump(($mcp['alpha_payload'] ?? null) === 'alpha-persisted-before-crash');
var_dump(($mcp['alpha_after_rejoin'] ?? null) === '{"res":"alpha-after-rejoin"}');
var_dump(($mcp['close_alpha'] ?? null) === true);
var_dump(($mcp['close_beta'] ?? null) === true);
echo json_encode(array_map(static fn(array $entry): string => (string) ($entry['termination'] ?? ''), $alphaHistory)), "\n";
echo json_encode(array_map(static fn(array $entry): string => (string) ($entry['termination'] ?? ''), $betaHistory)), "\n";

$orchestrator = king_system_lifecycle_matrix_run_orchestrator_remote_return_scenario();
var_dump(($orchestrator['first_remote_capture']['events'][0]['run_id'] ?? null) === 'run-1');
var_dump(($orchestrator['after_restart']['status'] ?? null) === 'running');
var_dump(($orchestrator['resume']['recovered_from_state'] ?? null) === true);
var_dump(($orchestrator['resume']['execution_backend'] ?? null) === 'remote_peer');
var_dump(($orchestrator['resume']['topology_scope'] ?? null) === 'tcp_host_port_execution_peer');
var_dump(($orchestrator['resume']['result_text'] ?? null) === 'remote-failover');
var_dump(($orchestrator['resume']['run']['status'] ?? null) === 'completed');
var_dump(($orchestrator['second_remote_capture']['events'][0]['run_id'] ?? null) === 'run-1');
echo json_encode(array_map(static fn(array $entry): string => (string) ($entry['termination'] ?? ''), $orchestrator['history'])), "\n";
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
["initialized","primed","primary_outage","healed","stopped"]
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
["crash","stop"]
["stop"]
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
["crash","stop"]
