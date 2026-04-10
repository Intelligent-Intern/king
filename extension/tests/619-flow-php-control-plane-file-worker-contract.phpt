--TEST--
Repo-local Flow PHP control plane persists file-worker start, pause, cancel, inspect, and checkpoint-aware recovery honestly
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function king_flow_control_plane_cleanup(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_flow_control_plane_cleanup($path . '/' . $entry);
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
}

$statePath = tempnam(sys_get_temp_dir(), 'king-flow-control-fw-state-');
$queuePath = sys_get_temp_dir() . '/king-flow-control-fw-queue-' . getmypid();
$objectStoreRoot = sys_get_temp_dir() . '/king-flow-control-fw-store-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controlPlanePath = dirname(__DIR__, 2) . '/demo/userland/flow-php/src/ControlPlane.php';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-flow-control-fw-controller-');
$resumeScript = tempnam(sys_get_temp_dir(), 'king-flow-control-fw-resume-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-flow-control-fw-worker-');
$inspectScript = tempnam(sys_get_temp_dir(), 'king-flow-control-fw-inspect-');

@unlink($statePath);
king_flow_control_plane_cleanup($queuePath);
king_flow_control_plane_cleanup($objectStoreRoot);
mkdir($queuePath, 0700, true);
mkdir($objectStoreRoot, 0700, true);

$controllerTemplate = <<<'PHP'
<?php
require_once __CONTROL_PLANE_PATH__;

use King\Flow\CheckpointRecoveryPlan;
use King\Flow\CheckpointState;
use King\Flow\FlowControlPlane;
use King\Flow\ObjectStoreCheckpointStore;
use King\Flow\ObjectStoreFlowControlStore;
use King\Flow\OrchestratorExecutionBackend;

function flow_control_fw_extract(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'extract';

    return ['output' => $input];
}

function flow_control_fw_load(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'load';

    return ['output' => $input];
}

$objectStoreRoot = $argv[1] ?? '';

king_object_store_init([
    'storage_root_path' => $objectStoreRoot,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]);

$backend = new OrchestratorExecutionBackend();
$backend->registerTool('extract', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$backend->registerTool('load', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$backend->registerHandler('extract', 'flow_control_fw_extract');
$backend->registerHandler('load', 'flow_control_fw_load');

$checkpointStore = new ObjectStoreCheckpointStore('checkpoints/orders-import', [
    'expires_at' => '2099-01-01T00:00:00Z',
]);
$controlStore = new ObjectStoreFlowControlStore('controls/orders-import', [
    'expires_at' => '2099-01-01T00:00:00Z',
]);
$control = new FlowControlPlane($backend, $checkpointStore, $controlStore);

$checkpoint = $checkpointStore->create(
    'flow-1-checkpoint',
    new CheckpointState(
        ['records' => 1],
        ['resume_from' => 'checkpoint_progress'],
        null,
        null,
        ['history' => ['checkpoint-extract'], 'checkpoint_worker' => 'worker-a']
    )
);

$first = $control->start(
    'flow-1',
    ['order' => 'alpha', 'history' => []],
    [
        ['tool' => 'extract'],
        ['tool' => 'load'],
    ],
    'flow-1-checkpoint',
    CheckpointRecoveryPlan::mergeInitialWithCheckpointProgress(),
    ['trace_id' => 'flow-control-file-worker-619-flow-1']
);

$second = $control->start(
    'flow-2',
    ['order' => 'beta', 'history' => []],
    [
        ['tool' => 'extract'],
        ['tool' => 'load'],
    ],
    null,
    null,
    ['trace_id' => 'flow-control-file-worker-619-flow-2']
);

$paused = $control->pause('flow-1');
$cancelled = $control->cancel('flow-2');
$flow1Record = $controlStore->load('flow-1');

echo json_encode([
    'checkpoint' => [
        'committed' => $checkpoint->committed(),
        'version' => $checkpoint->record()?->version(),
    ],
    'first' => $first->toArray(),
    'second' => $second->toArray(),
    'paused' => $paused->toArray(),
    'cancelled' => $cancelled->toArray(),
    'flow1_record' => $flow1Record === null ? null : [
        'version' => $flow1Record->version(),
        'metadata' => $flow1Record->metadata(),
    ],
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $controllerScript,
    str_replace('__CONTROL_PLANE_PATH__', var_export($controlPlanePath, true), $controllerTemplate)
);

$resumeTemplate = <<<'PHP'
<?php
require_once __CONTROL_PLANE_PATH__;

use King\Flow\FlowControlPlane;
use King\Flow\ObjectStoreCheckpointStore;
use King\Flow\ObjectStoreFlowControlStore;
use King\Flow\OrchestratorExecutionBackend;

function flow_control_fw_extract(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'extract';

    return ['output' => $input];
}

function flow_control_fw_load(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'load';

    return ['output' => $input];
}

$objectStoreRoot = $argv[1] ?? '';

king_object_store_init([
    'storage_root_path' => $objectStoreRoot,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]);

$backend = new OrchestratorExecutionBackend();
$backend->registerTool('extract', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$backend->registerTool('load', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$backend->registerHandler('extract', 'flow_control_fw_extract');
$backend->registerHandler('load', 'flow_control_fw_load');

$control = new FlowControlPlane(
    $backend,
    new ObjectStoreCheckpointStore('checkpoints/orders-import', [
        'expires_at' => '2099-01-01T00:00:00Z',
    ]),
    new ObjectStoreFlowControlStore('controls/orders-import', [
        'expires_at' => '2099-01-01T00:00:00Z',
    ])
);

echo json_encode([
    'resumed' => $control->resume('flow-1')->toArray(),
    'cancelled' => $control->inspect('flow-2')?->toArray(),
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $resumeScript,
    str_replace('__CONTROL_PLANE_PATH__', var_export($controlPlanePath, true), $resumeTemplate)
);

$workerTemplate = <<<'PHP'
<?php
require_once __CONTROL_PLANE_PATH__;

use King\Flow\OrchestratorExecutionBackend;

function flow_control_fw_extract(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'extract';

    return ['output' => $input];
}

function flow_control_fw_load(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'load';

    return ['output' => $input];
}

$backend = new OrchestratorExecutionBackend();
$backend->registerHandler('extract', 'flow_control_fw_extract');
$backend->registerHandler('load', 'flow_control_fw_load');
$snapshot = $backend->claimNext();

echo json_encode([
    'snapshot' => $snapshot === false ? null : $snapshot->toArray(),
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $workerScript,
    str_replace('__CONTROL_PLANE_PATH__', var_export($controlPlanePath, true), $workerTemplate)
);

$inspectTemplate = <<<'PHP'
<?php
require_once __CONTROL_PLANE_PATH__;

use King\Flow\FlowControlPlane;
use King\Flow\ObjectStoreCheckpointStore;
use King\Flow\ObjectStoreFlowControlStore;
use King\Flow\OrchestratorExecutionBackend;

$objectStoreRoot = $argv[1] ?? '';
$controlRunId = $argv[2] ?? '';

king_object_store_init([
    'storage_root_path' => $objectStoreRoot,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]);

$control = new FlowControlPlane(
    new OrchestratorExecutionBackend(),
    new ObjectStoreCheckpointStore('checkpoints/orders-import', [
        'expires_at' => '2099-01-01T00:00:00Z',
    ]),
    new ObjectStoreFlowControlStore('controls/orders-import', [
        'expires_at' => '2099-01-01T00:00:00Z',
    ])
);

echo json_encode($control->inspect($controlRunId)?->toArray(), JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $inspectScript,
    str_replace('__CONTROL_PLANE_PATH__', var_export($controlPlanePath, true), $inspectTemplate)
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
exec(
    sprintf(
        '%s %s',
        sprintf($baseCommand, escapeshellarg($controllerScript)),
        escapeshellarg($objectStoreRoot)
    ),
    $controllerOutput,
    $controllerStatus
);
$controller = json_decode(trim($controllerOutput[0] ?? ''), true);

var_dump($controllerStatus === 0);
var_dump(($controller['checkpoint']['committed'] ?? null) === true);
var_dump(($controller['checkpoint']['version'] ?? null) === 1);
var_dump(($controller['first']['control_run_id'] ?? null) === 'flow-1');
var_dump(($controller['first']['control_status'] ?? null) === 'queued');
var_dump(($controller['first']['active_backend_run_id'] ?? null) === 'run-1');
var_dump(($controller['first']['checkpoint_id'] ?? null) === 'flow-1-checkpoint');
var_dump(($controller['first']['checkpoint_resume_available'] ?? null) === true);
var_dump(($controller['second']['active_backend_run_id'] ?? null) === 'run-2');
var_dump(($controller['paused']['control_status'] ?? null) === 'paused');
var_dump(($controller['paused']['pause_mode'] ?? null) === 'cancelled_before_claim');
var_dump(($controller['paused']['backend_snapshot']['run_id'] ?? null) === 'run-1');
var_dump(($controller['paused']['backend_snapshot']['cancel_requested'] ?? null) === true);
var_dump(($controller['cancelled']['control_status'] ?? null) === 'cancelled');
var_dump(($controller['cancelled']['backend_snapshot']['run_id'] ?? null) === 'run-2');
var_dump(($controller['cancelled']['backend_snapshot']['cancel_requested'] ?? null) === true);
var_dump(($controller['flow1_record']['version'] ?? null) === 2);
var_dump(($controller['flow1_record']['metadata']['content_type'] ?? null) === 'application/vnd.king.flow-control+json');
var_dump(($controller['flow1_record']['metadata']['integrity_sha256'] ?? '') !== '');

$resumeOutput = [];
$resumeStatus = -1;
exec(
    sprintf(
        '%s %s',
        sprintf($baseCommand, escapeshellarg($resumeScript)),
        escapeshellarg($objectStoreRoot)
    ),
    $resumeOutput,
    $resumeStatus
);
$resume = json_decode(trim($resumeOutput[0] ?? ''), true);

var_dump($resumeStatus === 0);
var_dump(($resume['resumed']['control_status'] ?? null) === 'queued');
var_dump(($resume['resumed']['active_backend_run_id'] ?? null) === 'run-3');
var_dump(($resume['resumed']['backend_run_ids'] ?? null) === ['run-1', 'run-3']);
var_dump(($resume['resumed']['recovery_count'] ?? null) === 1);
var_dump(($resume['resumed']['last_action'] ?? null) === 'recover_from_checkpoint');
var_dump(($resume['resumed']['checkpoint_record']['version'] ?? null) === 1);
var_dump(($resume['cancelled']['control_status'] ?? null) === 'cancelled');

$workerOutput = [];
$workerStatus = -1;
exec(sprintf($baseCommand, escapeshellarg($workerScript)), $workerOutput, $workerStatus);
$worker = json_decode(trim($workerOutput[0] ?? ''), true);

var_dump($workerStatus === 0);
var_dump(($worker['snapshot']['run_id'] ?? null) === 'run-3');
var_dump(($worker['snapshot']['status'] ?? null) === 'completed');
var_dump(($worker['snapshot']['result']['history'] ?? null) === ['checkpoint-extract', 'extract', 'load']);

$inspectOutput = [];
$inspectStatus = -1;
exec(
    sprintf(
        '%s %s %s',
        sprintf($baseCommand, escapeshellarg($inspectScript)),
        escapeshellarg($objectStoreRoot),
        escapeshellarg('flow-1')
    ),
    $inspectOutput,
    $inspectStatus
);
$inspect = json_decode(trim($inspectOutput[0] ?? ''), true);

var_dump($inspectStatus === 0);
var_dump(($inspect['control_status'] ?? null) === 'completed');
var_dump(($inspect['active_backend_run_id'] ?? null) === 'run-3');
var_dump(($inspect['backend_run_ids'] ?? null) === ['run-1', 'run-3']);
var_dump(($inspect['recovery_count'] ?? null) === 1);
var_dump(($inspect['backend_snapshot']['completed_step_count'] ?? null) === 2);
var_dump(($inspect['backend_snapshot']['result']['history'] ?? null) === ['checkpoint-extract', 'extract', 'load']);

foreach ([
    $controllerScript,
    $resumeScript,
    $workerScript,
    $inspectScript,
    $statePath,
] as $path) {
    @unlink($path);
}

king_flow_control_plane_cleanup($queuePath);
king_flow_control_plane_cleanup($objectStoreRoot);
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
