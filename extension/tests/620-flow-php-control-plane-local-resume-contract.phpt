--TEST--
Repo-local Flow PHP control plane exposes persisted inspect and resume surfaces for local orchestrator runs
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
function king_flow_control_plane_local_cleanup(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_flow_control_plane_local_cleanup($path . '/' . $entry);
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
}

$statePath = tempnam(sys_get_temp_dir(), 'king-flow-control-local-state-');
$objectStoreRoot = sys_get_temp_dir() . '/king-flow-control-local-store-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controlPlanePath = dirname(__DIR__, 2) . '/userland/flow-php/src/ControlPlane.php';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-flow-control-local-controller-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-flow-control-local-observer-');
$resumeScript = tempnam(sys_get_temp_dir(), 'king-flow-control-local-resume-');

@unlink($statePath);
king_flow_control_plane_local_cleanup($objectStoreRoot);
mkdir($objectStoreRoot, 0700, true);

$controllerTemplate = <<<'PHP'
<?php
require_once __CONTROL_PLANE_PATH__;

use King\Flow\FlowControlPlane;
use King\Flow\ObjectStoreCheckpointStore;
use King\Flow\ObjectStoreFlowControlStore;
use King\Flow\OrchestratorExecutionBackend;

function flow_control_local_prepare(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'prepare';

    return ['output' => $input];
}

function flow_control_local_finalize(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'finalize';

    return ['output' => $input];
}

$objectStoreRoot = $argv[1] ?? '';

king_object_store_init([
    'storage_root_path' => $objectStoreRoot,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]);

$backend = new OrchestratorExecutionBackend();
$backend->registerTool('prepare', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$backend->registerTool('finalize', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$backend->registerHandler('prepare', 'flow_control_local_prepare');
$backend->registerHandler('finalize', 'flow_control_local_finalize');

$control = new FlowControlPlane(
    $backend,
    new ObjectStoreCheckpointStore('checkpoints/local-flow', [
        'expires_at' => '2099-01-01T00:00:00Z',
    ]),
    new ObjectStoreFlowControlStore('controls/local-flow', [
        'expires_at' => '2099-01-01T00:00:00Z',
    ])
);

$control->start(
    'flow-local',
    ['text' => 'flow-control-local', 'history' => []],
    [
        ['tool' => 'prepare'],
        ['tool' => 'finalize', 'delay_ms' => 2500],
    ],
    null,
    null,
    ['trace_id' => 'flow-control-local-620']
);
PHP;
file_put_contents(
    $controllerScript,
    str_replace('__CONTROL_PLANE_PATH__', var_export($controlPlanePath, true), $controllerTemplate)
);

$observerTemplate = <<<'PHP'
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
    new ObjectStoreCheckpointStore('checkpoints/local-flow', [
        'expires_at' => '2099-01-01T00:00:00Z',
    ]),
    new ObjectStoreFlowControlStore('controls/local-flow', [
        'expires_at' => '2099-01-01T00:00:00Z',
    ])
);

echo json_encode($control->inspect($controlRunId)?->toArray(), JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $observerScript,
    str_replace('__CONTROL_PLANE_PATH__', var_export($controlPlanePath, true), $observerTemplate)
);

$resumeTemplate = <<<'PHP'
<?php
require_once __CONTROL_PLANE_PATH__;

use King\Flow\FlowControlPlane;
use King\Flow\ObjectStoreCheckpointStore;
use King\Flow\ObjectStoreFlowControlStore;
use King\Flow\OrchestratorExecutionBackend;

function flow_control_local_prepare(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'prepare';

    return ['output' => $input];
}

function flow_control_local_finalize(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'finalize';

    return ['output' => $input];
}

$objectStoreRoot = $argv[1] ?? '';

king_object_store_init([
    'storage_root_path' => $objectStoreRoot,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]);

$backend = new OrchestratorExecutionBackend();
$backend->registerHandler('prepare', 'flow_control_local_prepare');
$backend->registerHandler('finalize', 'flow_control_local_finalize');

$control = new FlowControlPlane(
    $backend,
    new ObjectStoreCheckpointStore('checkpoints/local-flow', [
        'expires_at' => '2099-01-01T00:00:00Z',
    ]),
    new ObjectStoreFlowControlStore('controls/local-flow', [
        'expires_at' => '2099-01-01T00:00:00Z',
    ])
);

echo json_encode($control->resume('flow-local')->toArray(), JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $resumeScript,
    str_replace('__CONTROL_PLANE_PATH__', var_export($controlPlanePath, true), $resumeTemplate)
);

$baseCommand = sprintf(
    '%s -n -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    '%s'
);

$observerCommand = static function (string $controlRunId) use ($baseCommand, $observerScript, $objectStoreRoot): string {
    return sprintf(
        '%s %s %s',
        sprintf($baseCommand, escapeshellarg($observerScript)),
        escapeshellarg($objectStoreRoot),
        escapeshellarg($controlRunId)
    );
};

$resumeCommand = static function () use ($baseCommand, $resumeScript, $objectStoreRoot): string {
    return sprintf(
        '%s %s',
        sprintf($baseCommand, escapeshellarg($resumeScript)),
        escapeshellarg($objectStoreRoot)
    );
};

$controllerArgv = [
    PHP_BINARY,
    '-n',
    '-d', 'extension=' . $extensionPath,
    '-d', 'king.security_allow_config_override=1',
    '-d', 'king.orchestrator_state_path=' . $statePath,
    $controllerScript,
    $objectStoreRoot,
];

$controllerProcess = proc_open($controllerArgv, [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $controllerPipes);

$runningObserved = false;
for ($i = 0; $i < 400; $i++) {
    $observerOutput = [];
    $observerStatus = -1;
    exec($observerCommand('flow-local'), $observerOutput, $observerStatus);
    $snapshot = json_decode(trim($observerOutput[0] ?? ''), true);

    if (
        $observerStatus === 0
        && is_array($snapshot)
        && ($snapshot['control_run_id'] ?? null) === 'flow-local'
        && ($snapshot['control_status'] ?? null) === 'running'
        && ($snapshot['active_backend_run_id'] ?? null) === 'run-1'
        && ($snapshot['backend_run_ids'] ?? null) === ['run-1']
        && ($snapshot['last_action'] ?? null) === 'start'
        && ($snapshot['checkpoint_resume_available'] ?? null) === false
        && ($snapshot['backend_snapshot']['run_id'] ?? null) === 'run-1'
        && ($snapshot['backend_snapshot']['status'] ?? null) === 'running'
        && ($snapshot['backend_snapshot']['completed_step_count'] ?? null) === 1
        && ($snapshot['backend_snapshot']['result']['history'] ?? null) === ['prepare']
    ) {
        $runningObserved = true;
        break;
    }

    usleep(10000);
}

var_dump($runningObserved);

$controllerStatusInfo = proc_get_status($controllerProcess);
$controllerPid = (int) ($controllerStatusInfo['pid'] ?? 0);
$killStatus = -1;
exec('/bin/kill -9 ' . $controllerPid, $killOutput, $killStatus);
var_dump($killStatus === 0);

$controllerStdout = stream_get_contents($controllerPipes[1]);
$controllerStderr = stream_get_contents($controllerPipes[2]);
fclose($controllerPipes[1]);
fclose($controllerPipes[2]);
$controllerExit = proc_close($controllerProcess);
var_dump($controllerExit !== 0);
var_dump(trim($controllerStdout) === '');
var_dump(trim($controllerStderr) === '');

$resumeOutput = [];
$resumeStatus = -1;
exec($resumeCommand(), $resumeOutput, $resumeStatus);
$resume = json_decode(trim($resumeOutput[0] ?? ''), true);

var_dump($resumeStatus === 0);
var_dump(($resume['control_run_id'] ?? null) === 'flow-local');
var_dump(($resume['control_status'] ?? null) === 'completed');
var_dump(($resume['active_backend_run_id'] ?? null) === 'run-1');
var_dump(($resume['backend_run_ids'] ?? null) === ['run-1']);
var_dump(($resume['recovery_count'] ?? null) === 0);
var_dump(($resume['last_action'] ?? null) === 'resume_run');
var_dump(($resume['checkpoint_resume_available'] ?? null) === false);
var_dump(($resume['capabilities']['backend'] ?? null) === 'local');
var_dump(($resume['capabilities']['continuation_mode'] ?? null) === 'resume_run_by_id');
var_dump(($resume['backend_snapshot']['status'] ?? null) === 'completed');
var_dump(($resume['backend_snapshot']['completed_step_count'] ?? null) === 2);
var_dump(($resume['backend_snapshot']['result']['history'] ?? null) === ['prepare', 'finalize']);

foreach ([
    $controllerScript,
    $observerScript,
    $resumeScript,
    $statePath,
] as $path) {
    @unlink($path);
}

king_flow_control_plane_local_cleanup($objectStoreRoot);
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
