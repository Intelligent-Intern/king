--TEST--
King coordinated runtime gates remote-peer dispatch and resume while the system is not ready
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
require __DIR__ . '/orchestrator_remote_peer_helper.inc';

$extensionPath = dirname(__DIR__) . '/modules/king.so';
$dispatchStatePath = tempnam(sys_get_temp_dir(), 'king-readiness-remote-dispatch-state-');
$resumeStatePath = tempnam(sys_get_temp_dir(), 'king-readiness-remote-resume-state-');
$dispatchGateScript = tempnam(sys_get_temp_dir(), 'king-readiness-remote-dispatch-gate-');
$dispatchRunnerScript = tempnam(sys_get_temp_dir(), 'king-readiness-remote-dispatch-runner-');
$resumeControllerScript = tempnam(sys_get_temp_dir(), 'king-readiness-remote-resume-controller-');
$resumeObserverScript = tempnam(sys_get_temp_dir(), 'king-readiness-remote-resume-observer-');
$resumeGateScript = tempnam(sys_get_temp_dir(), 'king-readiness-remote-resume-gate-');
$resumeRunnerScript = tempnam(sys_get_temp_dir(), 'king-readiness-remote-resume-runner-');
$handlerBootstrapScript = tempnam(sys_get_temp_dir(), 'king-readiness-remote-bootstrap-');

@unlink($dispatchStatePath);
@unlink($resumeStatePath);

file_put_contents($handlerBootstrapScript, <<<'PHP'
<?php
function readiness_remote_prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected remote prepare input');
    }

    $input['history'][] = 'remote-prepare';
    return ['output' => $input];
}

function readiness_remote_finalize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected remote finalize input');
    }

    $input['history'][] = 'remote-finalize';
    return ['output' => $input];
}

return [
    'prepare' => 'readiness_remote_prepare_handler',
    'finalize' => 'readiness_remote_finalize_handler',
];
PHP);

file_put_contents($dispatchGateScript, <<<'PHP'
<?php
function readiness_dispatch_prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected dispatch gate input');
    }

    $input['history'][] = 'controller-prepare';
    return ['output' => $input];
}

king_pipeline_orchestrator_register_tool('prepare', [
    'label' => 'prepare-config',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('prepare', 'readiness_dispatch_prepare_handler');

$payload = [
    'init' => king_system_init(['component_timeout_seconds' => 1]),
    'restart' => king_system_restart_component('telemetry'),
];
$status = king_system_get_status();
$payload['lifecycle'] = $status['lifecycle'] ?? null;
$payload['remote_peer_dispatches'] = $status['admission']['remote_peer_dispatches'] ?? null;
$payload['remote_peer_resumes'] = $status['admission']['remote_peer_resumes'] ?? null;

try {
    king_pipeline_orchestrator_run(
        ['text' => 'blocked-dispatch', 'history' => []],
        [['tool' => 'prepare']]
    );
    $payload['exception_class'] = null;
    $payload['exception_message'] = null;
} catch (Throwable $e) {
    $payload['exception_class'] = get_class($e);
    $payload['exception_message'] = $e->getMessage();
}

echo json_encode($payload), "\n";
PHP);

file_put_contents($dispatchRunnerScript, <<<'PHP'
<?php
function readiness_dispatch_prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected dispatch runner input');
    }

    $input['history'][] = 'controller-prepare';
    return ['output' => $input];
}

king_pipeline_orchestrator_register_tool('prepare', [
    'label' => 'prepare-config',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('prepare', 'readiness_dispatch_prepare_handler');

$result = king_pipeline_orchestrator_run(
    ['text' => 'allowed-dispatch', 'history' => []],
    [['tool' => 'prepare']]
);
$run = king_pipeline_orchestrator_get_run('run-1');

echo json_encode([
    'result_text' => $result['text'] ?? null,
    'result_history' => $result['history'] ?? null,
    'run_status' => $run['status'] ?? null,
    'run_error' => $run['error'] ?? null,
]), "\n";
PHP);

file_put_contents($resumeControllerScript, <<<'PHP'
<?php
function readiness_controller_prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected controller prepare input');
    }

    $input['history'][] = 'controller-prepare';
    return ['output' => $input];
}

function readiness_controller_finalize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected controller finalize input');
    }

    $input['history'][] = 'controller-finalize';
    return ['output' => $input];
}

king_pipeline_orchestrator_register_tool('prepare', [
    'label' => 'prepare-config',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_tool('finalize', [
    'label' => 'finalize-config',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('prepare', 'readiness_controller_prepare_handler');
king_pipeline_orchestrator_register_handler('finalize', 'readiness_controller_finalize_handler');

king_pipeline_orchestrator_run(
    ['text' => 'blocked-resume', 'history' => []],
    [
        ['tool' => 'prepare'],
        ['tool' => 'finalize', 'delay_ms' => 5000],
    ],
    ['trace_id' => 'readiness-remote-resume']
);
PHP);

file_put_contents($resumeObserverScript, <<<'PHP'
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
    'error' => $run['error'] ?? null,
    'recovery_count' => $run['distributed_observability']['recovery_count'] ?? null,
    'remote_attempt_count' => $run['distributed_observability']['remote_attempt_count'] ?? null,
    'handler_boundary' => $run['handler_boundary'] ?? null,
]), "\n";
PHP);

file_put_contents($resumeGateScript, <<<'PHP'
<?php
$payload = [
    'init' => king_system_init(['component_timeout_seconds' => 1]),
    'restart' => king_system_restart_component('telemetry'),
];
$status = king_system_get_status();
$payload['lifecycle'] = $status['lifecycle'] ?? null;
$payload['remote_peer_dispatches'] = $status['admission']['remote_peer_dispatches'] ?? null;
$payload['remote_peer_resumes'] = $status['admission']['remote_peer_resumes'] ?? null;

try {
    king_pipeline_orchestrator_resume_run($argv[1] ?? 'run-1');
    $payload['exception_class'] = null;
    $payload['exception_message'] = null;
} catch (Throwable $e) {
    $payload['exception_class'] = get_class($e);
    $payload['exception_message'] = $e->getMessage();
}

echo json_encode($payload), "\n";
PHP);

file_put_contents($resumeRunnerScript, <<<'PHP'
<?php
$runId = $argv[1] ?? 'run-1';
$info = king_system_get_component_info('pipeline_orchestrator');
$result = king_pipeline_orchestrator_resume_run($runId);
$run = king_pipeline_orchestrator_get_run($runId);

echo json_encode([
    'recovered_from_state' => $info['configuration']['recovered_from_state'] ?? null,
    'result_text' => $result['text'] ?? null,
    'result_history' => $result['history'] ?? null,
    'run_status' => $run['status'] ?? null,
    'run_error' => $run['error'] ?? null,
    'recovery_count' => $run['distributed_observability']['recovery_count'] ?? null,
    'remote_attempt_count' => $run['distributed_observability']['remote_attempt_count'] ?? null,
]), "\n";
PHP);

$buildCommand = static function (array $server, string $statePath, string $script, array $args = []) use ($extensionPath): string {
    $command = sprintf(
        '%s -n -d %s -d %s -d %s -d %s -d %s -d %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg('king.orchestrator_execution_backend=remote_peer'),
        escapeshellarg('king.orchestrator_remote_host=' . $server['host']),
        escapeshellarg('king.orchestrator_remote_port=' . $server['port']),
        escapeshellarg('king.orchestrator_state_path=' . $statePath),
        escapeshellarg($script)
    );

    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    return $command;
};

$dispatchServer = king_orchestrator_remote_peer_start(null, '127.0.0.1', null, [$handlerBootstrapScript]);
$dispatchGateCommand = $buildCommand($dispatchServer, $dispatchStatePath, $dispatchGateScript);
$dispatchRunnerCommand = $buildCommand($dispatchServer, $dispatchStatePath, $dispatchRunnerScript);

exec($dispatchGateCommand, $dispatchGateOutput, $dispatchGateStatus);
$dispatchGate = json_decode(trim($dispatchGateOutput[0] ?? ''), true);
var_dump($dispatchGateStatus);
var_dump(($dispatchGate['init'] ?? null) === true);
var_dump(($dispatchGate['restart'] ?? null) === true);
var_dump(($dispatchGate['lifecycle'] ?? null) === 'draining');
var_dump(($dispatchGate['remote_peer_dispatches'] ?? null) === false);
var_dump(($dispatchGate['remote_peer_resumes'] ?? null) === false);
var_dump(($dispatchGate['exception_class'] ?? null) === 'King\\RuntimeException');
var_dump(str_contains((string) ($dispatchGate['exception_message'] ?? ''), 'cannot admit remote_peer_dispatches'));
var_dump(str_contains((string) ($dispatchGate['exception_message'] ?? ''), "lifecycle is 'draining'"));

exec($dispatchRunnerCommand, $dispatchRunnerOutput, $dispatchRunnerStatus);
$dispatchRunner = json_decode(trim($dispatchRunnerOutput[0] ?? ''), true);
var_dump($dispatchRunnerStatus);
var_dump(($dispatchRunner['result_text'] ?? null) === 'allowed-dispatch');
var_dump(($dispatchRunner['run_status'] ?? null) === 'completed');
var_dump(($dispatchRunner['run_error'] ?? null) === null);
var_dump(($dispatchRunner['result_history'] ?? null) === ['remote-prepare']);

$dispatchCapture = king_orchestrator_remote_peer_stop($dispatchServer);
var_dump(count($dispatchCapture['events'] ?? []) === 1);
var_dump(($dispatchCapture['events'][0]['initial_data']['text'] ?? null) === 'allowed-dispatch');

$resumeServer = king_orchestrator_remote_peer_start(null, '127.0.0.1', null, [$handlerBootstrapScript]);
$resumeObserverCommand = static fn(string $runId) => $buildCommand($resumeServer, $resumeStatePath, $resumeObserverScript, [$runId]);
$resumeGateCommand = static fn(string $runId) => $buildCommand($resumeServer, $resumeStatePath, $resumeGateScript, [$runId]);
$resumeRunnerCommand = static fn(string $runId) => $buildCommand($resumeServer, $resumeStatePath, $resumeRunnerScript, [$runId]);

$controllerArgv = [
    PHP_BINARY,
    '-n',
    '-d', 'extension=' . $extensionPath,
    '-d', 'king.security_allow_config_override=1',
    '-d', 'king.orchestrator_execution_backend=remote_peer',
    '-d', 'king.orchestrator_remote_host=' . $resumeServer['host'],
    '-d', 'king.orchestrator_remote_port=' . $resumeServer['port'],
    '-d', 'king.orchestrator_state_path=' . $resumeStatePath,
    $resumeControllerScript,
];

$controllerProcess = proc_open($controllerArgv, [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $controllerPipes);

$runningObserved = false;
for ($i = 0; $i < 600; $i++) {
    $observerOutput = [];
    $observerStatus = -1;
    exec($resumeObserverCommand('run-1'), $observerOutput, $observerStatus);
    $snapshot = json_decode(trim($observerOutput[0] ?? ''), true);

    if (
        $observerStatus === 0
        && is_array($snapshot)
        && ($snapshot['run_id'] ?? null) === 'run-1'
        && ($snapshot['status'] ?? null) === 'running'
        && ($snapshot['finished_at'] ?? null) === 0
        && ($snapshot['error'] ?? null) === null
        && ($snapshot['recovery_count'] ?? null) === 0
        && ($snapshot['remote_attempt_count'] ?? null) === 1
        && ($snapshot['handler_boundary']['required_tools'] ?? null) === ['prepare', 'finalize']
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
var_dump($killStatus);

$controllerStdout = stream_get_contents($controllerPipes[1]);
$controllerStderr = stream_get_contents($controllerPipes[2]);
fclose($controllerPipes[1]);
fclose($controllerPipes[2]);
$controllerExit = proc_close($controllerProcess);
var_dump($controllerExit !== 0);
var_dump(trim($controllerStdout) === '');
var_dump(trim($controllerStderr) === '');

exec($resumeGateCommand('run-1'), $resumeGateOutput, $resumeGateStatus);
$resumeGate = json_decode(trim($resumeGateOutput[0] ?? ''), true);
var_dump($resumeGateStatus);
var_dump(($resumeGate['init'] ?? null) === true);
var_dump(($resumeGate['restart'] ?? null) === true);
var_dump(($resumeGate['lifecycle'] ?? null) === 'draining');
var_dump(($resumeGate['remote_peer_dispatches'] ?? null) === false);
var_dump(($resumeGate['remote_peer_resumes'] ?? null) === false);
var_dump(($resumeGate['exception_class'] ?? null) === 'King\\RuntimeException');
var_dump(str_contains((string) ($resumeGate['exception_message'] ?? ''), 'cannot admit remote_peer_resumes'));
var_dump(str_contains((string) ($resumeGate['exception_message'] ?? ''), "lifecycle is 'draining'"));

exec($resumeObserverCommand('run-1'), $resumeBlockedOutput, $resumeBlockedStatus);
$resumeBlocked = json_decode(trim($resumeBlockedOutput[0] ?? ''), true);
var_dump($resumeBlockedStatus);
var_dump(($resumeBlocked['status'] ?? null) === 'running');
var_dump(($resumeBlocked['error'] ?? null) === null);
var_dump(($resumeBlocked['recovery_count'] ?? null) === 0);
var_dump(($resumeBlocked['remote_attempt_count'] ?? null) === 1);

exec($resumeRunnerCommand('run-1'), $resumeRunnerOutput, $resumeRunnerStatus);
$resumeRunner = json_decode(trim($resumeRunnerOutput[0] ?? ''), true);
var_dump($resumeRunnerStatus);
var_dump(($resumeRunner['recovered_from_state'] ?? null) === true);
var_dump(($resumeRunner['result_text'] ?? null) === 'blocked-resume');
var_dump(($resumeRunner['run_status'] ?? null) === 'completed');
var_dump(($resumeRunner['run_error'] ?? null) === null);
var_dump(($resumeRunner['recovery_count'] ?? null) === 1);
var_dump(($resumeRunner['remote_attempt_count'] ?? null) === 2);
var_dump(($resumeRunner['result_history'] ?? null) === ['remote-prepare', 'remote-finalize']);

$resumeCapture = king_orchestrator_remote_peer_stop($resumeServer);
var_dump(count($resumeCapture['events'] ?? []) === 2);
var_dump(($resumeCapture['events'][0]['initial_data']['text'] ?? null) === 'blocked-resume');
var_dump(($resumeCapture['events'][1]['initial_data']['text'] ?? null) === 'blocked-resume');

foreach ([
    $dispatchStatePath,
    $resumeStatePath,
    $dispatchGateScript,
    $dispatchRunnerScript,
    $resumeControllerScript,
    $resumeObserverScript,
    $resumeGateScript,
    $resumeRunnerScript,
    $handlerBootstrapScript,
] as $path) {
    @unlink($path);
}
?>
--EXPECT--
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
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
