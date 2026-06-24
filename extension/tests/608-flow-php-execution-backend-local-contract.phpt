--TEST--
Repo-local Flow PHP execution backend resumes local orchestrator runs after controller restart
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
$statePath = tempnam(sys_get_temp_dir(), 'king-flow-exec-local-state-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$executionBackendPath = dirname(__DIR__, 2) . '/userland/flow-php/src/ExecutionBackend.php';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-flow-exec-local-controller-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-flow-exec-local-observer-');
$resumeScript = tempnam(sys_get_temp_dir(), 'king-flow-exec-local-resume-');

@unlink($statePath);

$controllerTemplate = <<<'PHP'
<?php
require_once __EXECUTION_BACKEND_PATH__;

use King\Flow\OrchestratorExecutionBackend;

function flow_exec_local_prepare(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'prepare';

    return ['output' => $input];
}

function flow_exec_local_finalize(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'finalize';

    return ['output' => $input];
}

$backend = new OrchestratorExecutionBackend();
$backend->registerTool('prepare', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$backend->registerTool('finalize', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$backend->registerHandler('prepare', 'flow_exec_local_prepare');
$backend->registerHandler('finalize', 'flow_exec_local_finalize');
$backend->start(
    ['text' => 'flow-local-execution', 'history' => []],
    [
        ['tool' => 'prepare'],
        ['tool' => 'finalize', 'delay_ms' => 2500],
    ],
    ['trace_id' => 'flow-execution-local-608']
);
PHP;
file_put_contents(
    $controllerScript,
    str_replace('__EXECUTION_BACKEND_PATH__', var_export($executionBackendPath, true), $controllerTemplate)
);

file_put_contents($observerScript, <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1] ?? 'run-1');
if ($run === false) {
    echo "false\n";
    return;
}

echo json_encode([
    'run_id' => $run['run_id'] ?? null,
    'status' => $run['status'] ?? null,
    'finished_at' => $run['finished_at'] ?? null,
    'completed_step_count' => $run['completed_step_count'] ?? null,
    'history' => $run['result']['history'] ?? null,
    'error' => $run['error'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP);

$resumeTemplate = <<<'PHP'
<?php
require_once __EXECUTION_BACKEND_PATH__;

use King\Flow\OrchestratorExecutionBackend;

function flow_exec_local_prepare(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'prepare';

    return ['output' => $input];
}

function flow_exec_local_finalize(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'finalize';

    return ['output' => $input];
}

$runId = $argv[1] ?? 'run-1';
$backend = new OrchestratorExecutionBackend();
$backend->registerHandler('prepare', 'flow_exec_local_prepare');
$backend->registerHandler('finalize', 'flow_exec_local_finalize');
$snapshot = $backend->continueRun($runId);

echo json_encode([
    'capabilities' => $backend->capabilities()->toArray(),
    'run_id' => $snapshot->runId(),
    'status' => $snapshot->status(),
    'execution_backend' => $snapshot->executionBackend(),
    'topology_scope' => $snapshot->topologyScope(),
    'completed_step_count' => $snapshot->completedStepCount(),
    'step_count' => $snapshot->stepCount(),
    'history' => $snapshot->payload()['history'] ?? null,
    'error' => $snapshot->error(),
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $resumeScript,
    str_replace('__EXECUTION_BACKEND_PATH__', var_export($executionBackendPath, true), $resumeTemplate)
);

$baseCommand = sprintf(
    '%s -n -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    '%s'
);

$observerCommand = static function (string $runId) use ($baseCommand, $observerScript): string {
    return sprintf(
        '%s %s',
        sprintf($baseCommand, escapeshellarg($observerScript)),
        escapeshellarg($runId)
    );
};

$resumeCommand = static function (string $runId) use ($baseCommand, $resumeScript): string {
    return sprintf(
        '%s %s',
        sprintf($baseCommand, escapeshellarg($resumeScript)),
        escapeshellarg($runId)
    );
};

$controllerArgv = [
    PHP_BINARY,
    '-n',
    '-d', 'extension=' . $extensionPath,
    '-d', 'king.security_allow_config_override=1',
    '-d', 'king.orchestrator_state_path=' . $statePath,
    $controllerScript,
];

$controllerProcess = proc_open($controllerArgv, [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $controllerPipes);

$runningObserved = false;
$runId = 'run-1';
for ($i = 0; $i < 400; $i++) {
    $observerOutput = [];
    $observerStatus = -1;
    exec($observerCommand($runId), $observerOutput, $observerStatus);
    $snapshot = json_decode(trim($observerOutput[0] ?? ''), true);
    if (
        $observerStatus === 0
        && is_array($snapshot)
        && ($snapshot['run_id'] ?? null) === $runId
        && ($snapshot['status'] ?? null) === 'running'
        && ($snapshot['finished_at'] ?? null) === 0
        && ($snapshot['completed_step_count'] ?? null) === 1
        && ($snapshot['history'] ?? null) === ['prepare']
        && ($snapshot['error'] ?? null) === null
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
exec($resumeCommand($runId), $resumeOutput, $resumeStatus);
$resume = json_decode(trim($resumeOutput[0] ?? ''), true);

var_dump($resumeStatus === 0);
var_dump(($resume['capabilities']['backend'] ?? null) === 'local');
var_dump(($resume['capabilities']['topology_scope'] ?? null) === 'local_in_process');
var_dump(($resume['capabilities']['submission_mode'] ?? null) === 'run_immediately');
var_dump(($resume['capabilities']['continuation_mode'] ?? null) === 'resume_run_by_id');
var_dump(($resume['capabilities']['claim_mode'] ?? null) === 'not_supported');
var_dump(($resume['capabilities']['cancellation_mode'] ?? null) === 'cancel_token_only');
var_dump(($resume['capabilities']['controller_handler_requirement'] ?? null) === 'required_for_local_execution');
var_dump(($resume['capabilities']['executor_handler_requirement'] ?? null) === 'same_process_registered_handlers');
var_dump(($resume['status'] ?? null) === 'completed');
var_dump(($resume['execution_backend'] ?? null) === 'local');
var_dump(($resume['topology_scope'] ?? null) === 'local_in_process');
var_dump(($resume['completed_step_count'] ?? null) === 2);
var_dump(($resume['step_count'] ?? null) === 2);
var_dump(($resume['history'] ?? null) === ['prepare', 'finalize']);
var_dump(($resume['error'] ?? null) === null);

foreach ([
    $controllerScript,
    $observerScript,
    $resumeScript,
    $statePath,
] as $path) {
    @unlink($path);
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
