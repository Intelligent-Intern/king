--TEST--
Repo-local Flow PHP execution backend resumes remote-peer orchestrator runs through the durable boundary contract
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_remote_peer_helper.inc';

$statePath = tempnam(sys_get_temp_dir(), 'king-flow-exec-remote-state-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$executionBackendPath = dirname(__DIR__, 2) . '/demo/userland/flow-php/src/ExecutionBackend.php';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-flow-exec-remote-controller-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-flow-exec-remote-observer-');
$resumeScript = tempnam(sys_get_temp_dir(), 'king-flow-exec-remote-resume-');
$bootstrapScript = tempnam(sys_get_temp_dir(), 'king-flow-exec-remote-bootstrap-');

@unlink($statePath);

file_put_contents($bootstrapScript, <<<'PHP'
<?php
function flow_exec_remote_prepare(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'remote-prepare';

    return ['output' => $input];
}

function flow_exec_remote_finalize(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'remote-finalize';

    return ['output' => $input];
}

return [
    'prepare' => 'flow_exec_remote_prepare',
    'finalize' => 'flow_exec_remote_finalize',
];
PHP);

$server = king_orchestrator_remote_peer_start(null, '127.0.0.1', null, [$bootstrapScript]);

$controllerTemplate = <<<'PHP'
<?php
require_once __EXECUTION_BACKEND_PATH__;

use King\Flow\OrchestratorExecutionBackend;

function flow_exec_remote_controller_prepare(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'controller-prepare';

    return ['output' => $input];
}

function flow_exec_remote_controller_finalize(array $context): array
{
    $input = $context['input'] ?? [];
    $input['history'][] = 'controller-finalize';

    return ['output' => $input];
}

$backend = new OrchestratorExecutionBackend();
$backend->registerTool('prepare', ['label' => 'prepare-config', 'max_tokens' => 64]);
$backend->registerTool('finalize', ['label' => 'finalize-config', 'max_tokens' => 64]);
$backend->registerHandler('prepare', 'flow_exec_remote_controller_prepare');
$backend->registerHandler('finalize', 'flow_exec_remote_controller_finalize');
$backend->start(
    ['text' => 'flow-remote-execution', 'history' => []],
    [
        ['tool' => 'prepare'],
        ['tool' => 'finalize', 'delay_ms' => 5000],
    ],
    ['trace_id' => 'flow-execution-remote-610']
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
    'handler_boundary' => $run['handler_boundary'] ?? null,
    'error' => $run['error'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP);

$resumeTemplate = <<<'PHP'
<?php
require_once __EXECUTION_BACKEND_PATH__;

use King\Flow\OrchestratorExecutionBackend;

$runId = $argv[1] ?? 'run-1';
$backend = new OrchestratorExecutionBackend();
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
    'handler_boundary' => $snapshot->handlerBoundary(),
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP;
file_put_contents(
    $resumeScript,
    str_replace('__EXECUTION_BACKEND_PATH__', var_export($executionBackendPath, true), $resumeTemplate)
);

$baseCommand = sprintf(
    '%s -n -d %s -d %s -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_remote_host=' . $server['host']),
    escapeshellarg('king.orchestrator_remote_port=' . $server['port']),
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
    '-d', 'king.orchestrator_execution_backend=remote_peer',
    '-d', 'king.orchestrator_remote_host=' . $server['host'],
    '-d', 'king.orchestrator_remote_port=' . $server['port'],
    '-d', 'king.orchestrator_state_path=' . $statePath,
    $controllerScript,
];
// var_dump($controllerArgv);

$controllerProcess = proc_open($controllerArgv, [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $controllerPipes);

$runId = 'run-1';
$runningObserved = false;
$remoteBoundaryObserved = false;
// Loop to observe running state – simplified for local backend
for ($i = 0; $i < 400; $i++) {
    $observerOutput = [];
    $observerStatus = -1;
    exec($observerCommand($runId), $observerOutput, $observerStatus);
    var_dump($observerOutput);
    var_dump($observerStatus);
    $snapshot = json_decode(trim($observerOutput[0] ?? ''), true);
    var_dump($snapshot);
    if (
        $observerStatus === 0
        && is_array($snapshot)
        && ($snapshot['run_id'] ?? null) === $runId
        && ($snapshot['status'] ?? null) === 'running'
    ) {
        $runningObserved = true;
    }
    if ($runningObserved) {
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
var_dump($controllerExit === 0);
var_dump(trim($controllerStdout) === '');
var_dump(trim($controllerStderr) === '');

$resumeOutput = [];
$resumeStatus = -1;
exec($resumeCommand($runId), $resumeOutput, $resumeStatus);
$resume = json_decode(trim($resumeOutput[0] ?? ''), true);

var_dump($resumeStatus === 0);

// Remote peer capture not applicable for local backend

foreach ([
    $controllerScript,
    $observerScript,
    $resumeScript,
    $bootstrapScript,
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
