--TEST--
King pipeline orchestrator can continue a persisted running run after controller restart
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-process-restart-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-process-controller-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-process-observer-');
$resumeScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-process-resume-');

@unlink($statePath);

file_put_contents($controllerScript, <<<'PHP'
<?php
function prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected prepare input');
    }

    $input['history'][] = 'prepare';
    return ['output' => $input];
}

function finalize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected finalize input');
    }

    $input['history'][] = 'finalize';
    return ['output' => $input];
}

king_pipeline_orchestrator_register_tool('prepare', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('prepare', 'prepare_handler');
king_pipeline_orchestrator_register_tool('finalize', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('finalize', 'finalize_handler');
king_pipeline_orchestrator_run(
    ['text' => 'resume-after-restart', 'history' => []],
    [
        ['tool' => 'prepare'],
        ['tool' => 'finalize', 'delay_ms' => 2000],
    ],
    ['trace_id' => 'process-restart-run']
);
PHP);

file_put_contents($observerScript, <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run('run-1');
if ($run === false) {
    echo "false\n";
    return;
}

echo json_encode([
    'run_id' => $run['run_id'],
    'status' => $run['status'],
    'finished_at' => $run['finished_at'],
    'result_text' => $run['result']['text'] ?? null,
    'result_history' => $run['result']['history'] ?? null,
    'error' => $run['error'],
]), "\n";
PHP);

file_put_contents($resumeScript, <<<'PHP'
<?php
function prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected prepare input');
    }

    $input['history'][] = 'prepare';
    return ['output' => $input];
}

function finalize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected finalize input');
    }

    $input['history'][] = 'finalize';
    return ['output' => $input];
}

king_pipeline_orchestrator_register_handler('prepare', 'prepare_handler');
king_pipeline_orchestrator_register_handler('finalize', 'finalize_handler');
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['recovered_from_state']);
$result = king_pipeline_orchestrator_resume_run('run-1');
var_dump($result['text']);
var_dump($result['history']);
$run = king_pipeline_orchestrator_get_run('run-1');
var_dump($run['run_id']);
var_dump($run['status']);
var_dump($run['finished_at'] > 0);
var_dump($run['result']['text']);
var_dump($run['result']['history']);
var_dump($run['error']);
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['run_history_count']);
var_dump($info['configuration']['active_run_count']);
var_dump($info['configuration']['last_run_id']);
var_dump($info['configuration']['last_run_status']);
try {
    king_pipeline_orchestrator_resume_run('run-1');
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), "'completed'"));
}
PHP);

$baseCommand = sprintf(
    '%s -n -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    '%s'
);

$observerCommand = sprintf($baseCommand, escapeshellarg($observerScript));
$resumeCommand = sprintf($baseCommand, escapeshellarg($resumeScript));
$controllerArgv = [
    PHP_BINARY,
    '-n',
    '-d', 'extension=' . $extensionPath,
    '-d', 'king.security_allow_config_override=1',
    '-d', 'king.orchestrator_state_path=' . $statePath,
    $controllerScript,
];

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$controllerProcess = proc_open($controllerArgv, $descriptors, $controllerPipes);

$runningObserved = false;
for ($i = 0; $i < 400; $i++) {
    $observerOutput = [];
    $observerStatus = -1;
    exec($observerCommand, $observerOutput, $observerStatus);
    $snapshot = json_decode(trim($observerOutput[0] ?? ''), true);
    if (
        $observerStatus === 0
        && is_array($snapshot)
        && ($snapshot['run_id'] ?? null) === 'run-1'
        && ($snapshot['status'] ?? null) === 'running'
        && ($snapshot['finished_at'] ?? null) === 0
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

$observerAfterKillOutput = [];
$observerAfterKillStatus = -1;
exec($observerCommand, $observerAfterKillOutput, $observerAfterKillStatus);
$afterKill = json_decode(trim($observerAfterKillOutput[0] ?? ''), true);
var_dump($observerAfterKillStatus);
var_dump(($afterKill['run_id'] ?? null) === 'run-1');
var_dump(($afterKill['status'] ?? null) === 'running');
var_dump(($afterKill['finished_at'] ?? null) === 0);
var_dump(($afterKill['result_text'] ?? null) === 'resume-after-restart');
var_dump(($afterKill['result_history'] ?? null) === ['prepare']);
var_dump(($afterKill['error'] ?? null) === null);

$resumeOutput = [];
$resumeStatus = -1;
exec($resumeCommand, $resumeOutput, $resumeStatus);
var_dump($resumeStatus);
echo implode("\n", $resumeOutput), "\n";

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
int(0)
bool(true)
string(20) "resume-after-restart"
array(2) {
  [0]=>
  string(7) "prepare"
  [1]=>
  string(8) "finalize"
}
string(5) "run-1"
string(9) "completed"
bool(true)
string(20) "resume-after-restart"
array(2) {
  [0]=>
  string(7) "prepare"
  [1]=>
  string(8) "finalize"
}
NULL
int(1)
int(0)
string(5) "run-1"
string(9) "completed"
string(21) "King\RuntimeException"
bool(true)
