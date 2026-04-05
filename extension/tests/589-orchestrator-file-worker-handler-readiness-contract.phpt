--TEST--
King file-worker claims and resumes userland-backed runs only in worker processes with rehydrated handler readiness
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-handler-readiness-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-handler-readiness-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$dispatchScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-handler-readiness-dispatch-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-handler-readiness-observer-');
$unreadyWorkerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-handler-readiness-unready-');
$readyWorkerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-handler-readiness-ready-');
$crashWorkerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-handler-readiness-crash-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($dispatchScript, <<<'PHP'
<?php
function summarizer_handler(array $context): array
{
    return ['output' => $context['input'] ?? []];
}

$text = $argv[1] ?? 'default';
$delayMs = (int) ($argv[2] ?? 0);
$traceId = $argv[3] ?? ('trace-' . $text);

king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('summarizer', 'summarizer_handler');
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => $text],
    [['tool' => 'summarizer', 'delay_ms' => $delayMs]],
    ['trace_id' => $traceId]
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
    'result_text' => $run['result']['text'] ?? null,
    'handler_boundary' => $run['handler_boundary'] ?? null,
    'handler_readiness' => $run['handler_readiness'] ?? null,
]), "\n";
PHP);

file_put_contents($unreadyWorkerScript, <<<'PHP'
<?php
$work = king_pipeline_orchestrator_worker_run_next();
var_dump($work);
PHP);

file_put_contents($readyWorkerScript, <<<'PHP'
<?php
function summarizer_handler(array $context): array
{
    return ['output' => $context['input'] ?? []];
}

king_pipeline_orchestrator_register_handler('summarizer', 'summarizer_handler');
$work = king_pipeline_orchestrator_worker_run_next();
if ($work === false) {
    echo "false\n";
    return;
}

echo json_encode([
    'run_id' => $work['run_id'],
    'status' => $work['status'],
    'queue_phase' => $work['distributed_observability']['queue_phase'] ?? null,
    'result_text' => $work['result']['text'] ?? null,
]), "\n";
PHP);

file_put_contents($crashWorkerScript, <<<'PHP'
<?php
function summarizer_handler(array $context): array
{
    return ['output' => $context['input'] ?? []];
}

king_pipeline_orchestrator_register_handler('summarizer', 'summarizer_handler');
king_pipeline_orchestrator_worker_run_next();
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

$dispatchCommand = static fn(string $text, int $delayMs, string $traceId): string => sprintf(
    $baseCommand . ' %s %s %s',
    escapeshellarg($dispatchScript),
    escapeshellarg($text),
    escapeshellarg((string) $delayMs),
    escapeshellarg($traceId)
);
$observerCommand = static fn(string $runId): string => sprintf(
    $baseCommand . ' %s',
    escapeshellarg($observerScript),
    escapeshellarg($runId)
);
$unreadyWorkerCommand = sprintf($baseCommand, escapeshellarg($unreadyWorkerScript));
$readyWorkerCommand = sprintf($baseCommand, escapeshellarg($readyWorkerScript));

exec($dispatchCommand('queued-needs-handler', 0, 'handler-readiness-queued'), $dispatchOutput1, $dispatchStatus1);
$runId1 = trim($dispatchOutput1[0] ?? '');
var_dump($dispatchStatus1);
var_dump(preg_match('/^run-\d+$/', $runId1) === 1);

$observerOutput1 = [];
$observerStatus1 = -1;
exec($observerCommand($runId1), $observerOutput1, $observerStatus1);
$queuedRun = json_decode(trim($observerOutput1[0] ?? ''), true);
var_dump($observerStatus1);
var_dump(($queuedRun['status'] ?? null) === 'queued');
var_dump(($queuedRun['queue_phase'] ?? null) === 'queued');
var_dump(($queuedRun['handler_boundary']['requires_process_registration'] ?? null) === true);
var_dump(($queuedRun['handler_boundary']['required_tools'] ?? null) === ['summarizer']);
var_dump(($queuedRun['handler_readiness']['requires_process_registration'] ?? null) === true);
var_dump(($queuedRun['handler_readiness']['ready'] ?? null) === false);
var_dump(($queuedRun['handler_readiness']['required_tool_count'] ?? null) === 1);
var_dump(($queuedRun['handler_readiness']['required_step_count'] ?? null) === 1);
var_dump(($queuedRun['handler_readiness']['missing_tool_count'] ?? null) === 1);
var_dump(($queuedRun['handler_readiness']['missing_tools'] ?? null) === ['summarizer']);

$unreadyOutput1 = [];
$unreadyStatus1 = -1;
exec($unreadyWorkerCommand, $unreadyOutput1, $unreadyStatus1);
var_dump($unreadyStatus1);
echo implode("\n", $unreadyOutput1), "\n";

$observerOutput2 = [];
$observerStatus2 = -1;
exec($observerCommand($runId1), $observerOutput2, $observerStatus2);
$stillQueuedRun = json_decode(trim($observerOutput2[0] ?? ''), true);
var_dump($observerStatus2);
var_dump(($stillQueuedRun['status'] ?? null) === 'queued');
var_dump(($stillQueuedRun['queue_phase'] ?? null) === 'queued');
var_dump(count(glob($queuePath . '/queued-' . $runId1 . '.job')) === 1);
var_dump(count(glob($queuePath . '/claimed-*.job')) === 0);

$readyOutput1 = [];
$readyStatus1 = -1;
exec($readyWorkerCommand, $readyOutput1, $readyStatus1);
$completedQueuedRun = json_decode(trim($readyOutput1[0] ?? ''), true);
var_dump($readyStatus1);
var_dump(($completedQueuedRun['run_id'] ?? null) === $runId1);
var_dump(($completedQueuedRun['status'] ?? null) === 'completed');
var_dump(($completedQueuedRun['queue_phase'] ?? null) === 'dequeued');
var_dump(($completedQueuedRun['result_text'] ?? null) === 'queued-needs-handler');

exec($dispatchCommand('claimed-needs-handler', 2000, 'handler-readiness-claimed'), $dispatchOutput2, $dispatchStatus2);
$runId2 = trim($dispatchOutput2[0] ?? '');
var_dump($dispatchStatus2);
var_dump(preg_match('/^run-\d+$/', $runId2) === 1);

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
$crashWorker = proc_open($crashWorkerArgv, $descriptors, $workerPipes);

$claimedObserved = false;
for ($i = 0; $i < 400; $i++) {
    $claimedFiles = glob($queuePath . '/claimed-*.job');
    $observerOutput3 = [];
    $observerStatus3 = -1;
    exec($observerCommand($runId2), $observerOutput3, $observerStatus3);
    $runningRun = json_decode(trim($observerOutput3[0] ?? ''), true);

    if (
        is_array($claimedFiles)
        && count($claimedFiles) === 1
        && $observerStatus3 === 0
        && ($runningRun['status'] ?? null) === 'running'
        && ($runningRun['queue_phase'] ?? null) === 'claimed'
    ) {
        $claimedObserved = true;
        break;
    }
    usleep(10000);
}
var_dump($claimedObserved);

$crashStatusInfo = proc_get_status($crashWorker);
$crashPid = (int) ($crashStatusInfo['pid'] ?? 0);
$killStatus = -1;
exec('/bin/kill -9 ' . $crashPid, $killOutput, $killStatus);
var_dump($killStatus);

$crashStdout = stream_get_contents($workerPipes[1]);
$crashStderr = stream_get_contents($workerPipes[2]);
fclose($workerPipes[1]);
fclose($workerPipes[2]);
$crashExit = proc_close($crashWorker);
var_dump($crashExit !== 0);
var_dump(trim($crashStdout) === '');
var_dump(trim($crashStderr) === '');

$unreadyOutput2 = [];
$unreadyStatus2 = -1;
exec($unreadyWorkerCommand, $unreadyOutput2, $unreadyStatus2);
var_dump($unreadyStatus2);
echo implode("\n", $unreadyOutput2), "\n";

$observerOutput4 = [];
$observerStatus4 = -1;
exec($observerCommand($runId2), $observerOutput4, $observerStatus4);
$stillClaimedRun = json_decode(trim($observerOutput4[0] ?? ''), true);
var_dump($observerStatus4);
var_dump(($stillClaimedRun['status'] ?? null) === 'running');
var_dump(($stillClaimedRun['queue_phase'] ?? null) === 'claimed');
var_dump(($stillClaimedRun['finished_at'] ?? null) === 0);
var_dump(count(glob($queuePath . '/claimed-*.job')) === 1);

$readyOutput2 = [];
$readyStatus2 = -1;
exec($readyWorkerCommand, $readyOutput2, $readyStatus2);
$completedClaimedRun = json_decode(trim($readyOutput2[0] ?? ''), true);
var_dump($readyStatus2);
var_dump(($completedClaimedRun['run_id'] ?? null) === $runId2);
var_dump(($completedClaimedRun['status'] ?? null) === 'completed');
var_dump(($completedClaimedRun['queue_phase'] ?? null) === 'dequeued');
var_dump(($completedClaimedRun['result_text'] ?? null) === 'claimed-needs-handler');

foreach ([
    $dispatchScript,
    $observerScript,
    $unreadyWorkerScript,
    $readyWorkerScript,
    $crashWorkerScript,
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
int(0)
bool(false)
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
int(0)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
int(0)
bool(false)
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
