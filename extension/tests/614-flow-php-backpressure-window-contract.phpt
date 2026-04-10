--TEST--
Repo-local Flow PHP backpressure window uses real file-worker snapshots to gate queued fan-out honestly
--SKIPIF--
<?php
if (!function_exists('proc_open')) {
    echo "skip proc_open is required";
}
?>
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-flow-partition-fw-state-');
$queuePath = sys_get_temp_dir() . '/king-flow-partition-fw-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$executionBackendPath = dirname(__DIR__, 2) . '/demo/userland/flow-php/src/ExecutionBackend.php';
$partitioningPath = dirname(__DIR__, 2) . '/demo/userland/flow-php/src/Partitioning.php';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-flow-partition-fw-controller-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-flow-partition-fw-worker-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

$controllerTemplate = <<<'PHP'
<?php
require_once __EXECUTION_BACKEND_PATH__;
require_once __PARTITIONING_PATH__;

use King\Flow\OrchestratorExecutionBackend;
use King\Flow\PartitionAttempt;
use King\Flow\PartitionBackpressureWindow;
use King\Flow\PartitionPlan;

function flow_partition_fw_emit(array $context): array
{
    $input = $context['input'] ?? [];

    return ['output' => [
        'partition_id' => $context['run']['partition_id'] ?? null,
        'batch_id' => $context['run']['batch_id'] ?? null,
        'ids' => array_column($input['rows'] ?? [], 'id'),
    ]];
}

$plan = PartitionPlan::fromRowsByField([
    ['id' => 1, 'region' => 'alpha', 'payload' => str_repeat('a', 18)],
    ['id' => 2, 'region' => 'alpha', 'payload' => str_repeat('b', 18)],
    ['id' => 3, 'region' => 'alpha', 'payload' => str_repeat('c', 18)],
    ['id' => 4, 'region' => 'beta', 'payload' => str_repeat('d', 18)],
], 'region', 1, 160);

$backend = new OrchestratorExecutionBackend();
$backend->registerTool('emit', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$backend->registerHandler('emit', 'flow_partition_fw_emit');

$firstBatch = $plan->batches()[0];
$pending = array_slice($plan->batches(), 1);
$firstSnapshot = $backend->start(
    ['rows' => $firstBatch->rows()],
    [$firstBatch->annotateStep(['tool' => 'emit', 'delay_ms' => 300])],
    ['trace_id' => 'flow-partition-backpressure-614']
);
$firstSnapshot = $backend->inspect($firstSnapshot->runId());
$firstAttempt = $firstSnapshot === null ? null : PartitionAttempt::fromExecutionSnapshot($firstSnapshot);

$queuedWindow = PartitionBackpressureWindow::fromCapabilities(
    $backend->capabilities(),
    4,
    1,
    4
)->decision($pending, [$firstAttempt]);

$partitionWindow = PartitionBackpressureWindow::fromCapabilities(
    $backend->capabilities(),
    4,
    4,
    1
)->decision($pending, [$firstAttempt]);

echo json_encode([
    'first_snapshot' => $firstSnapshot->toArray(),
    'first_attempt' => $firstAttempt?->toArray(),
    'queued_window' => $queuedWindow->toArray(),
    'partition_window' => $partitionWindow->toArray(),
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $controllerScript,
    str_replace(
        ['__EXECUTION_BACKEND_PATH__', '__PARTITIONING_PATH__'],
        [var_export($executionBackendPath, true), var_export($partitioningPath, true)],
        $controllerTemplate
    )
);

$workerTemplate = <<<'PHP'
<?php
require_once __EXECUTION_BACKEND_PATH__;
require_once __PARTITIONING_PATH__;

use King\Flow\OrchestratorExecutionBackend;
use King\Flow\PartitionAttempt;
use King\Flow\PartitionBackpressureWindow;
use King\Flow\PartitionPlan;

function flow_partition_fw_emit(array $context): array
{
    $input = $context['input'] ?? [];

    return ['output' => [
        'partition_id' => $context['run']['partition_id'] ?? null,
        'batch_id' => $context['run']['batch_id'] ?? null,
        'ids' => array_column($input['rows'] ?? [], 'id'),
    ]];
}

$plan = PartitionPlan::fromRowsByField([
    ['id' => 1, 'region' => 'alpha', 'payload' => str_repeat('a', 18)],
    ['id' => 2, 'region' => 'alpha', 'payload' => str_repeat('b', 18)],
    ['id' => 3, 'region' => 'alpha', 'payload' => str_repeat('c', 18)],
    ['id' => 4, 'region' => 'beta', 'payload' => str_repeat('d', 18)],
], 'region', 1, 160);

$backend = new OrchestratorExecutionBackend();
$backend->registerHandler('emit', 'flow_partition_fw_emit');
$completed = $backend->claimNext();
$completed = $completed === false ? false : $backend->inspect($completed->runId());

$reliefWindow = PartitionBackpressureWindow::fromCapabilities(
    $backend->capabilities(),
    4,
    1,
    4
)->decision(array_slice($plan->batches(), 1), []);

echo json_encode([
    'completed' => $completed === false ? null : $completed->toArray(),
    'completed_attempt' => $completed === false ? null : PartitionAttempt::fromExecutionSnapshot($completed)?->toArray(),
    'relief_window' => $reliefWindow->toArray(),
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $workerScript,
    str_replace(
        ['__EXECUTION_BACKEND_PATH__', '__PARTITIONING_PATH__'],
        [var_export($executionBackendPath, true), var_export($partitioningPath, true)],
        $workerTemplate
    )
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

$workerOutput = [];
$workerStatus = -1;
exec(sprintf($baseCommand, escapeshellarg($workerScript)), $workerOutput, $workerStatus);
$worker = json_decode(trim($workerOutput[0] ?? ''), true);

var_dump($controllerStatus === 0);
var_dump(($controller['first_snapshot']['status'] ?? null) === 'queued');
var_dump(($controller['first_snapshot']['steps'][0]['telemetry_adapter']['partition_id'] ?? null) === 'partition-000-alpha');
var_dump(($controller['first_snapshot']['steps'][0]['telemetry_adapter']['batch_id'] ?? null) === 'partition-000-alpha-batch-000000');
var_dump(($controller['first_attempt']['active'] ?? null) === true);
var_dump(($controller['first_attempt']['queue_phase'] ?? null) === 'queued');
var_dump(($controller['queued_window']['dispatchable_batch_ids'] ?? null) === []);
var_dump(($controller['queued_window']['blocked_reasons']['partition-000-alpha-batch-000001'] ?? null) === 'max_queued_batches');
var_dump(($controller['queued_window']['blocked_reasons']['partition-000-alpha-batch-000002'] ?? null) === 'max_queued_batches');
var_dump(($controller['queued_window']['blocked_reasons']['partition-001-beta-batch-000000'] ?? null) === 'max_queued_batches');
var_dump(($controller['partition_window']['dispatchable_batch_ids'] ?? null) === [
    'partition-000-alpha-batch-000001',
    'partition-000-alpha-batch-000002',
]);
var_dump(($controller['partition_window']['blocked_reasons']['partition-001-beta-batch-000000'] ?? null) === 'max_active_partitions');

var_dump($workerStatus === 0);
var_dump(($worker['completed']['status'] ?? null) === 'completed');
var_dump(($worker['completed']['steps'][0]['telemetry_adapter']['partition_id'] ?? null) === 'partition-000-alpha');
var_dump(($worker['completed_attempt']['active'] ?? null) === false);
var_dump(($worker['relief_window']['dispatchable_batch_ids'] ?? null) === [
    'partition-000-alpha-batch-000001',
]);

foreach ([
    $controllerScript,
    $workerScript,
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
