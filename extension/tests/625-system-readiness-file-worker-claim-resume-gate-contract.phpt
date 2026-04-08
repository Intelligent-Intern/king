--TEST--
King coordinated runtime gates file-worker claim and claimed-job recovery while the system is not ready
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('proc_get_status')) {
    echo "skip proc_open and proc_get_status are required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-readiness-worker-state-');
$queuePath = sys_get_temp_dir() . '/king-readiness-worker-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-readiness-worker-controller-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-readiness-worker-observer-');
$gatedWorkerScript = tempnam(sys_get_temp_dir(), 'king-readiness-worker-gated-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-readiness-worker-runner-');
$crashWorkerScript = tempnam(sys_get_temp_dir(), 'king-readiness-worker-crash-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($controllerScript, <<<'PHP'
<?php
$text = $argv[1] ?? 'default';
$delayMs = (int) ($argv[2] ?? 0);
$traceId = $argv[3] ?? 'trace';

king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);

$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => $text],
    [['tool' => 'summarizer', 'delay_ms' => $delayMs]],
    ['trace_id' => $traceId]
);
echo $dispatch['run_id'], "\n";
PHP);

file_put_contents($observerScript, <<<'PHP'
<?php
$runId = $argv[1] ?? '';
$run = king_pipeline_orchestrator_get_run($runId);
echo json_encode([
    'status' => $run['status'] ?? null,
    'result_text' => $run['result']['text'] ?? null,
    'error' => $run['error'] ?? null,
    'queue_phase' => $run['distributed_observability']['queue_phase'] ?? null,
    'claim_count' => $run['distributed_observability']['claim_count'] ?? null,
    'recovery_count' => $run['distributed_observability']['recovery_count'] ?? null,
]), "\n";
PHP);

file_put_contents($gatedWorkerScript, <<<'PHP'
<?php
$payload = [
    'init' => king_system_init(['component_timeout_seconds' => 1]),
    'restart' => king_system_restart_component('telemetry'),
];
$status = king_system_get_status();
$payload['lifecycle'] = $status['lifecycle'] ?? null;
$payload['file_worker_claims'] = $status['admission']['file_worker_claims'] ?? null;
$payload['file_worker_resumes'] = $status['admission']['file_worker_resumes'] ?? null;

try {
    king_pipeline_orchestrator_worker_run_next();
    $payload['exception_class'] = null;
    $payload['exception_message'] = null;
} catch (Throwable $e) {
    $payload['exception_class'] = get_class($e);
    $payload['exception_message'] = $e->getMessage();
}

echo json_encode($payload), "\n";
PHP);

file_put_contents($workerScript, <<<'PHP'
<?php
$work = king_pipeline_orchestrator_worker_run_next();
if ($work === false) {
    echo "false\n";
    return;
}

echo json_encode([
    'run_id' => $work['run_id'] ?? null,
    'status' => $work['status'] ?? null,
    'text' => $work['result']['text'] ?? null,
    'queue_phase' => $work['distributed_observability']['queue_phase'] ?? null,
    'claim_count' => $work['distributed_observability']['claim_count'] ?? null,
    'recovery_count' => $work['distributed_observability']['recovery_count'] ?? null,
]), "\n";
PHP);

file_put_contents($crashWorkerScript, <<<'PHP'
<?php
king_pipeline_orchestrator_worker_run_next();
PHP);

$phpPrefix = sprintf(
    '%s -n -d %s -d %s -d %s -d %s -d %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_execution_backend=file_worker'),
    escapeshellarg('king.orchestrator_worker_queue_path=' . $queuePath),
    escapeshellarg('king.orchestrator_state_path=' . $statePath)
);

$controllerCommand = static fn(string $text, int $delayMs, string $traceId): string => $phpPrefix . ' '
    . escapeshellarg($controllerScript) . ' '
    . escapeshellarg($text) . ' '
    . $delayMs . ' '
    . escapeshellarg($traceId);
$observerCommand = static fn(string $runId): string => $phpPrefix . ' '
    . escapeshellarg($observerScript) . ' '
    . escapeshellarg($runId);
$gatedWorkerCommand = $phpPrefix . ' ' . escapeshellarg($gatedWorkerScript);
$workerCommand = $phpPrefix . ' ' . escapeshellarg($workerScript);
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

exec($controllerCommand('blocked-claim', 0, 'readiness-claim'), $claimDispatchOutput, $claimDispatchStatus);
$claimRunId = trim($claimDispatchOutput[0] ?? '');
var_dump($claimDispatchStatus);
var_dump(preg_match('/^run-\d+$/', $claimRunId) === 1);

exec($gatedWorkerCommand, $claimGateOutput, $claimGateStatus);
$claimGate = json_decode(trim($claimGateOutput[0] ?? ''), true);
var_dump($claimGateStatus);
var_dump(($claimGate['init'] ?? null) === true);
var_dump(($claimGate['restart'] ?? null) === true);
var_dump(($claimGate['lifecycle'] ?? null) === 'draining');
var_dump(($claimGate['file_worker_claims'] ?? null) === false);
var_dump(($claimGate['file_worker_resumes'] ?? null) === false);
var_dump(($claimGate['exception_class'] ?? null) === 'King\\RuntimeException');
var_dump(str_contains((string) ($claimGate['exception_message'] ?? ''), 'cannot admit file_worker_claims'));
var_dump(str_contains((string) ($claimGate['exception_message'] ?? ''), "lifecycle is 'draining'"));

exec($observerCommand($claimRunId), $claimSnapshotOutput, $claimSnapshotStatus);
$claimSnapshot = json_decode(trim($claimSnapshotOutput[0] ?? ''), true);
var_dump($claimSnapshotStatus);
var_dump(($claimSnapshot['status'] ?? null) === 'queued');
var_dump(($claimSnapshot['queue_phase'] ?? null) === 'queued');
var_dump(($claimSnapshot['claim_count'] ?? null) === 0);
var_dump(($claimSnapshot['recovery_count'] ?? null) === 0);
var_dump(count(glob($queuePath . '/queued-*.job')) === 1);
var_dump(count(glob($queuePath . '/claimed-*.job')) === 0);

exec($workerCommand, $claimRecoveryOutput, $claimRecoveryStatus);
$claimRecovered = json_decode(trim($claimRecoveryOutput[0] ?? ''), true);
var_dump($claimRecoveryStatus);
var_dump(($claimRecovered['run_id'] ?? null) === $claimRunId);
var_dump(($claimRecovered['status'] ?? null) === 'completed');
var_dump(($claimRecovered['text'] ?? null) === 'blocked-claim');
var_dump(($claimRecovered['queue_phase'] ?? null) === 'dequeued');
var_dump(($claimRecovered['claim_count'] ?? null) === 1);
var_dump(($claimRecovered['recovery_count'] ?? null) === 0);
var_dump(count(glob($queuePath . '/queued-*.job')) === 0);
var_dump(count(glob($queuePath . '/claimed-*.job')) === 0);

exec($controllerCommand('blocked-resume', 5000, 'readiness-resume'), $resumeDispatchOutput, $resumeDispatchStatus);
$resumeRunId = trim($resumeDispatchOutput[0] ?? '');
var_dump($resumeDispatchStatus);
var_dump(preg_match('/^run-\d+$/', $resumeRunId) === 1);

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$crashWorker = proc_open($crashWorkerArgv, $descriptors, $crashWorkerPipes);
$claimedObserved = false;
for ($i = 0; $i < 400; $i++) {
    $claimedFiles = glob($queuePath . '/claimed-*.job');
    if (is_array($claimedFiles) && count($claimedFiles) === 1) {
        $claimedObserved = true;
        break;
    }
    usleep(10000);
}
var_dump($claimedObserved);
usleep(100000);

$crashWorkerStatus = proc_get_status($crashWorker);
$crashWorkerPid = (int) ($crashWorkerStatus['pid'] ?? 0);
$killStatus = -1;
exec('/bin/kill -9 ' . $crashWorkerPid, $killOutput, $killStatus);
var_dump($killStatus);

$crashStdout = stream_get_contents($crashWorkerPipes[1]);
$crashStderr = stream_get_contents($crashWorkerPipes[2]);
fclose($crashWorkerPipes[1]);
fclose($crashWorkerPipes[2]);
$crashExit = proc_close($crashWorker);
var_dump($crashExit !== 0);
var_dump(trim($crashStdout) === '');
var_dump(trim($crashStderr) === '');

exec($observerCommand($resumeRunId), $resumeSnapshotOutput, $resumeSnapshotStatus);
$resumeSnapshot = json_decode(trim($resumeSnapshotOutput[0] ?? ''), true);
var_dump($resumeSnapshotStatus);
var_dump(($resumeSnapshot['status'] ?? null) === 'running');
var_dump(($resumeSnapshot['queue_phase'] ?? null) === 'claimed');
var_dump(($resumeSnapshot['claim_count'] ?? null) === 1);
var_dump(($resumeSnapshot['recovery_count'] ?? null) === 0);
var_dump(count(glob($queuePath . '/queued-*.job')) === 0);
var_dump(count(glob($queuePath . '/claimed-*.job')) === 1);

exec($gatedWorkerCommand, $resumeGateOutput, $resumeGateStatus);
$resumeGate = json_decode(trim($resumeGateOutput[0] ?? ''), true);
var_dump($resumeGateStatus);
var_dump(($resumeGate['init'] ?? null) === true);
var_dump(($resumeGate['restart'] ?? null) === true);
var_dump(($resumeGate['lifecycle'] ?? null) === 'draining');
var_dump(($resumeGate['file_worker_claims'] ?? null) === false);
var_dump(($resumeGate['file_worker_resumes'] ?? null) === false);
var_dump(($resumeGate['exception_class'] ?? null) === 'King\\RuntimeException');
var_dump(str_contains((string) ($resumeGate['exception_message'] ?? ''), 'cannot admit file_worker_resumes'));
var_dump(str_contains((string) ($resumeGate['exception_message'] ?? ''), "lifecycle is 'draining'"));

exec($observerCommand($resumeRunId), $resumeBlockedOutput, $resumeBlockedStatus);
$resumeBlocked = json_decode(trim($resumeBlockedOutput[0] ?? ''), true);
var_dump($resumeBlockedStatus);
var_dump(($resumeBlocked['status'] ?? null) === 'running');
var_dump(($resumeBlocked['queue_phase'] ?? null) === 'claimed');
var_dump(($resumeBlocked['claim_count'] ?? null) === 1);
var_dump(($resumeBlocked['recovery_count'] ?? null) === 0);
var_dump(count(glob($queuePath . '/queued-*.job')) === 0);
var_dump(count(glob($queuePath . '/claimed-*.job')) === 1);

exec($workerCommand, $resumeRecoveryOutput, $resumeRecoveryStatus);
$resumeRecovered = json_decode(trim($resumeRecoveryOutput[0] ?? ''), true);
var_dump($resumeRecoveryStatus);
var_dump(($resumeRecovered['run_id'] ?? null) === $resumeRunId);
var_dump(($resumeRecovered['status'] ?? null) === 'completed');
var_dump(($resumeRecovered['text'] ?? null) === 'blocked-resume');
var_dump(($resumeRecovered['queue_phase'] ?? null) === 'dequeued');
var_dump(($resumeRecovered['claim_count'] ?? null) === 2);
var_dump(($resumeRecovered['recovery_count'] ?? null) === 1);
var_dump(count(glob($queuePath . '/queued-*.job')) === 0);
var_dump(count(glob($queuePath . '/claimed-*.job')) === 0);

foreach ([
    $controllerScript,
    $observerScript,
    $gatedWorkerScript,
    $workerScript,
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
