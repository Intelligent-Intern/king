--TEST--
King userland-backed orchestrator runs classify validation, runtime, timeout, cancelled, backend, and missing-handler failures explicitly
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/orchestrator_remote_peer_helper.inc';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function last_run_snapshot(): array
{
    $info = king_system_get_component_info('pipeline_orchestrator');
    $runId = $info['configuration']['last_run_id'] ?? null;
    $snapshot = king_pipeline_orchestrator_get_run($runId);

    if (!is_array($snapshot)) {
        throw new RuntimeException('failed to load the last orchestrator run snapshot');
    }

    return $snapshot;
}

function ok_handler(array $context): array
{
    return ['output' => $context['input'] ?? []];
}

function validation_fail_handler(array $context): array
{
    throw new King\ValidationException('local validation failure');
}

function runtime_fail_handler(array $context): array
{
    throw new RuntimeException('local runtime failure');
}

function timeout_fail_handler(array $context): array
{
    throw new King\TimeoutException('local timeout failure');
}

foreach (['ok', 'validator', 'runtime', 'timeout', 'missing'] as $toolName) {
    assert_true(
        king_pipeline_orchestrator_register_tool($toolName, [
            'model' => 'gpt-sim',
            'max_tokens' => 64,
        ]),
        "failed to register local tool {$toolName}"
    );
}

assert_true(
    king_pipeline_orchestrator_register_handler('ok', 'ok_handler'),
    'failed to register ok handler'
);
assert_true(
    king_pipeline_orchestrator_register_handler('validator', 'validation_fail_handler'),
    'failed to register validation handler'
);
assert_true(
    king_pipeline_orchestrator_register_handler('runtime', 'runtime_fail_handler'),
    'failed to register runtime handler'
);
assert_true(
    king_pipeline_orchestrator_register_handler('timeout', 'timeout_fail_handler'),
    'failed to register timeout handler'
);

try {
    king_pipeline_orchestrator_run(['text' => 'local-validation'], [['tool' => 'validator']]);
    throw new RuntimeException('local validation case did not throw');
} catch (Throwable $e) {
    assert_true($e instanceof King\ValidationException, 'local validation case lost the ValidationException');
    assert_true($e->getMessage() === 'local validation failure', 'local validation exception message drifted');
}

$validation = last_run_snapshot();
assert_true(($validation['status'] ?? null) === 'failed', 'local validation run did not fail');
assert_true(($validation['error'] ?? null) === 'local validation failure', 'local validation run error drifted');
assert_true(($validation['error_classification']['category'] ?? null) === 'validation', 'local validation category drifted');
assert_true(($validation['error_classification']['retry_disposition'] ?? null) === 'non_retryable', 'local validation retry disposition drifted');
assert_true(($validation['error_classification']['scope'] ?? null) === 'step', 'local validation scope drifted');
assert_true(($validation['error_classification']['backend'] ?? null) === 'local', 'local validation backend drifted');
assert_true(($validation['error_classification']['step_index'] ?? null) === 0, 'local validation step index drifted');
assert_true(($validation['error_classification']['step_tool'] ?? null) === 'validator', 'local validation step tool drifted');
assert_true(($validation['steps'][0]['status'] ?? null) === 'failed', 'local validation step status drifted');
echo "local-validation-ok\n";

try {
    king_pipeline_orchestrator_run(['text' => 'local-runtime'], [['tool' => 'runtime']]);
    throw new RuntimeException('local runtime case did not throw');
} catch (Throwable $e) {
    assert_true(get_class($e) === RuntimeException::class, 'local runtime case lost the RuntimeException');
    assert_true($e->getMessage() === 'local runtime failure', 'local runtime exception message drifted');
}

$runtime = last_run_snapshot();
assert_true(($runtime['status'] ?? null) === 'failed', 'local runtime run did not fail');
assert_true(($runtime['error'] ?? null) === 'local runtime failure', 'local runtime run error drifted');
assert_true(($runtime['error_classification']['category'] ?? null) === 'runtime', 'local runtime category drifted');
assert_true(($runtime['error_classification']['retry_disposition'] ?? null) === 'caller_managed_retry', 'local runtime retry disposition drifted');
assert_true(($runtime['error_classification']['scope'] ?? null) === 'step', 'local runtime scope drifted');
assert_true(($runtime['error_classification']['backend'] ?? null) === 'local', 'local runtime backend drifted');
assert_true(($runtime['error_classification']['step_index'] ?? null) === 0, 'local runtime step index drifted');
assert_true(($runtime['error_classification']['step_tool'] ?? null) === 'runtime', 'local runtime step tool drifted');
assert_true(($runtime['steps'][0]['status'] ?? null) === 'failed', 'local runtime step status drifted');
echo "local-runtime-ok\n";

try {
    king_pipeline_orchestrator_run(['text' => 'local-timeout'], [['tool' => 'timeout']]);
    throw new RuntimeException('local timeout case did not throw');
} catch (Throwable $e) {
    assert_true($e instanceof King\TimeoutException, 'local timeout case lost the TimeoutException');
    assert_true($e->getMessage() === 'local timeout failure', 'local timeout exception message drifted');
}

$timeout = last_run_snapshot();
assert_true(($timeout['status'] ?? null) === 'failed', 'local timeout run did not fail');
assert_true(($timeout['error'] ?? null) === 'local timeout failure', 'local timeout run error drifted');
assert_true(($timeout['error_classification']['category'] ?? null) === 'timeout', 'local timeout category drifted');
assert_true(($timeout['error_classification']['retry_disposition'] ?? null) === 'caller_managed_retry', 'local timeout retry disposition drifted');
assert_true(($timeout['error_classification']['scope'] ?? null) === 'step', 'local timeout scope drifted');
assert_true(($timeout['error_classification']['backend'] ?? null) === 'local', 'local timeout backend drifted');
assert_true(($timeout['error_classification']['step_index'] ?? null) === 0, 'local timeout step index drifted');
assert_true(($timeout['error_classification']['step_tool'] ?? null) === 'timeout', 'local timeout step tool drifted');
assert_true(($timeout['steps'][0]['status'] ?? null) === 'failed', 'local timeout step status drifted');
echo "local-timeout-ok\n";

try {
    king_pipeline_orchestrator_run(['text' => 'local-missing-handler'], [['tool' => 'missing']]);
    throw new RuntimeException('local missing-handler case did not throw');
} catch (Throwable $e) {
    assert_true($e instanceof King\RuntimeException, 'local missing-handler case lost the RuntimeException');
    assert_true(
        $e->getMessage() === "king_pipeline_orchestrator_run() has no registered handler for tool 'missing'.",
        'local missing-handler exception message drifted'
    );
}

$missing = last_run_snapshot();
assert_true(($missing['status'] ?? null) === 'failed', 'local missing-handler run did not fail');
assert_true(
    ($missing['error'] ?? null) === "king_pipeline_orchestrator_run() has no registered handler for tool 'missing'.",
    'local missing-handler run error drifted'
);
assert_true(($missing['error_classification']['category'] ?? null) === 'missing_handler', 'local missing-handler category drifted');
assert_true(($missing['error_classification']['retry_disposition'] ?? null) === 'caller_managed_retry', 'local missing-handler retry disposition drifted');
assert_true(($missing['error_classification']['scope'] ?? null) === 'step', 'local missing-handler scope drifted');
assert_true(($missing['error_classification']['backend'] ?? null) === 'local', 'local missing-handler backend drifted');
assert_true(($missing['error_classification']['step_index'] ?? null) === 0, 'local missing-handler step index drifted');
assert_true(($missing['error_classification']['step_tool'] ?? null) === 'missing', 'local missing-handler step tool drifted');
assert_true(($missing['steps'][0]['status'] ?? null) === 'failed', 'local missing-handler step status drifted');
echo "local-missing-handler-ok\n";

$extensionPath = dirname(__DIR__) . '/modules/king.so';
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-userland-classification-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-userland-classification-queue-' . getmypid();
$dispatchScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-userland-classification-dispatch-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-userland-classification-worker-');
$cancelDispatchScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-userland-classification-cancel-dispatch-');
$cancelWorkerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-userland-classification-cancel-worker-');
$cancelScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-userland-classification-cancel-request-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-userland-classification-observer-');
$remoteRunnerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-userland-classification-remote-runner-');
$remoteSummarizerBootstrap = tempnam(sys_get_temp_dir(), 'king-orchestrator-userland-classification-remote-summarizer-');
$remotePrepareOnlyBootstrap = tempnam(sys_get_temp_dir(), 'king-orchestrator-userland-classification-remote-prepare-only-');
$remoteBackendStatePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-userland-classification-remote-backend-state-');
$remoteMissingStatePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-userland-classification-remote-missing-state-');

@unlink($statePath);
@unlink($remoteBackendStatePath);
@unlink($remoteMissingStatePath);
@mkdir($queuePath, 0700, true);

file_put_contents($dispatchScript, <<<'PHP'
<?php
function dispatch_ok_handler(array $context): array
{
    return ['output' => $context['input'] ?? []];
}

king_pipeline_orchestrator_register_tool('explode', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('explode', 'dispatch_ok_handler');
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'file-worker-runtime'],
    [['tool' => 'explode']],
    ['trace_id' => 'file-worker-runtime']
);
echo $dispatch['run_id'], "\n";
PHP);

file_put_contents($workerScript, <<<'PHP'
<?php
function explode_handler(array $context): array
{
    throw new RuntimeException('file-worker runtime failure');
}

king_pipeline_orchestrator_register_handler('explode', 'explode_handler');
try {
    king_pipeline_orchestrator_worker_run_next();
    echo json_encode(['exception_class' => null, 'message' => null]), "\n";
} catch (Throwable $e) {
    echo json_encode([
        'exception_class' => get_class($e),
        'message' => $e->getMessage(),
    ]), "\n";
}
PHP);

file_put_contents($cancelDispatchScript, <<<'PHP'
<?php
function cancel_dispatch_handler(array $context): array
{
    return ['output' => $context['input'] ?? []];
}

king_pipeline_orchestrator_register_tool('sleepy', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('sleepy', 'cancel_dispatch_handler');
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'file-worker-cancelled'],
    [['tool' => 'sleepy', 'delay_ms' => 2000]],
    ['trace_id' => 'file-worker-cancelled']
);
echo $dispatch['run_id'], "\n";
PHP);

file_put_contents($cancelWorkerScript, <<<'PHP'
<?php
function sleepy_handler(array $context): array
{
    return ['output' => $context['input'] ?? []];
}

king_pipeline_orchestrator_register_handler('sleepy', 'sleepy_handler');
try {
    king_pipeline_orchestrator_worker_run_next();
    echo json_encode(['exception_class' => null, 'message' => null]), "\n";
} catch (Throwable $e) {
    echo json_encode([
        'exception_class' => get_class($e),
        'message' => $e->getMessage(),
    ]), "\n";
}
PHP);

file_put_contents($cancelScript, <<<'PHP'
<?php
echo json_encode(['cancelled' => king_pipeline_orchestrator_cancel_run($argv[1] ?? '')]), "\n";
PHP);

file_put_contents($observerScript, <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1] ?? '');
echo json_encode([
    'status' => $run['status'] ?? null,
    'finished_at' => $run['finished_at'] ?? null,
    'queue_phase' => $run['distributed_observability']['queue_phase'] ?? null,
    'error' => $run['error'] ?? null,
    'error_classification' => $run['error_classification'] ?? null,
    'steps' => $run['steps'] ?? [],
]), "\n";
PHP);

file_put_contents($remoteRunnerScript, <<<'PHP'
<?php
function controller_passthrough_handler(array $context): array
{
    return ['output' => $context['input'] ?? []];
}

$toolNames = unserialize(base64_decode($argv[1]), ['allowed_classes' => false]);
$pipeline = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
$traceId = $argv[3] ?? 'remote-userland-classification';

foreach ($toolNames as $toolName) {
    king_pipeline_orchestrator_register_tool($toolName, [
        'label' => $toolName,
        'max_tokens' => 64,
    ]);
    king_pipeline_orchestrator_register_handler($toolName, 'controller_passthrough_handler');
}

try {
    king_pipeline_orchestrator_run(
        ['text' => 'remote-userland-classification', 'history' => []],
        $pipeline,
        ['trace_id' => $traceId]
    );
    echo json_encode(['exception_class' => null, 'message' => null]), "\n";
} catch (Throwable $e) {
    echo json_encode([
        'exception_class' => get_class($e),
        'message' => $e->getMessage(),
    ]), "\n";
}

$run = king_pipeline_orchestrator_get_run(
    king_system_get_component_info('pipeline_orchestrator')['configuration']['last_run_id']
);
echo json_encode([
    'status' => $run['status'] ?? null,
    'error' => $run['error'] ?? null,
    'error_classification' => $run['error_classification'] ?? null,
    'step_statuses' => array_map(
        static fn(array $step): mixed => $step['status'] ?? null,
        $run['steps'] ?? []
    ),
]), "\n";
PHP);

file_put_contents($remoteSummarizerBootstrap, <<<'PHP'
<?php
return [
    'summarizer' => static fn(array $context): array => ['output' => $context['input'] ?? []],
];
PHP);

file_put_contents($remotePrepareOnlyBootstrap, <<<'PHP'
<?php
return [
    'prepare' => static fn(array $context): array => ['output' => $context['input'] ?? []],
];
PHP);

$baseFileWorkerCommand = sprintf(
    '%s -n -d %s -d %s -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_execution_backend=file_worker'),
    escapeshellarg('king.orchestrator_worker_queue_path=' . $queuePath),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    '%s'
);

$dispatchCommand = sprintf($baseFileWorkerCommand, escapeshellarg($dispatchScript));
exec($dispatchCommand, $dispatchOutput, $dispatchStatus);
$fileWorkerRunId = trim($dispatchOutput[0] ?? '');
assert_true($dispatchStatus === 0, 'file-worker dispatch process failed');
assert_true(preg_match('/^run-\d+$/', $fileWorkerRunId) === 1, 'file-worker dispatch run id drifted');

$workerCommand = sprintf($baseFileWorkerCommand, escapeshellarg($workerScript));
exec($workerCommand, $workerOutput, $workerStatus);
$workerFailure = json_decode(trim($workerOutput[0] ?? ''), true);
assert_true($workerStatus === 0, 'file-worker worker process failed');
assert_true(($workerFailure['exception_class'] ?? null) === RuntimeException::class, 'file-worker runtime exception class drifted');
assert_true(($workerFailure['message'] ?? null) === 'file-worker runtime failure', 'file-worker runtime exception message drifted');

$observerCommand = static fn(string $runId): string => sprintf(
    $baseFileWorkerCommand . ' %s',
    escapeshellarg($observerScript),
    escapeshellarg($runId)
);
exec($observerCommand($fileWorkerRunId), $observerOutput, $observerStatus);
$fileWorkerSnapshot = json_decode(trim($observerOutput[0] ?? ''), true);
assert_true($observerStatus === 0, 'file-worker observer process failed');
assert_true(($fileWorkerSnapshot['status'] ?? null) === 'failed', 'file-worker runtime snapshot status drifted');
assert_true(($fileWorkerSnapshot['error'] ?? null) === 'file-worker runtime failure', 'file-worker runtime snapshot error drifted');
assert_true(($fileWorkerSnapshot['error_classification']['category'] ?? null) === 'runtime', 'file-worker runtime category drifted');
assert_true(($fileWorkerSnapshot['error_classification']['retry_disposition'] ?? null) === 'caller_managed_retry', 'file-worker runtime retry disposition drifted');
assert_true(($fileWorkerSnapshot['error_classification']['scope'] ?? null) === 'step', 'file-worker runtime scope drifted');
assert_true(($fileWorkerSnapshot['error_classification']['backend'] ?? null) === 'file_worker', 'file-worker runtime backend drifted');
assert_true(($fileWorkerSnapshot['error_classification']['step_index'] ?? null) === 0, 'file-worker runtime step index drifted');
assert_true(($fileWorkerSnapshot['error_classification']['step_tool'] ?? null) === 'explode', 'file-worker runtime step tool drifted');
assert_true(($fileWorkerSnapshot['steps'][0]['status'] ?? null) === 'failed', 'file-worker runtime step status drifted');
echo "file-worker-runtime-ok\n";

$cancelDispatchCommand = sprintf($baseFileWorkerCommand, escapeshellarg($cancelDispatchScript));
exec($cancelDispatchCommand, $cancelDispatchOutput, $cancelDispatchStatus);
$cancelRunId = trim($cancelDispatchOutput[0] ?? '');
assert_true($cancelDispatchStatus === 0, 'file-worker cancel dispatch process failed');
assert_true(preg_match('/^run-\d+$/', $cancelRunId) === 1, 'file-worker cancel dispatch run id drifted');

$cancelWorkerArgv = [
    PHP_BINARY,
    '-n',
    '-d', 'extension=' . $extensionPath,
    '-d', 'king.security_allow_config_override=1',
    '-d', 'king.orchestrator_execution_backend=file_worker',
    '-d', 'king.orchestrator_worker_queue_path=' . $queuePath,
    '-d', 'king.orchestrator_state_path=' . $statePath,
    $cancelWorkerScript,
];
$cancelWorkerProcess = proc_open($cancelWorkerArgv, [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $cancelWorkerPipes);
assert_true(is_resource($cancelWorkerProcess), 'failed to start the file-worker cancellation worker');

$runningObserved = false;
for ($i = 0; $i < 400; $i++) {
    $cancelObserverOutput = [];
    $cancelObserverStatus = -1;
    exec($observerCommand($cancelRunId), $cancelObserverOutput, $cancelObserverStatus);
    $cancelRunningSnapshot = json_decode(trim($cancelObserverOutput[0] ?? ''), true);

    if (
        $cancelObserverStatus === 0
        && is_array($cancelRunningSnapshot)
        && ($cancelRunningSnapshot['status'] ?? null) === 'running'
        && ($cancelRunningSnapshot['queue_phase'] ?? null) === 'claimed'
        && ($cancelRunningSnapshot['finished_at'] ?? null) === 0
    ) {
        $runningObserved = true;
        break;
    }
    usleep(10000);
}
assert_true($runningObserved, 'file-worker cancellation run never reached claimed running state');

$cancelRequestCommand = sprintf(
    $baseFileWorkerCommand . ' %s',
    escapeshellarg($cancelScript),
    escapeshellarg($cancelRunId)
);
exec($cancelRequestCommand, $cancelRequestOutput, $cancelRequestStatus);
$cancelResponse = json_decode(trim($cancelRequestOutput[0] ?? ''), true);
assert_true($cancelRequestStatus === 0, 'file-worker cancel request process failed');
assert_true(($cancelResponse['cancelled'] ?? null) === true, 'file-worker cancel request was not accepted');

$cancelWorkerStdout = stream_get_contents($cancelWorkerPipes[1]);
$cancelWorkerStderr = stream_get_contents($cancelWorkerPipes[2]);
fclose($cancelWorkerPipes[1]);
fclose($cancelWorkerPipes[2]);
$cancelWorkerExit = proc_close($cancelWorkerProcess);
$cancelWorkerResult = json_decode(trim($cancelWorkerStdout), true);

assert_true($cancelWorkerExit === 0, 'file-worker cancellation worker exited unexpectedly');
assert_true(trim($cancelWorkerStderr) === '', 'file-worker cancellation worker wrote to stderr');
assert_true(($cancelWorkerResult['exception_class'] ?? null) === null, 'file-worker cancellation worker should return without throwing');
assert_true(($cancelWorkerResult['message'] ?? null) === null, 'file-worker cancellation worker returned an unexpected message');

$cancelSnapshotOutput = [];
$cancelSnapshotStatus = -1;
exec($observerCommand($cancelRunId), $cancelSnapshotOutput, $cancelSnapshotStatus);
$cancelSnapshot = json_decode(trim($cancelSnapshotOutput[0] ?? ''), true);

assert_true($cancelSnapshotStatus === 0, 'file-worker cancellation observer process failed');
assert_true(($cancelSnapshot['status'] ?? null) === 'cancelled', 'file-worker cancellation status drifted');
assert_true(
    ($cancelSnapshot['error'] ?? null) === 'king_pipeline_orchestrator_worker_run_next() cancelled the active orchestrator run via the persisted file-worker cancel channel.',
    'file-worker cancellation error drifted'
);
assert_true(($cancelSnapshot['error_classification']['category'] ?? null) === 'cancelled', 'file-worker cancellation category drifted');
assert_true(($cancelSnapshot['error_classification']['retry_disposition'] ?? null) === 'not_applicable', 'file-worker cancellation retry disposition drifted');
assert_true(($cancelSnapshot['error_classification']['scope'] ?? null) === 'run', 'file-worker cancellation scope drifted');
assert_true(($cancelSnapshot['error_classification']['backend'] ?? null) === 'file_worker', 'file-worker cancellation backend drifted');
assert_true(($cancelSnapshot['error_classification']['step_index'] ?? null) === null, 'file-worker cancellation step index drifted');
assert_true(($cancelSnapshot['error_classification']['step_tool'] ?? null) === null, 'file-worker cancellation step tool drifted');
assert_true(($cancelSnapshot['steps'][0]['status'] ?? null) === 'pending', 'file-worker cancellation step status drifted');
echo "file-worker-cancelled-run-scope-ok\n";

$buildRemoteCommand = static function (array $server, string $statePath, array $toolNames, array $pipeline, string $traceId) use ($extensionPath, $remoteRunnerScript): string {
    return sprintf(
        '%s -n -d %s -d %s -d %s -d %s -d %s -d %s %s %s %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg('king.orchestrator_execution_backend=remote_peer'),
        escapeshellarg('king.orchestrator_remote_host=' . $server['host']),
        escapeshellarg('king.orchestrator_remote_port=' . $server['port']),
        escapeshellarg('king.orchestrator_state_path=' . $statePath),
        escapeshellarg($remoteRunnerScript),
        escapeshellarg(base64_encode(serialize($toolNames))),
        escapeshellarg(base64_encode(serialize($pipeline))),
        escapeshellarg($traceId)
    );
};

$remoteBackendServer = king_orchestrator_remote_peer_start(null, '127.0.0.1', null, [$remoteSummarizerBootstrap]);
$remoteBackendCommand = $buildRemoteCommand(
    $remoteBackendServer,
    $remoteBackendStatePath,
    ['summarizer'],
    [['tool' => 'summarizer', 'remote_error' => 'forced userland remote backend failure']],
    'remote-userland-backend'
);
exec($remoteBackendCommand, $remoteBackendOutput, $remoteBackendStatus);
$remoteBackendFailure = json_decode(trim($remoteBackendOutput[0] ?? ''), true);
$remoteBackendSnapshot = json_decode(trim($remoteBackendOutput[1] ?? ''), true);
$remoteBackendCapture = king_orchestrator_remote_peer_stop($remoteBackendServer);

assert_true($remoteBackendStatus === 0, 'remote backend runner process failed');
assert_true(($remoteBackendFailure['exception_class'] ?? null) === 'King\\RuntimeException', 'remote backend exception class drifted');
assert_true(($remoteBackendFailure['message'] ?? null) === 'forced userland remote backend failure', 'remote backend exception message drifted');
assert_true(($remoteBackendSnapshot['status'] ?? null) === 'failed', 'remote backend snapshot status drifted');
assert_true(($remoteBackendSnapshot['error'] ?? null) === 'forced userland remote backend failure', 'remote backend snapshot error drifted');
assert_true(($remoteBackendSnapshot['error_classification']['category'] ?? null) === 'backend', 'remote backend category drifted');
assert_true(($remoteBackendSnapshot['error_classification']['retry_disposition'] ?? null) === 'caller_managed_retry', 'remote backend retry disposition drifted');
assert_true(($remoteBackendSnapshot['error_classification']['scope'] ?? null) === 'step', 'remote backend scope drifted');
assert_true(($remoteBackendSnapshot['error_classification']['backend'] ?? null) === 'remote_peer', 'remote backend classification backend drifted');
assert_true(($remoteBackendSnapshot['error_classification']['step_index'] ?? null) === 0, 'remote backend step index drifted');
assert_true(($remoteBackendSnapshot['error_classification']['step_tool'] ?? null) === 'summarizer', 'remote backend step tool drifted');
assert_true(($remoteBackendSnapshot['step_statuses'][0] ?? null) === 'failed', 'remote backend step status drifted');
assert_true(($remoteBackendCapture['events'][0]['failed_step_index'] ?? null) === 0, 'remote backend capture step index drifted');
echo "remote-backend-ok\n";

$remoteMissingServer = king_orchestrator_remote_peer_start(null, '127.0.0.1', null, [$remotePrepareOnlyBootstrap]);
$remoteMissingCommand = $buildRemoteCommand(
    $remoteMissingServer,
    $remoteMissingStatePath,
    ['prepare', 'finalize'],
    [
        ['tool' => 'prepare'],
        ['tool' => 'finalize'],
    ],
    'remote-userland-missing-handler'
);
exec($remoteMissingCommand, $remoteMissingOutput, $remoteMissingStatus);
$remoteMissingFailure = json_decode(trim($remoteMissingOutput[0] ?? ''), true);
$remoteMissingSnapshot = json_decode(trim($remoteMissingOutput[1] ?? ''), true);
$remoteMissingCapture = king_orchestrator_remote_peer_stop($remoteMissingServer);

assert_true($remoteMissingStatus === 0, 'remote missing-handler runner process failed');
assert_true(($remoteMissingFailure['exception_class'] ?? null) === 'King\\RuntimeException', 'remote missing-handler exception class drifted');
assert_true(($remoteMissingFailure['message'] ?? null) === "remote peer has no registered handler for tool 'finalize'.", 'remote missing-handler exception message drifted');
assert_true(($remoteMissingSnapshot['status'] ?? null) === 'failed', 'remote missing-handler snapshot status drifted');
assert_true(($remoteMissingSnapshot['error'] ?? null) === "remote peer has no registered handler for tool 'finalize'.", 'remote missing-handler snapshot error drifted');
assert_true(($remoteMissingSnapshot['error_classification']['category'] ?? null) === 'missing_handler', 'remote missing-handler category drifted');
assert_true(($remoteMissingSnapshot['error_classification']['retry_disposition'] ?? null) === 'caller_managed_retry', 'remote missing-handler retry disposition drifted');
assert_true(($remoteMissingSnapshot['error_classification']['scope'] ?? null) === 'step', 'remote missing-handler scope drifted');
assert_true(($remoteMissingSnapshot['error_classification']['backend'] ?? null) === 'remote_peer', 'remote missing-handler backend drifted');
assert_true(($remoteMissingSnapshot['error_classification']['step_index'] ?? null) === 1, 'remote missing-handler step index drifted');
assert_true(($remoteMissingSnapshot['error_classification']['step_tool'] ?? null) === 'finalize', 'remote missing-handler step tool drifted');
assert_true(($remoteMissingSnapshot['step_statuses'][0] ?? null) === 'completed', 'remote missing-handler first step status drifted');
assert_true(($remoteMissingSnapshot['step_statuses'][1] ?? null) === 'failed', 'remote missing-handler second step status drifted');
assert_true(($remoteMissingCapture['events'][0]['failed_step_index'] ?? null) === 1, 'remote missing-handler capture step index drifted');
echo "remote-missing-handler-ok\n";

foreach ([
    $dispatchScript,
    $workerScript,
    $cancelDispatchScript,
    $cancelWorkerScript,
    $cancelScript,
    $observerScript,
    $remoteRunnerScript,
    $remoteSummarizerBootstrap,
    $remotePrepareOnlyBootstrap,
    $statePath,
    $remoteBackendStatePath,
    $remoteMissingStatePath,
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
local-validation-ok
local-runtime-ok
local-timeout-ok
local-missing-handler-ok
file-worker-runtime-ok
file-worker-cancelled-run-scope-ok
remote-backend-ok
remote-missing-handler-ok
