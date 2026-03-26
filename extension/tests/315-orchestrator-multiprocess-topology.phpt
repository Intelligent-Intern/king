--TEST--
King pipeline orchestrator multiprocess topology harness verifies dispatch cancel and stale-claim recovery across controller observer and worker processes
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-topology-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-topology-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerLiveScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-topology-live-');
$controllerRecoveryScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-topology-recovery-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-topology-observer-');
$cancelScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-topology-cancel-');
$cancelWorkerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-topology-cancel-worker-');
$completeWorkerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-topology-complete-worker-');

@unlink($statePath);
@mkdir($queuePath, 0777, true);

file_put_contents($controllerLiveScript, <<<'PHP'
<?php
var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'topology-cancel'],
    [['tool' => 'summarizer', 'delay_ms' => 500]],
    ['trace_id' => 'topology-live']
);
echo $dispatch['run_id'], "\n";
PHP);

file_put_contents($controllerRecoveryScript, <<<'PHP'
<?php
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'topology-complete'],
    [['tool' => 'summarizer']],
    ['trace_id' => 'topology-recovery']
);
echo $dispatch['run_id'], "\n";
PHP);

file_put_contents($observerScript, <<<'PHP'
<?php
$runId = $argv[1];
$run = king_pipeline_orchestrator_get_run($runId);
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['recovered_from_state']);
var_dump($info['configuration']['execution_backend']);
var_dump($info['configuration']['queued_run_count']);
var_dump($info['configuration']['run_history_count']);
var_dump($info['configuration']['last_run_status']);
var_dump($info['configuration']['registered_tools']);
var_dump($run['status']);
var_dump($run['cancel_requested']);
var_dump($run['error']);
PHP);

file_put_contents($cancelScript, <<<'PHP'
<?php
$runId = $argv[1];
var_dump(king_pipeline_orchestrator_cancel_run($runId));
$run = king_pipeline_orchestrator_get_run($runId);
var_dump($run['cancel_requested']);
var_dump($run['status']);
PHP);

file_put_contents($cancelWorkerScript, <<<'PHP'
<?php
$work = king_pipeline_orchestrator_worker_run_next();
var_dump(preg_match('/^run-\d+$/', $work['run_id']) === 1);
var_dump($work['status']);
var_dump($work['cancel_requested']);
var_dump($work['error']);
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['queued_run_count']);
PHP);

file_put_contents($completeWorkerScript, <<<'PHP'
<?php
$work = king_pipeline_orchestrator_worker_run_next();
var_dump(preg_match('/^run-\d+$/', $work['run_id']) === 1);
var_dump($work['status']);
var_dump($work['cancel_requested']);
var_dump($work['result']['text']);
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['queued_run_count']);
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

$controllerLiveCommand = sprintf($baseCommand, escapeshellarg($controllerLiveScript));
$controllerRecoveryCommand = sprintf($baseCommand, escapeshellarg($controllerRecoveryScript));
$cancelWorkerCommand = sprintf($baseCommand, escapeshellarg($cancelWorkerScript));
$completeWorkerCommand = sprintf($baseCommand, escapeshellarg($completeWorkerScript));

exec($controllerLiveCommand, $controllerLiveOutput, $controllerLiveStatus);
$liveRunId = trim($controllerLiveOutput[count($controllerLiveOutput) - 1] ?? '');
var_dump($controllerLiveStatus);
var_dump(trim($controllerLiveOutput[0] ?? '') === 'bool(true)');
var_dump(preg_match('/^run-\d+$/', $liveRunId) === 1);

$observerBeforeCommand = sprintf(
    $baseCommand . ' %s',
    escapeshellarg($observerScript),
    escapeshellarg($liveRunId)
);
exec($observerBeforeCommand, $observerBeforeOutput, $observerBeforeStatus);
var_dump($observerBeforeStatus);
echo implode("\n", $observerBeforeOutput), "\n";

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$cancelWorkerProcess = proc_open($cancelWorkerCommand, $descriptors, $cancelWorkerPipes);
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

$cancelCommand = sprintf(
    $baseCommand . ' %s',
    escapeshellarg($cancelScript),
    escapeshellarg($liveRunId)
);
exec($cancelCommand, $cancelOutput, $cancelStatus);
var_dump($cancelStatus);
echo implode("\n", $cancelOutput), "\n";

$cancelWorkerStdout = stream_get_contents($cancelWorkerPipes[1]);
$cancelWorkerStderr = stream_get_contents($cancelWorkerPipes[2]);
fclose($cancelWorkerPipes[1]);
fclose($cancelWorkerPipes[2]);
$cancelWorkerStatus = proc_close($cancelWorkerProcess);
var_dump($cancelWorkerStatus);
echo trim($cancelWorkerStdout), "\n";
var_dump(trim($cancelWorkerStderr) === '');

$observerAfterCancelCommand = sprintf(
    $baseCommand . ' %s',
    escapeshellarg($observerScript),
    escapeshellarg($liveRunId)
);
exec($observerAfterCancelCommand, $observerAfterCancelOutput, $observerAfterCancelStatus);
var_dump($observerAfterCancelStatus);
echo implode("\n", $observerAfterCancelOutput), "\n";

exec($controllerRecoveryCommand, $controllerRecoveryOutput, $controllerRecoveryStatus);
$recoveryRunId = trim($controllerRecoveryOutput[0] ?? '');
var_dump($controllerRecoveryStatus);
var_dump(preg_match('/^run-\d+$/', $recoveryRunId) === 1);
var_dump(rename(
    $queuePath . '/queued-' . $recoveryRunId . '.job',
    $queuePath . '/claimed-4242-queued-' . $recoveryRunId . '.job'
));

exec($completeWorkerCommand, $completeWorkerOutput, $completeWorkerStatus);
var_dump($completeWorkerStatus);
echo implode("\n", $completeWorkerOutput), "\n";

$observerAfterCompleteCommand = sprintf(
    $baseCommand . ' %s',
    escapeshellarg($observerScript),
    escapeshellarg($recoveryRunId)
);
exec($observerAfterCompleteCommand, $observerAfterCompleteOutput, $observerAfterCompleteStatus);
var_dump($observerAfterCompleteStatus);
echo implode("\n", $observerAfterCompleteOutput), "\n";

foreach ([
    $controllerLiveScript,
    $controllerRecoveryScript,
    $observerScript,
    $cancelScript,
    $cancelWorkerScript,
    $completeWorkerScript,
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
string(11) "file_worker"
int(1)
int(1)
string(7) "running"
array(1) {
  [0]=>
  string(10) "summarizer"
}
string(7) "running"
bool(false)
NULL
bool(true)
int(0)
bool(true)
bool(true)
string(7) "running"
int(0)
bool(true)
string(9) "cancelled"
bool(true)
string(%d) "king_pipeline_orchestrator_worker_run_next() cancelled the active orchestrator run via the persisted file-worker cancel channel."
int(0)
bool(true)
int(0)
bool(true)
string(11) "file_worker"
int(0)
int(1)
string(9) "cancelled"
array(1) {
  [0]=>
  string(10) "summarizer"
}
string(9) "cancelled"
bool(true)
string(%d) "king_pipeline_orchestrator_worker_run_next() cancelled the active orchestrator run via the persisted file-worker cancel channel."
int(0)
bool(true)
bool(true)
int(0)
bool(true)
string(9) "completed"
bool(false)
string(17) "topology-complete"
int(0)
bool(false)
int(0)
bool(true)
string(11) "file_worker"
int(0)
int(2)
string(9) "completed"
array(1) {
  [0]=>
  string(10) "summarizer"
}
string(9) "completed"
bool(false)
NULL
