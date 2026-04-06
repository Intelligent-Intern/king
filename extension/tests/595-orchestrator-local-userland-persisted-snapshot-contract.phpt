--TEST--
King local userland tool execution persists a correct run snapshot readable by a fresh process
--INI--
king.security_allow_config_override=1
--FILE--
<?php
/**
 * #15: Add PHPT proof for local userland tool execution over a persisted run snapshot.
 *
 * Proves that:
 * - Tools and handlers registered in process-A execute synchronously on the local backend.
 * - The completed run is persisted to the state file with the correct result and step progress.
 * - A fresh process-B (no handler registration) can read back the run snapshot and sees:
 *     - status = "completed"
 *     - execution_backend = "local"
 *     - topology_scope = "local_in_process"
 *     - completed_step_count matches total step count
 *     - result contains the expected chained output
 *     - handler_readiness.requires_process_registration = false (finished local run has no open boundary)
 *     - handler_readiness.ready = true
 *     - per-step status = "completed"
 *     - per-step compensation_status = "not_required"
 */
$statePath = tempnam(sys_get_temp_dir(), 'king-orch-local-snap-state-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$runnerScript = tempnam(sys_get_temp_dir(), 'king-orch-local-snap-runner-');
$readerScript = tempnam(sys_get_temp_dir(), 'king-orch-local-snap-reader-');

@unlink($statePath);

/* ---- runner: register tools + handlers, execute locally, print run-id ---- */
file_put_contents($runnerScript, <<<'PHP'
<?php
function snap_prepare_handler(array $context): array
{
    $input = $context['input'] ?? [];
    if (!is_array($input)) {
        throw new RuntimeException('snap_prepare: unexpected input type');
    }
    $input['history'][] = 'prepare';
    return ['output' => $input];
}

function snap_enrich_handler(array $context): array
{
    $input = $context['input'] ?? [];
    if (!is_array($input)) {
        throw new RuntimeException('snap_enrich: unexpected input type');
    }
    $input['history'][] = 'enrich';
    $input['step_meta'] = [
        'run_id'   => $context['run_id'] ?? null,
        'index'    => $context['step']['index'] ?? null,
        'tool'     => $context['step']['tool_name'] ?? null,
        'backend'  => $context['run']['execution_backend'] ?? null,
        'topology' => $context['run']['topology_scope'] ?? null,
    ];
    return ['output' => $input];
}

function snap_finalize_handler(array $context): array
{
    $input = $context['input'] ?? [];
    if (!is_array($input)) {
        throw new RuntimeException('snap_finalize: unexpected input type');
    }
    $input['history'][] = 'finalize';
    return ['output' => $input];
}

$ok = king_pipeline_orchestrator_register_tool('snap-prepare', [
    'model' => 'gpt-sim', 'max_tokens' => 32,
]);
if (!$ok) { fwrite(STDERR, "register snap-prepare failed\n"); exit(1); }

$ok = king_pipeline_orchestrator_register_tool('snap-enrich', [
    'model' => 'gpt-sim', 'max_tokens' => 48,
]);
if (!$ok) { fwrite(STDERR, "register snap-enrich failed\n"); exit(1); }

$ok = king_pipeline_orchestrator_register_tool('snap-finalize', [
    'model' => 'gpt-sim', 'max_tokens' => 16,
]);
if (!$ok) { fwrite(STDERR, "register snap-finalize failed\n"); exit(1); }

$ok = king_pipeline_orchestrator_register_handler('snap-prepare', 'snap_prepare_handler');
if (!$ok) { fwrite(STDERR, "register snap-prepare handler failed\n"); exit(1); }

$ok = king_pipeline_orchestrator_register_handler('snap-enrich', 'snap_enrich_handler');
if (!$ok) { fwrite(STDERR, "register snap-enrich handler failed\n"); exit(1); }

$ok = king_pipeline_orchestrator_register_handler('snap-finalize', 'snap_finalize_handler');
if (!$ok) { fwrite(STDERR, "register snap-finalize handler failed\n"); exit(1); }

$result = king_pipeline_orchestrator_run(
    ['text' => 'local-snapshot-proof', 'history' => []],
    [
        ['tool' => 'snap-prepare'],
        ['tool' => 'snap-enrich'],
        ['tool' => 'snap-finalize'],
    ],
    ['trace_id' => 'local-userland-persisted-snap-15']
);

if (!is_array($result)) { fwrite(STDERR, "run did not return array\n"); exit(1); }

$info = king_system_get_component_info('pipeline_orchestrator');
$runId = $info['configuration']['last_run_id'] ?? null;
if ($runId === null) { fwrite(STDERR, "no last_run_id\n"); exit(1); }

echo $runId . "\n";
PHP);

/* ---- reader: load snapshot from state, verify correctness (no handlers registered) ---- */
file_put_contents($readerScript, <<<'PHP'
<?php
$runId = trim($argv[1] ?? '');
if ($runId === '') {
    fwrite(STDERR, "reader: missing run_id argument\n");
    exit(1);
}

$run = king_pipeline_orchestrator_get_run($runId);
if (!is_array($run)) {
    fwrite(STDERR, "reader: get_run returned non-array for {$runId}\n");
    exit(1);
}

$checks = [];

/* top-level shape */
$checks['status_completed']           = ($run['status'] ?? null) === 'completed';
$checks['execution_backend_local']    = ($run['execution_backend'] ?? null) === 'local';
$checks['topology_local_in_process']  = ($run['topology_scope'] ?? null) === 'local_in_process';
$checks['step_count_3']               = ($run['step_count'] ?? null) === 3;
$checks['completed_step_count_3']     = ($run['completed_step_count'] ?? null) === 3;

/* result payload carries chained history */
$history = $run['result']['history'] ?? null;
$checks['history_is_array']           = is_array($history);
$checks['history_prepare']            = is_array($history) && ($history[0] ?? null) === 'prepare';
$checks['history_enrich']             = is_array($history) && ($history[1] ?? null) === 'enrich';
$checks['history_finalize']           = is_array($history) && ($history[2] ?? null) === 'finalize';

/* step_meta injected by snap_enrich_handler proves context delivery */
$stepMeta = $run['result']['step_meta'] ?? null;
$checks['step_meta_is_array']         = is_array($stepMeta);
$checks['step_meta_index_1']          = is_array($stepMeta) && ($stepMeta['index'] ?? null) === 1;
$checks['step_meta_tool_enrich']      = is_array($stepMeta) && ($stepMeta['tool'] ?? null) === 'snap-enrich';
$checks['step_meta_backend_local']    = is_array($stepMeta) && ($stepMeta['backend'] ?? null) === 'local';
$checks['step_meta_topology']         = is_array($stepMeta) && ($stepMeta['topology'] ?? null) === 'local_in_process';
$checks['step_meta_run_id_match']     = is_array($stepMeta) && ($stepMeta['run_id'] ?? null) === $runId;

/* handler_readiness: finished local run has no open process-registration boundary */
$hr = $run['handler_readiness'] ?? null;
$checks['handler_readiness_is_array']       = is_array($hr);
$checks['hr_requires_proc_reg_false']       = is_array($hr) && ($hr['requires_process_registration'] ?? null) === false;
$checks['hr_ready_true']                    = is_array($hr) && ($hr['ready'] ?? null) === true;
$checks['hr_missing_tool_count_0']          = is_array($hr) && ($hr['missing_tool_count'] ?? null) === 0;

/* no error_classification for a successful run */
$checks['no_error_classification']          = ($run['error_classification'] ?? null) === null;

/* compensation not required */
$comp = $run['compensation'] ?? null;
$checks['comp_not_required']                = is_array($comp) && ($comp['required'] ?? null) === false;
$checks['comp_trigger_none']                = is_array($comp) && ($comp['trigger'] ?? null) === 'none';
$checks['comp_pending_step_count_0']        = is_array($comp) && ($comp['pending_step_count'] ?? null) === 0;

/* per-step assertions */
$steps = $run['steps'] ?? null;
$checks['steps_is_array']                   = is_array($steps) && count($steps) === 3;

for ($i = 0; $i < 3; $i++) {
    $step = $steps[$i] ?? null;
    $checks["step{$i}_status_completed"]       = is_array($step) && ($step['status'] ?? null) === 'completed';
    $checks["step{$i}_comp_not_required"]      = is_array($step) && ($step['compensation_status'] ?? null) === 'not_required';
    $checks["step{$i}_backend_local"]          = is_array($step) && ($step['execution_backend'] ?? null) === 'local';
    $checks["step{$i}_topology_local"]         = is_array($step) && ($step['topology_scope'] ?? null) === 'local_in_process';
}

/* distributed_observability */
$obs = $run['distributed_observability'] ?? null;
$checks['obs_completed_step_count_3']       = is_array($obs) && ($obs['completed_step_count'] ?? null) === 3;
$checks['obs_step_count_3']                 = is_array($obs) && ($obs['step_count'] ?? null) === 3;

$failed = [];
foreach ($checks as $name => $passed) {
    if (!$passed) {
        $failed[] = $name;
    }
}

if (count($failed) > 0) {
    fwrite(STDERR, "FAILED checks: " . implode(', ', $failed) . "\n");
    exit(1);
}

echo "ok\n";
PHP);

$baseCommand = sprintf(
    '%s -n -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    '%s'
);

/* --- run the runner --- */
$runnerCommand = sprintf($baseCommand, escapeshellarg($runnerScript));
exec($runnerCommand, $runnerOutput, $runnerStatus);

var_dump($runnerStatus === 0);

$runId = trim($runnerOutput[0] ?? '');
var_dump((bool) preg_match('/^run-\d+$/', $runId));

/* --- sanity: state file must exist and be non-empty --- */
var_dump(is_file($statePath) && filesize($statePath) > 0);

/* --- run the reader --- */
$readerCommand = sprintf(
    $baseCommand,
    escapeshellarg($readerScript) . ' ' . escapeshellarg($runId)
);
exec($readerCommand, $readerOutput, $readerStatus);

var_dump($readerStatus === 0);
var_dump(trim($readerOutput[0] ?? '') === 'ok');

@unlink($runnerScript);
@unlink($readerScript);
@unlink($statePath);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
