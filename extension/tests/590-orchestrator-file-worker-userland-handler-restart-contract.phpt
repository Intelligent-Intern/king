--TEST--
King file-worker executes registered userland handlers across worker restart under explicit re-registration
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-file-worker-userland-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-file-worker-userland-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$dispatchScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-file-worker-userland-dispatch-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-file-worker-userland-observer-');
$crashWorkerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-file-worker-userland-crash-');
$recoveryWorkerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-file-worker-userland-recovery-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($dispatchScript, <<<'PHP'
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
king_pipeline_orchestrator_register_tool('finalize', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('prepare', 'prepare_handler');
king_pipeline_orchestrator_register_handler('finalize', 'finalize_handler');
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'file-worker-restart', 'history' => []],
    [
        ['tool' => 'prepare'],
        ['tool' => 'finalize', 'delay_ms' => 2000],
    ],
    ['trace_id' => 'file-worker-userland-restart']
);
echo $dispatch['run_id'], "\n";
PHP);

file_put_contents($observerScript, <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1]);
echo json_encode([
    'run_id' => $run['run_id'] ?? null,
    'status' => $run['status'] ?? null,
    'finished_at' => $run['finished_at'] ?? null,
    'queue_phase' => $run['distributed_observability']['queue_phase'] ?? null,
    'completed_step_count' => $run['completed_step_count'] ?? null,
    'result_text' => $run['result']['text'] ?? null,
    'result_history' => $run['result']['history'] ?? null,
    'handler_boundary' => $run['handler_boundary'] ?? null,
    'error' => $run['error'] ?? null,
]), "\n";
PHP);

file_put_contents($crashWorkerScript, <<<'PHP'
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
king_pipeline_orchestrator_worker_run_next();
PHP);

file_put_contents($recoveryWorkerScript, <<<'PHP'
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
$work = king_pipeline_orchestrator_worker_run_next();
if ($work === false) {
    echo "false\n";
    return;
}

echo json_encode([
    'run_id' => $work['run_id'] ?? null,
    'status' => $work['status'] ?? null,
    'queue_phase' => $work['distributed_observability']['queue_phase'] ?? null,
    'result_text' => $work['result']['text'] ?? null,
    'result_history' => $work['result']['history'] ?? null,
    'error' => $work['error'] ?? null,
]), "\n";
PHP);

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

$dispatchCommand = sprintf($baseCommand, escapeshellarg($dispatchScript));
$observerCommand = static fn(string $runId): string => sprintf(
    $baseCommand . ' %s',
    escapeshellarg($observerScript),
    escapeshellarg($runId)
);
$recoveryWorkerCommand = sprintf($baseCommand, escapeshellarg($recoveryWorkerScript));

exec($dispatchCommand, $dispatchOutput, $dispatchStatus);
$runId = trim($dispatchOutput[0] ?? '');
var_dump($dispatchStatus);
var_dump(preg_match('/^run-\d+$/', $runId) === 1);

$crashWorkerArgv = [
    PHP_BINARY,
    '-n',
    '-d', 'extension=' . $extensionPath,
    '-d', 'king.security_allow_config_override=1',
    '-d', 'king.orchestrator_execution_backend=file_worker',
    '-d', 'king.orchestrator_worker_queue_path=' . $queuePath,
    '-d', 'king.orchestrator_state_path=' . $statePath,
    $crashWorkerScript,
];
$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$workerProcess = proc_open($crashWorkerArgv, $descriptors, $workerPipes);

$runningObserved = false;
for ($i = 0; $i < 400; $i++) {
    $observerOutput = [];
    $observerStatus = -1;
    exec($observerCommand($runId), $observerOutput, $observerStatus);
    $snapshot = json_decode(trim($observerOutput[0] ?? ''), true);

    if (
        $observerStatus === 0
        && is_array($snapshot)
        && ($snapshot['status'] ?? null) === 'running'
        && ($snapshot['queue_phase'] ?? null) === 'claimed'
        && ($snapshot['completed_step_count'] ?? null) === 1
        && ($snapshot['result_history'] ?? null) === ['prepare']
        && ($snapshot['finished_at'] ?? null) === 0
    ) {
        $runningObserved = true;
        break;
    }
    usleep(10000);
}
var_dump($runningObserved);

$workerStatusInfo = proc_get_status($workerProcess);
$workerPid = (int) ($workerStatusInfo['pid'] ?? 0);
$killStatus = -1;
exec('/bin/kill -9 ' . $workerPid, $killOutput, $killStatus);
var_dump($killStatus);

$workerStdout = stream_get_contents($workerPipes[1]);
$workerStderr = stream_get_contents($workerPipes[2]);
fclose($workerPipes[1]);
fclose($workerPipes[2]);
$workerExit = proc_close($workerProcess);
var_dump($workerExit !== 0);
var_dump(trim($workerStdout) === '');
var_dump(trim($workerStderr) === '');

$observerAfterKillOutput = [];
$observerAfterKillStatus = -1;
exec($observerCommand($runId), $observerAfterKillOutput, $observerAfterKillStatus);
$afterKill = json_decode(trim($observerAfterKillOutput[0] ?? ''), true);
var_dump($observerAfterKillStatus);
var_dump(($afterKill['status'] ?? null) === 'running');
var_dump(($afterKill['queue_phase'] ?? null) === 'claimed');
var_dump(($afterKill['completed_step_count'] ?? null) === 1);
var_dump(($afterKill['finished_at'] ?? null) === 0);
var_dump(($afterKill['result_history'] ?? null) === ['prepare']);
var_dump(($afterKill['error'] ?? null) === null);

$recoveryWorkerOutput = [];
$recoveryWorkerStatus = -1;
exec($recoveryWorkerCommand, $recoveryWorkerOutput, $recoveryWorkerStatus);
$recovered = json_decode(trim($recoveryWorkerOutput[0] ?? ''), true);
var_dump($recoveryWorkerStatus);
var_dump(($recovered['run_id'] ?? null) === $runId);
var_dump(($recovered['status'] ?? null) === 'completed');
var_dump(($recovered['queue_phase'] ?? null) === 'dequeued');
var_dump(($recovered['result_text'] ?? null) === 'file-worker-restart');
var_dump(($recovered['result_history'] ?? null) === ['prepare', 'finalize']);
var_dump(($recovered['error'] ?? null) === null);

$observerAfterOutput = [];
$observerAfterStatus = -1;
exec($observerCommand($runId), $observerAfterOutput, $observerAfterStatus);
$after = json_decode(trim($observerAfterOutput[0] ?? ''), true);
var_dump($observerAfterStatus);
var_dump(($after['status'] ?? null) === 'completed');
var_dump(($after['queue_phase'] ?? null) === 'dequeued');
var_dump(($after['completed_step_count'] ?? null) === 2);
var_dump(($after['finished_at'] ?? 0) > 0);
var_dump(($after['result_history'] ?? null) === ['prepare', 'finalize']);
var_dump(($after['error'] ?? null) === null);
var_dump(($after['handler_boundary']['required_tools'] ?? null) === ['prepare', 'finalize']);
var_dump(count(glob($queuePath . '/claimed-*.job')) === 0);
var_dump(count(glob($queuePath . '/queued-*.job')) === 0);

foreach ([
    $dispatchScript,
    $observerScript,
    $crashWorkerScript,
    $recoveryWorkerScript,
    $statePath,
] as $path) {
    @unlink($path);
}
if (is_dir($queuePath)) {
    foreach (scandir($queuePath) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            @unlink($queuePath . '/' . $entry);
        }
    }
    @rmdir($queuePath);
}
?>
--EXPECT--
int(0)
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
int(0)
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
