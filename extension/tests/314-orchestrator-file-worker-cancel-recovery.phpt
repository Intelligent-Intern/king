--TEST--
King file-worker orchestrator cancellation propagates across claimed runs and stale claimed-job recovery
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-cancel-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-cancel-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$dispatchLiveScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-dispatch-live-');
$dispatchRecoveryScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-dispatch-recovery-');
$cancelScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-cancel-run-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-cancel-worker-');

@unlink($statePath);
@mkdir($queuePath, 0777, true);

file_put_contents($dispatchLiveScript, <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'live-cancel'],
    [['tool' => 'summarizer', 'delay_ms' => 500]],
    ['trace_id' => 'cancel-live']
);
echo $dispatch['run_id'], "\n";
PHP);

file_put_contents($dispatchRecoveryScript, <<<'PHP'
<?php
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'recovery-cancel'],
    [['tool' => 'summarizer', 'delay_ms' => 500]],
    ['trace_id' => 'cancel-recovery']
);
echo $dispatch['run_id'], "\n";
PHP);

file_put_contents($cancelScript, <<<'PHP'
<?php
$runId = $argv[1];
var_dump(king_pipeline_orchestrator_cancel_run($runId));
$run = king_pipeline_orchestrator_get_run($runId);
var_dump($run['cancel_requested']);
PHP);

file_put_contents($workerScript, <<<'PHP'
<?php
$work = king_pipeline_orchestrator_worker_run_next();
var_dump(preg_match('/^run-\d+$/', $work['run_id']) === 1);
var_dump($work['status']);
var_dump($work['cancel_requested']);
var_dump($work['error']);
var_dump(king_pipeline_orchestrator_worker_run_next());
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

$dispatchLiveCommand = sprintf($baseCommand, escapeshellarg($dispatchLiveScript));
$dispatchRecoveryCommand = sprintf($baseCommand, escapeshellarg($dispatchRecoveryScript));
$workerCommand = sprintf($baseCommand, escapeshellarg($workerScript));

exec($dispatchLiveCommand, $liveOutput, $liveStatus);
$liveRunId = trim($liveOutput[0] ?? '');
var_dump($liveStatus);
var_dump(preg_match('/^run-\d+$/', $liveRunId) === 1);

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$workerProcess = proc_open($workerCommand, $descriptors, $workerPipes);
$claimedDetected = false;
for ($i = 0; $i < 100; $i++) {
    $claimedFiles = glob($queuePath . '/claimed-*.job');
    if (is_array($claimedFiles) && count($claimedFiles) > 0) {
        $claimedDetected = true;
        break;
    }
    usleep(10000);
}
var_dump($claimedDetected);

$cancelLiveCommand = sprintf(
    $baseCommand . ' %s',
    escapeshellarg($cancelScript),
    escapeshellarg($liveRunId)
);
exec($cancelLiveCommand, $cancelLiveOutput, $cancelLiveStatus);
var_dump($cancelLiveStatus);
echo implode("\n", $cancelLiveOutput), "\n";

$workerStdout = stream_get_contents($workerPipes[1]);
$workerStderr = stream_get_contents($workerPipes[2]);
fclose($workerPipes[1]);
fclose($workerPipes[2]);
$workerStatus = proc_close($workerProcess);
var_dump($workerStatus);
echo trim($workerStdout), "\n";
var_dump(trim($workerStderr) === '');

exec($dispatchRecoveryCommand, $recoveryOutput, $recoveryStatus);
$recoveryRunId = trim($recoveryOutput[0] ?? '');
var_dump($recoveryStatus);
var_dump(preg_match('/^run-\d+$/', $recoveryRunId) === 1);
var_dump(rename(
    $queuePath . '/queued-' . $recoveryRunId . '.job',
    $queuePath . '/claimed-9999-queued-' . $recoveryRunId . '.job'
));

$cancelRecoveryCommand = sprintf(
    $baseCommand . ' %s',
    escapeshellarg($cancelScript),
    escapeshellarg($recoveryRunId)
);
exec($cancelRecoveryCommand, $cancelRecoveryOutput, $cancelRecoveryStatus);
var_dump($cancelRecoveryStatus);
echo implode("\n", $cancelRecoveryOutput), "\n";

exec($workerCommand, $recoveryWorkerOutput, $recoveryWorkerStatus);
var_dump($recoveryWorkerStatus);
echo implode("\n", $recoveryWorkerOutput), "\n";

foreach ([
    $dispatchLiveScript,
    $dispatchRecoveryScript,
    $cancelScript,
    $workerScript,
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
--EXPECTF--
int(0)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
int(0)
bool(true)
string(9) "cancelled"
bool(true)
string(%d) "king_pipeline_orchestrator_worker_run_next() cancelled the active orchestrator run via the persisted file-worker cancel channel."
bool(false)
bool(true)
int(0)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
int(0)
bool(true)
string(9) "cancelled"
bool(true)
string(%d) "king_pipeline_orchestrator_worker_run_next() cancelled the active orchestrator run via the persisted file-worker cancel channel."
bool(false)
