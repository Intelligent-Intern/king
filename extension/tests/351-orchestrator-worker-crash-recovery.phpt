--TEST--
King file-worker recovers a running claimed run after worker loss without duplicate completion
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-crash-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-crash-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-crash-controller-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-crash-observer-');
$crashWorkerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-crash-worker-');
$recoveryWorkerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-crash-recovery-worker-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($controllerScript, <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'recover-after-crash'],
    [['tool' => 'summarizer', 'delay_ms' => 15000]],
    ['trace_id' => 'crash-recovery']
);
echo $dispatch['run_id'], "\n";
PHP);

file_put_contents($observerScript, <<<'PHP'
<?php
$runId = $argv[1];
$run = king_pipeline_orchestrator_get_run($runId);
echo json_encode([
    'run_id' => $run['run_id'],
    'status' => $run['status'],
    'finished_at' => $run['finished_at'],
    'cancel_requested' => $run['cancel_requested'],
    'result_text' => $run['result']['text'] ?? null,
    'error' => $run['error'],
]), "\n";
PHP);

file_put_contents($crashWorkerScript, <<<'PHP'
<?php
king_pipeline_orchestrator_worker_run_next();
PHP);

file_put_contents($recoveryWorkerScript, <<<'PHP'
<?php
$work = king_pipeline_orchestrator_worker_run_next();
if ($work === false) {
    echo "false\n";
    return;
}

echo json_encode([
    'run_id' => $work['run_id'],
    'status' => $work['status'],
    'cancel_requested' => $work['cancel_requested'],
    'text' => $work['result']['text'] ?? null,
    'error' => $work['error'],
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

$controllerCommand = sprintf($baseCommand, escapeshellarg($controllerScript));
$recoveryWorkerCommand = sprintf($baseCommand, escapeshellarg($recoveryWorkerScript));
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
$observerCommand = static fn(string $runId): string => sprintf(
    $baseCommand . ' %s',
    escapeshellarg($observerScript),
    escapeshellarg($runId)
);

exec($controllerCommand, $controllerOutput, $controllerStatus);
$runId = trim($controllerOutput[0] ?? '');
var_dump($controllerStatus);
var_dump(preg_match('/^run-\d+$/', $runId) === 1);

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$workerProcess = proc_open($crashWorkerArgv, $descriptors, $workerPipes);

$claimedObserved = false;
for ($i = 0; $i < 400; $i++) {
    if (
        is_array(glob($queuePath . '/claimed-*.job'))
        && count(glob($queuePath . '/claimed-*.job')) === 1
    ) {
        $claimedObserved = true;
        break;
    }
    usleep(10000);
}

var_dump($claimedObserved);
usleep(100000);

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
var_dump(is_array(glob($queuePath . '/claimed-*.job')));
var_dump(count(glob($queuePath . '/claimed-*.job')) === 1);

$observerAfterKillOutput = [];
$observerAfterKillStatus = -1;
exec($observerCommand($runId), $observerAfterKillOutput, $observerAfterKillStatus);
$afterKill = json_decode(trim($observerAfterKillOutput[0] ?? ''), true);
var_dump($observerAfterKillStatus);
var_dump(($afterKill['status'] ?? null) === 'running');
var_dump(($afterKill['finished_at'] ?? null) === 0);
var_dump(($afterKill['result_text'] ?? null) === null);
var_dump(($afterKill['error'] ?? null) === null);

$recoveryWorkerOutput = [];
$recoveryWorkerStatus = -1;
exec($recoveryWorkerCommand, $recoveryWorkerOutput, $recoveryWorkerStatus);
$recovered = json_decode(trim($recoveryWorkerOutput[0] ?? ''), true);
var_dump($recoveryWorkerStatus);
var_dump(($recovered['run_id'] ?? null) === $runId);
var_dump(($recovered['status'] ?? null) === 'completed');
var_dump(($recovered['cancel_requested'] ?? null) === false);
var_dump(($recovered['text'] ?? null) === 'recover-after-crash');
var_dump(($recovered['error'] ?? null) === null);

$emptyOutput = [];
$emptyStatus = -1;
exec($recoveryWorkerCommand, $emptyOutput, $emptyStatus);
var_dump($emptyStatus);
var_dump(trim($emptyOutput[0] ?? '') === 'false');

$observerAfterOutput = [];
$observerAfterStatus = -1;
exec($observerCommand($runId), $observerAfterOutput, $observerAfterStatus);
$after = json_decode(trim($observerAfterOutput[0] ?? ''), true);
var_dump($observerAfterStatus);
var_dump(($after['status'] ?? null) === 'completed');
var_dump(($after['finished_at'] ?? 0) > 0);
var_dump(($after['cancel_requested'] ?? null) === false);
var_dump(($after['result_text'] ?? null) === 'recover-after-crash');
var_dump(($after['error'] ?? null) === null);
var_dump(count(glob($queuePath . '/claimed-*.job')) === 0);
var_dump(count(glob($queuePath . '/queued-*.job')) === 0);

foreach ([
    $controllerScript,
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
int(0)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
