--TEST--
Repo-local Flow PHP execution backend dispatches, claims, and cancels file-worker orchestrator runs honestly
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
$statePath = tempnam(sys_get_temp_dir(), 'king-flow-exec-fw-state-');
$queuePath = sys_get_temp_dir() . '/king-flow-exec-fw-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$executionBackendPath = dirname(__DIR__, 2) . '/userland/flow-php/src/ExecutionBackend.php';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-flow-exec-fw-controller-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-flow-exec-fw-worker-');
$cancelScript = tempnam(sys_get_temp_dir(), 'king-flow-exec-fw-cancel-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

$controllerTemplate = <<<'PHP'
<?php
require_once __EXECUTION_BACKEND_PATH__;

use King\Flow\OrchestratorExecutionBackend;

function flow_exec_fw_extract(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'extract';

    return ['output' => $input];
}

function flow_exec_fw_load(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'load';

    return ['output' => $input];
}

$backend = new OrchestratorExecutionBackend();
$backend->registerTool('extract', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$backend->registerTool('load', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$backend->registerHandler('extract', 'flow_exec_fw_extract');
$backend->registerHandler('load', 'flow_exec_fw_load');

$first = $backend->start(
    ['text' => 'flow-file-worker-success', 'history' => []],
    [
        ['tool' => 'extract'],
        ['tool' => 'load'],
    ],
    ['trace_id' => 'flow-execution-file-worker-success-609']
);

$second = $backend->start(
    ['text' => 'flow-file-worker-cancel', 'history' => []],
    [
        ['tool' => 'extract'],
        ['tool' => 'load', 'delay_ms' => 250],
    ],
    ['trace_id' => 'flow-execution-file-worker-cancel-609']
);

echo json_encode([
    'capabilities' => $backend->capabilities()->toArray(),
    'first_run_id' => $first->runId(),
    'first_status' => $first->status(),
    'second_run_id' => $second->runId(),
    'second_status' => $second->status(),
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $controllerScript,
    str_replace('__EXECUTION_BACKEND_PATH__', var_export($executionBackendPath, true), $controllerTemplate)
);

$workerTemplate = <<<'PHP'
<?php
require_once __EXECUTION_BACKEND_PATH__;

use King\Flow\OrchestratorExecutionBackend;

function flow_exec_fw_extract(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'extract';

    return ['output' => $input];
}

function flow_exec_fw_load(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'load';

    return ['output' => $input];
}

$backend = new OrchestratorExecutionBackend();
$backend->registerHandler('extract', 'flow_exec_fw_extract');
$backend->registerHandler('load', 'flow_exec_fw_load');
$snapshot = $backend->claimNext();

echo json_encode([
    'capabilities' => $backend->capabilities()->toArray(),
    'snapshot' => $snapshot === false ? null : $snapshot->toArray(),
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $workerScript,
    str_replace('__EXECUTION_BACKEND_PATH__', var_export($executionBackendPath, true), $workerTemplate)
);

$cancelTemplate = <<<'PHP'
<?php
require_once __EXECUTION_BACKEND_PATH__;

use King\Flow\OrchestratorExecutionBackend;

$runId = $argv[1] ?? '';
$backend = new OrchestratorExecutionBackend();
$cancelled = $backend->cancelRun($runId);
$snapshot = $backend->inspect($runId);

echo json_encode([
    'cancelled' => $cancelled,
    'snapshot' => $snapshot?->toArray(),
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $cancelScript,
    str_replace('__EXECUTION_BACKEND_PATH__', var_export($executionBackendPath, true), $cancelTemplate)
);

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

$controllerOutput = [];
$controllerStatus = -1;
exec(sprintf($baseCommand, escapeshellarg($controllerScript)), $controllerOutput, $controllerStatus);
$controller = json_decode(trim($controllerOutput[0] ?? ''), true);

var_dump($controllerStatus === 0);
var_dump(($controller['capabilities']['backend'] ?? null) === 'file_worker');
var_dump(($controller['capabilities']['topology_scope'] ?? null) === 'same_host_file_worker');
var_dump(($controller['capabilities']['submission_mode'] ?? null) === 'queue_dispatch');
var_dump(($controller['capabilities']['continuation_mode'] ?? null) === 'claim_recovered_or_queued_run');
var_dump(($controller['capabilities']['claim_mode'] ?? null) === 'claim_next_queued_run');
var_dump(($controller['capabilities']['cancellation_mode'] ?? null) === 'persisted_run_cancel');
var_dump(($controller['capabilities']['controller_handler_requirement'] ?? null) === 'required_to_persist_worker_boundary');
var_dump(($controller['capabilities']['executor_handler_requirement'] ?? null) === 'worker_process_registered_handlers');
var_dump(($controller['first_run_id'] ?? null) === 'run-1');
var_dump(($controller['first_status'] ?? null) === 'queued');
var_dump(($controller['second_run_id'] ?? null) === 'run-2');
var_dump(($controller['second_status'] ?? null) === 'queued');

$workerSuccessOutput = [];
$workerSuccessStatus = -1;
exec(sprintf($baseCommand, escapeshellarg($workerScript)), $workerSuccessOutput, $workerSuccessStatus);
$workerSuccess = json_decode(trim($workerSuccessOutput[0] ?? ''), true);
$workerSuccessSnapshot = $workerSuccess['snapshot'] ?? null;

var_dump($workerSuccessStatus === 0);
var_dump(($workerSuccess['capabilities']['backend'] ?? null) === 'file_worker');
var_dump(($workerSuccessSnapshot['run_id'] ?? null) === 'run-1');
var_dump(($workerSuccessSnapshot['status'] ?? null) === 'completed');
var_dump(($workerSuccessSnapshot['execution_backend'] ?? null) === 'file_worker');
var_dump(($workerSuccessSnapshot['topology_scope'] ?? null) === 'same_host_file_worker');
var_dump(($workerSuccessSnapshot['completed_step_count'] ?? null) === 2);
var_dump(($workerSuccessSnapshot['result']['history'] ?? null) === ['extract', 'load']);
var_dump(($workerSuccessSnapshot['error'] ?? null) === null);

$cancelOutput = [];
$cancelStatus = -1;
exec(
    sprintf('%s %s', sprintf($baseCommand, escapeshellarg($cancelScript)), escapeshellarg('run-2')),
    $cancelOutput,
    $cancelStatus
);
$cancel = json_decode(trim($cancelOutput[0] ?? ''), true);
$cancelSnapshot = $cancel['snapshot'] ?? null;

var_dump($cancelStatus === 0);
var_dump(($cancel['cancelled'] ?? null) === true);
var_dump(($cancelSnapshot['run_id'] ?? null) === 'run-2');
var_dump(($cancelSnapshot['cancel_requested'] ?? null) === true);

$workerCancelledOutput = [];
$workerCancelledStatus = -1;
exec(sprintf($baseCommand, escapeshellarg($workerScript)), $workerCancelledOutput, $workerCancelledStatus);
$workerCancelled = json_decode(trim($workerCancelledOutput[0] ?? ''), true);
$workerCancelledSnapshot = $workerCancelled['snapshot'] ?? null;

var_dump($workerCancelledStatus === 0);
var_dump($workerCancelledSnapshot === null);

foreach ([
    $controllerScript,
    $workerScript,
    $cancelScript,
    $statePath,
] as $path) {
    @unlink($path);
}

if (is_dir($queuePath)) {
    foreach (scandir($queuePath) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        @unlink($queuePath . '/' . $entry);
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
