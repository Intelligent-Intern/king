--TEST--
King remote-peer runs execute independently registered userland handlers and fail closed when the peer lacks them
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_remote_peer_helper.inc';

$extensionPath = dirname(__DIR__) . '/modules/king.so';
$successStatePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-userland-success-state-');
$failureStatePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-userland-failure-state-');
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-userland-controller-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-userland-observer-');
$resumeScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-userland-resume-');
$failureRunnerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-userland-failure-runner-');
$successBootstrapScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-userland-success-bootstrap-');
$failureBootstrapScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-userland-failure-bootstrap-');

@unlink($successStatePath);
@unlink($failureStatePath);

file_put_contents($successBootstrapScript, <<<'PHP'
<?php
function remote_prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected remote prepare input');
    }
    if (!array_key_exists('cancel', $context) || !is_null($context['cancel'])) {
        throw new RuntimeException('unexpected remote prepare cancel token');
    }
    if (!array_key_exists('timeout_budget_ms', $context) || !is_int($context['timeout_budget_ms'])) {
        throw new RuntimeException('unexpected remote prepare timeout budget');
    }
    if (!array_key_exists('deadline_budget_ms', $context) || !is_int($context['deadline_budget_ms'])) {
        throw new RuntimeException('unexpected remote prepare deadline budget');
    }

    $input['history'][] = sprintf(
        'remote-prepare:%s:%d:%d',
        (($context['tool']['config']['label'] ?? null) ?? 'missing'),
        (int) $context['timeout_budget_ms'],
        (int) $context['deadline_budget_ms']
    );
    return ['output' => $input];
}

function remote_finalize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected remote finalize input');
    }
    if (!array_key_exists('cancel', $context) || !is_null($context['cancel'])) {
        throw new RuntimeException('unexpected remote finalize cancel token');
    }
    if (!array_key_exists('timeout_budget_ms', $context) || !is_int($context['timeout_budget_ms'])) {
        throw new RuntimeException('unexpected remote finalize timeout budget');
    }
    if (!array_key_exists('deadline_budget_ms', $context) || !is_int($context['deadline_budget_ms'])) {
        throw new RuntimeException('unexpected remote finalize deadline budget');
    }

    $input['history'][] = sprintf(
        'remote-finalize:%s:%d:%d',
        (($context['tool']['config']['label'] ?? null) ?? 'missing'),
        (int) $context['timeout_budget_ms'],
        (int) $context['deadline_budget_ms']
    );
    return ['output' => $input];
}

return [
    'prepare' => 'remote_prepare_handler',
    'finalize' => 'remote_finalize_handler',
];
PHP);

file_put_contents($failureBootstrapScript, <<<'PHP'
<?php
function remote_prepare_only_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected remote prepare-only input');
    }
    if (!array_key_exists('cancel', $context) || !is_null($context['cancel'])) {
        throw new RuntimeException('unexpected remote prepare-only cancel token');
    }
    if (!array_key_exists('timeout_budget_ms', $context) || !is_int($context['timeout_budget_ms'])) {
        throw new RuntimeException('unexpected remote prepare-only timeout budget');
    }
    if (!array_key_exists('deadline_budget_ms', $context) || !is_int($context['deadline_budget_ms'])) {
        throw new RuntimeException('unexpected remote prepare-only deadline budget');
    }

    $input['history'][] = sprintf(
        'remote-prepare-only:%d:%d',
        (int) $context['timeout_budget_ms'],
        (int) $context['deadline_budget_ms']
    );
    return ['output' => $input];
}

return [
    'prepare' => 'remote_prepare_only_handler',
];
PHP);

file_put_contents($controllerScript, <<<'PHP'
<?php
function controller_prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected controller prepare input');
    }

    $input['history'][] = 'controller-prepare';
    return ['output' => $input];
}

function controller_finalize_handler(array $context): array
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
king_pipeline_orchestrator_register_handler('prepare', 'controller_prepare_handler');
king_pipeline_orchestrator_register_handler('finalize', 'controller_finalize_handler');

king_pipeline_orchestrator_run(
    ['text' => 'remote-userland', 'history' => []],
    [
        ['tool' => 'prepare'],
        ['tool' => 'finalize', 'delay_ms' => 5000],
    ],
    ['trace_id' => 'remote-userland-success']
);
PHP);

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
    'error' => $run['error'] ?? null,
    'handler_boundary' => $run['handler_boundary'] ?? null,
    'result_history' => $run['result']['history'] ?? null,
]), "\n";
PHP);

file_put_contents($resumeScript, <<<'PHP'
<?php
$runId = $argv[1] ?? 'run-1';
$info = king_system_get_component_info('pipeline_orchestrator');
$result = king_pipeline_orchestrator_resume_run($runId);
$run = king_pipeline_orchestrator_get_run($runId);

echo json_encode([
    'recovered_from_state' => $info['configuration']['recovered_from_state'] ?? null,
    'result_history' => $result['history'] ?? null,
    'run_status' => $run['status'] ?? null,
    'run_error' => $run['error'] ?? null,
    'handler_boundary' => $run['handler_boundary'] ?? null,
]), "\n";
PHP);

file_put_contents($failureRunnerScript, <<<'PHP'
<?php
function controller_prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected failure prepare input');
    }

    $input['history'][] = 'controller-prepare';
    return ['output' => $input];
}

function controller_finalize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected failure finalize input');
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
king_pipeline_orchestrator_register_handler('prepare', 'controller_prepare_handler');
king_pipeline_orchestrator_register_handler('finalize', 'controller_finalize_handler');

try {
    king_pipeline_orchestrator_run(
        ['text' => 'remote-userland-failure', 'history' => []],
        [
            ['tool' => 'prepare'],
            ['tool' => 'finalize'],
        ],
        ['trace_id' => 'remote-userland-failure']
    );
    echo json_encode(['unexpected' => 'no-exception']), "\n";
} catch (Throwable $e) {
    $run = king_pipeline_orchestrator_get_run('run-1');
    echo json_encode([
        'exception_class' => get_class($e),
        'message' => $e->getMessage(),
        'status' => $run['status'] ?? null,
        'error' => $run['error'] ?? null,
        'error_category' => $run['error_classification']['category'] ?? null,
        'error_retry_disposition' => $run['error_classification']['retry_disposition'] ?? null,
        'error_backend' => $run['error_classification']['backend'] ?? null,
        'error_step_index' => $run['error_classification']['step_index'] ?? null,
        'handler_boundary' => $run['handler_boundary'] ?? null,
    ]), "\n";
}
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

$successServer = king_orchestrator_remote_peer_start(null, '127.0.0.1', null, [$successBootstrapScript]);
$successObserverCommand = static fn(string $runId) => $buildCommand($successServer, $successStatePath, $observerScript, [$runId]);
$successResumeCommand = static fn(string $runId) => $buildCommand($successServer, $successStatePath, $resumeScript, [$runId]);

$controllerArgv = [
    PHP_BINARY,
    '-n',
    '-d', 'extension=' . $extensionPath,
    '-d', 'king.security_allow_config_override=1',
    '-d', 'king.orchestrator_execution_backend=remote_peer',
    '-d', 'king.orchestrator_remote_host=' . $successServer['host'],
    '-d', 'king.orchestrator_remote_port=' . $successServer['port'],
    '-d', 'king.orchestrator_state_path=' . $successStatePath,
    $controllerScript,
];

$controllerProcess = proc_open($controllerArgv, [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $controllerPipes);

$runningObserved = false;
$remoteBoundaryObserved = false;
for ($i = 0; $i < 600; $i++) {
    $observerOutput = [];
    $observerStatus = -1;
    exec($successObserverCommand('run-1'), $observerOutput, $observerStatus);
    $snapshot = json_decode(trim($observerOutput[0] ?? ''), true);

    if (
        $observerStatus === 0
        && is_array($snapshot)
        && ($snapshot['run_id'] ?? null) === 'run-1'
        && ($snapshot['status'] ?? null) === 'running'
        && ($snapshot['finished_at'] ?? null) === 0
        && ($snapshot['error'] ?? null) === null
        && ($snapshot['handler_boundary']['required_tools'] ?? null) === ['prepare', 'finalize']
    ) {
        $runningObserved = true;
    }

    if (is_file($successServer['capture'])) {
        $serverCapture = json_decode((string) file_get_contents($successServer['capture']), true);
        if (
            is_array($serverCapture)
            && ($serverCapture['events'][0]['handler_boundary']['required_tools'] ?? null) === ['prepare', 'finalize']
            && ($serverCapture['events'][0]['tool_configs']['prepare']['label'] ?? null) === 'prepare-config'
            && ($serverCapture['events'][0]['tool_configs']['finalize']['label'] ?? null) === 'finalize-config'
        ) {
            $remoteBoundaryObserved = true;
        }
    }

    if ($runningObserved && $remoteBoundaryObserved) {
        break;
    }

    usleep(10000);
}

var_dump($runningObserved);
var_dump($remoteBoundaryObserved);

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

$resumeOutput = [];
$resumeStatus = -1;
exec($successResumeCommand('run-1'), $resumeOutput, $resumeStatus);
$resumed = json_decode(trim($resumeOutput[0] ?? ''), true);
var_dump($resumeStatus);
var_dump(($resumed['recovered_from_state'] ?? null) === true);
var_dump(is_array($resumed['result_history'] ?? null));
var_dump(count($resumed['result_history'] ?? []) === 2);
var_dump(str_starts_with((string) ($resumed['result_history'][0] ?? ''), 'remote-prepare:prepare-config:'));
var_dump(str_starts_with((string) ($resumed['result_history'][1] ?? ''), 'remote-finalize:finalize-config:'));
var_dump(($resumed['run_status'] ?? null) === 'completed');
var_dump(($resumed['run_error'] ?? null) === null);
var_dump(($resumed['handler_boundary']['required_tools'] ?? null) === ['prepare', 'finalize']);

$successCapture = king_orchestrator_remote_peer_stop($successServer);
var_dump(($successCapture['registered_handlers'] ?? null) === ['prepare', 'finalize']);
var_dump(count($successCapture['events'] ?? []) === 2);
var_dump(($successCapture['events'][0]['handler_boundary']['required_tools'] ?? null) === ['prepare', 'finalize']);
var_dump(($successCapture['events'][1]['tool_configs']['finalize']['label'] ?? null) === 'finalize-config');
var_dump(is_array($successCapture['events'][1]['result']['history'] ?? null));
var_dump(count($successCapture['events'][1]['result']['history'] ?? []) === 2);
var_dump(
    str_starts_with(
        (string) (($successCapture['events'][1]['result']['history'][0] ?? '') ?: ''),
        'remote-prepare:prepare-config:'
    )
);
var_dump(
    str_starts_with(
        (string) (($successCapture['events'][1]['result']['history'][1] ?? '') ?: ''),
        'remote-finalize:finalize-config:'
    )
);

$failureServer = king_orchestrator_remote_peer_start(null, '127.0.0.1', null, [$failureBootstrapScript]);
$failureCommand = $buildCommand($failureServer, $failureStatePath, $failureRunnerScript);

$failureOutput = [];
$failureStatus = -1;
exec($failureCommand, $failureOutput, $failureStatus);
$failed = json_decode(trim($failureOutput[0] ?? ''), true);
var_dump($failureStatus);
var_dump(($failed['exception_class'] ?? null) === 'King\\RuntimeException');
var_dump(($failed['message'] ?? null) === "remote peer has no registered handler for tool 'finalize'.");
var_dump(($failed['status'] ?? null) === 'failed');
var_dump(($failed['error'] ?? null) === "remote peer has no registered handler for tool 'finalize'.");
var_dump(($failed['error_category'] ?? null) === 'missing_handler');
var_dump(($failed['error_retry_disposition'] ?? null) === 'caller_managed_retry');
var_dump(($failed['error_backend'] ?? null) === 'remote_peer');
var_dump(($failed['error_step_index'] ?? null) === 1);
var_dump(($failed['handler_boundary']['required_tools'] ?? null) === ['prepare', 'finalize']);

$failureCapture = king_orchestrator_remote_peer_stop($failureServer);
var_dump(($failureCapture['registered_handlers'] ?? null) === ['prepare']);
var_dump(count($failureCapture['events'] ?? []) === 1);
var_dump(($failureCapture['events'][0]['failed_step_index'] ?? null) === 1);
var_dump(($failureCapture['events'][0]['handler_boundary']['required_tools'] ?? null) === ['prepare', 'finalize']);

foreach ([
    $successStatePath,
    $failureStatePath,
    $controllerScript,
    $observerScript,
    $resumeScript,
    $failureRunnerScript,
    $successBootstrapScript,
    $failureBootstrapScript,
] as $path) {
    @unlink($path);
}
?>
--EXPECT--
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
