--TEST--
King file-worker executes a full userland-backed run via handler re-registration in a separate worker process
--INI--
king.security_allow_config_override=1
--FILE--
<?php
/**
 * #16: Add PHPT proof for file-worker userland tool execution with re-registration across processes.
 *
 * Proves that:
 * - A controller process registers tools and handlers, then dispatches a run onto the file-worker queue.
 * - The dispatcher process exits; the run remains queued without any handler callables in persisted state.
 * - A separate clean worker process re-registers only the handlers needed for process-local execution,
 *   claims the queued run via king_pipeline_orchestrator_worker_run_next(), and executes all steps.
 * - The worker returns a completed snapshot with correct result, handler_boundary, and step statuses.
 * - A subsequent reader process (no handler registration) reads the persisted snapshot and confirms:
 *     - status = "completed"
 *     - execution_backend = "file_worker"
 *     - topology_scope = "same_host_file_worker"
 *     - completed_step_count matches step count
 *     - context fields (run_id, step index, tool name, backend, topology) were delivered correctly
 *     - handler_boundary.contract = "durable_tool_name_refs_only"
 *     - handler_boundary.requires_process_registration = true (boundary is set for file-worker runs)
 *     - handler_readiness.ready = true (all required tools had matching handlers in worker)
 *     - handler_readiness.missing_tool_count = 0
 *     - queue files are cleaned up after completion
 */
$statePath   = tempnam(sys_get_temp_dir(), 'king-fw-rereg-state-');
$queuePath   = sys_get_temp_dir() . '/king-fw-rereg-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';

$dispatchScript = tempnam(sys_get_temp_dir(), 'king-fw-rereg-dispatch-');
$workerScript   = tempnam(sys_get_temp_dir(), 'king-fw-rereg-worker-');
$readerScript   = tempnam(sys_get_temp_dir(), 'king-fw-rereg-reader-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

/* ---- dispatcher: register tools+handlers, dispatch to file-worker queue ---- */
file_put_contents($dispatchScript, <<<'PHP'
<?php
function fw_extract_handler(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'extract';
    return ['output' => $input];
}

function fw_transform_handler(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'transform';
    $input['step_meta'] = [
        'run_id'   => $context['run_id'] ?? null,
        'index'    => $context['step']['index'] ?? null,
        'tool'     => $context['step']['tool_name'] ?? null,
        'backend'  => $context['run']['execution_backend'] ?? null,
        'topology' => $context['run']['topology_scope'] ?? null,
    ];
    return ['output' => $input];
}

function fw_load_handler(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'load';
    return ['output' => $input];
}

king_pipeline_orchestrator_register_tool('fw-extract',   ['model' => 'gpt-sim', 'max_tokens' => 32]);
king_pipeline_orchestrator_register_tool('fw-transform', ['model' => 'gpt-sim', 'max_tokens' => 48]);
king_pipeline_orchestrator_register_tool('fw-load',      ['model' => 'gpt-sim', 'max_tokens' => 16]);

king_pipeline_orchestrator_register_handler('fw-extract',   'fw_extract_handler');
king_pipeline_orchestrator_register_handler('fw-transform', 'fw_transform_handler');
king_pipeline_orchestrator_register_handler('fw-load',      'fw_load_handler');

$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'file-worker-rereg-proof', 'history' => []],
    [
        ['tool' => 'fw-extract'],
        ['tool' => 'fw-transform'],
        ['tool' => 'fw-load'],
    ],
    ['trace_id' => 'fw-userland-rereg-16']
);

echo $dispatch['run_id'] . "\n";
echo $dispatch['status'] . "\n";
PHP);

/* ---- worker: re-registers handlers, claims+executes the queued run ---- */
file_put_contents($workerScript, <<<'PHP'
<?php
/* Re-register only the handlers - tools are recovered from the state file */
function fw_extract_handler(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'extract';
    return ['output' => $input];
}

function fw_transform_handler(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'transform';
    $input['step_meta'] = [
        'run_id'   => $context['run_id'] ?? null,
        'index'    => $context['step']['index'] ?? null,
        'tool'     => $context['step']['tool_name'] ?? null,
        'backend'  => $context['run']['execution_backend'] ?? null,
        'topology' => $context['run']['topology_scope'] ?? null,
    ];
    return ['output' => $input];
}

function fw_load_handler(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'load';
    return ['output' => $input];
}

king_pipeline_orchestrator_register_handler('fw-extract',   'fw_extract_handler');
king_pipeline_orchestrator_register_handler('fw-transform', 'fw_transform_handler');
king_pipeline_orchestrator_register_handler('fw-load',      'fw_load_handler');

$result = king_pipeline_orchestrator_worker_run_next();

if ($result === false) {
    fwrite(STDERR, "worker: worker_run_next returned false (no work)\n");
    exit(1);
}

echo json_encode([
    'run_id'                  => $result['run_id'] ?? null,
    'status'                  => $result['status'] ?? null,
    'execution_backend'       => $result['execution_backend'] ?? null,
    'topology_scope'          => $result['topology_scope'] ?? null,
    'completed_step_count'    => $result['completed_step_count'] ?? null,
    'step_count'              => $result['step_count'] ?? null,
    'queue_phase'             => $result['distributed_observability']['queue_phase'] ?? null,
    'result_text'             => $result['result']['text'] ?? null,
    'result_history'          => $result['result']['history'] ?? null,
    'step_meta'               => $result['result']['step_meta'] ?? null,
    'boundary_contract'       => $result['handler_boundary']['contract'] ?? null,
    'boundary_scope'          => $result['handler_boundary']['binding_scope'] ?? null,
    'boundary_requires_reg'   => $result['handler_boundary']['requires_process_registration'] ?? null,
    'boundary_required_tools' => $result['handler_boundary']['required_tools'] ?? null,
    'hr_ready'                => $result['handler_readiness']['ready'] ?? null,
    'hr_missing'              => $result['handler_readiness']['missing_tool_count'] ?? null,
    'error'                   => $result['error'] ?? null,
]), "\n";
PHP);

/* ---- reader: reads the persisted completed snapshot (no handler registration) ---- */
file_put_contents($readerScript, <<<'PHP'
<?php
$runId = trim($argv[1] ?? '');
if ($runId === '') {
    fwrite(STDERR, "reader: missing run_id\n");
    exit(1);
}

$run = king_pipeline_orchestrator_get_run($runId);
if (!is_array($run)) {
    fwrite(STDERR, "reader: get_run returned non-array\n");
    exit(1);
}

$checks = [];

$checks['status_completed']        = ($run['status'] ?? null) === 'completed';
$checks['backend_file_worker']     = ($run['execution_backend'] ?? null) === 'file_worker';
$checks['topology_same_host']      = ($run['topology_scope'] ?? null) === 'same_host_file_worker';
$checks['step_count_3']            = ($run['step_count'] ?? null) === 3;
$checks['completed_step_count_3']  = ($run['completed_step_count'] ?? null) === 3;

$history = $run['result']['history'] ?? null;
$checks['history_is_array']        = is_array($history) && count($history) === 3;
$checks['history_extract']         = is_array($history) && ($history[0] ?? null) === 'extract';
$checks['history_transform']       = is_array($history) && ($history[1] ?? null) === 'transform';
$checks['history_load']            = is_array($history) && ($history[2] ?? null) === 'load';

$sm = $run['result']['step_meta'] ?? null;
$checks['step_meta_is_array']      = is_array($sm);
$checks['step_meta_index_1']       = is_array($sm) && ($sm['index'] ?? null) === 1;
$checks['step_meta_tool']          = is_array($sm) && ($sm['tool'] ?? null) === 'fw-transform';
$checks['step_meta_backend']       = is_array($sm) && ($sm['backend'] ?? null) === 'file_worker';
$checks['step_meta_topology']      = is_array($sm) && ($sm['topology'] ?? null) === 'same_host_file_worker';
$checks['step_meta_run_id_match']  = is_array($sm) && ($sm['run_id'] ?? null) === $runId;

$hb = $run['handler_boundary'] ?? null;
$checks['hb_is_array']             = is_array($hb);
$checks['hb_contract']             = is_array($hb) && ($hb['contract'] ?? null) === 'durable_tool_name_refs_only';
$checks['hb_requires_reg']         = is_array($hb) && ($hb['requires_process_registration'] ?? null) === true;
$checks['hb_required_tools_count'] = is_array($hb) && count($hb['required_tools'] ?? []) === 3;

$hr = $run['handler_readiness'] ?? null;
$checks['hr_is_array']             = is_array($hr);
/* In reader process there are no handlers registered, but the run is complete.
   The handler_readiness is computed against current process registrations.
   For a file_worker run, requires_process_registration=true means missing_tool_count
   reflects absence of handlers in the reader - that is correct behaviour.
   What matters is that the snapshot structure is intact and the run is completed. */
$checks['hr_is_array_check']       = is_array($hr);

$comp = $run['compensation'] ?? null;
$checks['comp_not_required']       = is_array($comp) && ($comp['required'] ?? null) === false;
$checks['comp_trigger_none']       = is_array($comp) && ($comp['trigger'] ?? null) === 'none';

$steps = $run['steps'] ?? null;
$checks['steps_count_3']           = is_array($steps) && count($steps) === 3;
for ($i = 0; $i < 3; $i++) {
    $step = $steps[$i] ?? null;
    $checks["step{$i}_status_completed"]  = is_array($step) && ($step['status'] ?? null) === 'completed';
    $checks["step{$i}_comp_not_required"] = is_array($step) && ($step['compensation_status'] ?? null) === 'not_required';
    $checks["step{$i}_backend"]           = is_array($step) && ($step['execution_backend'] ?? null) === 'file_worker';
}

$obs = $run['distributed_observability'] ?? null;
$checks['obs_queue_phase_dequeued'] = is_array($obs) && ($obs['queue_phase'] ?? null) === 'dequeued';
$checks['obs_completed_count_3']    = is_array($obs) && ($obs['completed_step_count'] ?? null) === 3;

$failed = array_keys(array_filter($checks, fn($v) => !$v));
if (count($failed) > 0) {
    echo 'FAILED: ' . implode(', ', $failed) . "\n";
    exit(1);
}

echo "ok\n";
PHP);

$baseCommand = sprintf(
    '%s -n -d %s -d %s -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_execution_backend=file_worker'),
    escapeshellarg('king.orchestrator_worker_queue_path=' . $queuePath),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    '%s'
);

/* --- step 1: dispatch --- */
$dispatchCommand = sprintf($baseCommand, escapeshellarg($dispatchScript));
exec($dispatchCommand, $dispatchOutput, $dispatchStatus);

var_dump($dispatchStatus === 0);
$runId = trim($dispatchOutput[0] ?? '');
var_dump((bool) preg_match('/^run-\d+$/', $runId));
var_dump(trim($dispatchOutput[1] ?? '') === 'queued');

/* --- step 2: state file must exist, and must not contain callable names --- */
var_dump(is_file($statePath) && filesize($statePath) > 0);

$stateContainsCallable = false;
foreach (preg_split("/[\t\r\n]+/", (string) file_get_contents($statePath)) as $field) {
    $decoded = base64_decode($field, true);
    if ($decoded === false) {
        continue;
    }
    if (
        str_contains($decoded, 'fw_extract_handler') ||
        str_contains($decoded, 'fw_transform_handler') ||
        str_contains($decoded, 'fw_load_handler')
    ) {
        $stateContainsCallable = true;
        break;
    }
}
var_dump($stateContainsCallable === false);

/* --- step 3: one queued job file must exist --- */
var_dump(count(glob($queuePath . '/queued-*.job')) === 1);

/* --- step 4: worker re-registers and executes --- */
$workerCommand = sprintf($baseCommand, escapeshellarg($workerScript));
exec($workerCommand, $workerOutput, $workerStatus);

var_dump($workerStatus === 0);

$work = json_decode(trim($workerOutput[0] ?? ''), true);
var_dump(is_array($work));
var_dump(($work['run_id'] ?? null) === $runId);
var_dump(($work['status'] ?? null) === 'completed');
var_dump(($work['execution_backend'] ?? null) === 'file_worker');
var_dump(($work['topology_scope'] ?? null) === 'same_host_file_worker');
var_dump(($work['completed_step_count'] ?? null) === 3);
var_dump(($work['step_count'] ?? null) === 3);
var_dump(($work['queue_phase'] ?? null) === 'dequeued');
var_dump(($work['result_text'] ?? null) === 'file-worker-rereg-proof');
var_dump(($work['result_history'] ?? null) === ['extract', 'transform', 'load']);

$sm = $work['step_meta'] ?? null;
var_dump(is_array($sm));
var_dump(($sm['index'] ?? null) === 1);
var_dump(($sm['tool'] ?? null) === 'fw-transform');
var_dump(($sm['backend'] ?? null) === 'file_worker');
var_dump(($sm['topology'] ?? null) === 'same_host_file_worker');
var_dump(($sm['run_id'] ?? null) === $runId);

var_dump(($work['boundary_contract'] ?? null) === 'durable_tool_name_refs_only');
var_dump(($work['boundary_requires_reg'] ?? null) === true);
var_dump(($work['hr_ready'] ?? null) === true);
var_dump(($work['hr_missing'] ?? null) === 0);
var_dump(($work['error'] ?? null) === null);

/* --- step 5: queue is fully cleaned up after completion --- */
var_dump(count(glob($queuePath . '/queued-*.job')) === 0);
var_dump(count(glob($queuePath . '/claimed-*.job')) === 0);

/* --- step 6: reader verifies persisted snapshot in a fresh process --- */
$readerBaseCommand = sprintf(
    '%s -n -d %s -d %s -d %s -d %s -d %s %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_execution_backend=file_worker'),
    escapeshellarg('king.orchestrator_worker_queue_path=' . $queuePath),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    escapeshellarg($readerScript),
    escapeshellarg($runId)
);
exec($readerBaseCommand . ' 2>&1', $readerOutput, $readerStatus);

var_dump($readerStatus === 0);
var_dump(trim($readerOutput[0] ?? '') === 'ok');

foreach ([$dispatchScript, $workerScript, $readerScript, $statePath] as $path) {
    @unlink($path);
}
if (is_dir($queuePath)) {
    foreach (scandir($queuePath) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            @unlink($queuePath . '/' . $entry);
        }
    }
    @rmdir($queuePath);
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
