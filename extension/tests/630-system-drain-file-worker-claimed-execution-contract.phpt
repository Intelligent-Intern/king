--TEST--
King drain blocks new file-worker claims while preserving already claimed file-worker execution
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
function king_system_drain_file_worker_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$statePath = tempnam(sys_get_temp_dir(), 'king-drain-file-worker-state-');
$queuePath = sys_get_temp_dir() . '/king-drain-file-worker-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-drain-file-worker-controller-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-drain-file-worker-worker-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($controllerScript, <<<'PHP'
<?php
function drain_prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected prepare input');
    }

    $input['history'][] = 'prepare';
    return ['output' => $input];
}

function drain_finalize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected finalize input');
    }

    $input['history'][] = 'finalize';
    return ['output' => $input];
}

function drain_queued_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected queued input');
    }

    $input['history'][] = 'queued';
    return ['output' => $input];
}

foreach (['drain-prepare', 'drain-finalize', 'drain-queued'] as $tool) {
    king_pipeline_orchestrator_register_tool($tool, [
        'model' => 'gpt-sim',
        'max_tokens' => 64,
    ]);
}
king_pipeline_orchestrator_register_handler('drain-prepare', 'drain_prepare_handler');
king_pipeline_orchestrator_register_handler('drain-finalize', 'drain_finalize_handler');
king_pipeline_orchestrator_register_handler('drain-queued', 'drain_queued_handler');

$admitted = king_pipeline_orchestrator_dispatch(
    ['text' => 'admitted-run', 'mode' => 'admitted', 'history' => []],
    [
        ['tool' => 'drain-prepare'],
        ['tool' => 'drain-finalize'],
    ],
    ['trace_id' => 'system-drain-file-worker-admitted']
);

$queued = king_pipeline_orchestrator_dispatch(
    ['text' => 'queued-run', 'mode' => 'queued', 'history' => []],
    [
        ['tool' => 'drain-queued'],
    ],
    ['trace_id' => 'system-drain-file-worker-queued']
);

echo json_encode([
    'admitted_run_id' => $admitted['run_id'] ?? null,
    'admitted_status' => $admitted['status'] ?? null,
    'queued_run_id' => $queued['run_id'] ?? null,
    'queued_status' => $queued['status'] ?? null,
]), "\n";
PHP);

file_put_contents($workerScript, <<<'PHP'
<?php
function drain_wait_until_ready(int $maxAttempts = 80): void
{
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $status = king_system_get_status();
        if (($status['lifecycle'] ?? null) === 'ready') {
            return;
        }

        usleep(100000);
    }

    throw new RuntimeException('worker runtime did not become ready before first claim');
}

function drain_wait_until_stopped(int $maxAttempts = 80): void
{
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $status = king_system_get_status();
        if (($status['initialized'] ?? true) === false) {
            return;
        }

        usleep(100000);
    }

    throw new RuntimeException('worker runtime did not stop after shutdown request');
}

function drain_prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected prepare input');
    }

    $statusBefore = king_system_get_status();
    $input['history'][] = 'prepare';
    $input['stage1_before_lifecycle'] = $statusBefore['lifecycle'] ?? null;
    $input['stage1_before_claims'] = $statusBefore['admission']['file_worker_claims'] ?? null;
    $input['stage1_backend'] = $context['run']['execution_backend'] ?? null;

    if (($input['mode'] ?? null) === 'admitted') {
        $input['restart_ok'] = king_system_restart_component('telemetry');
        $input['restart_error'] = king_get_last_error();

        $statusAfterRestart = king_system_get_status();
        $input['stage1_after_lifecycle'] = $statusAfterRestart['lifecycle'] ?? null;
        $input['stage1_after_claims'] = $statusAfterRestart['admission']['file_worker_claims'] ?? null;
        $input['stage1_after_resumes'] = $statusAfterRestart['admission']['file_worker_resumes'] ?? null;

        try {
            $nested = king_pipeline_orchestrator_worker_run_next();
            $input['nested_claim_unexpected_success'] = true;
            $input['nested_claim_result'] = $nested;
        } catch (Throwable $e) {
            $input['nested_claim_unexpected_success'] = false;
            $input['nested_claim_class'] = get_class($e);
            $input['nested_claim_message'] = $e->getMessage();
            $input['nested_claim_last_error'] = king_get_last_error();
        }

        usleep(250000);
        $statusBeforeReturn = king_system_get_status();
        $input['stage1_before_return_lifecycle'] = $statusBeforeReturn['lifecycle'] ?? null;
        $input['stage1_before_return_claims'] = $statusBeforeReturn['admission']['file_worker_claims'] ?? null;
    }

    return ['output' => $input];
}

function drain_finalize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected finalize input');
    }

    $status = king_system_get_status();
    $input['history'][] = 'finalize';
    $input['stage2_lifecycle'] = $status['lifecycle'] ?? null;
    $input['stage2_claims'] = $status['admission']['file_worker_claims'] ?? null;
    $input['stage2_resumes'] = $status['admission']['file_worker_resumes'] ?? null;
    $input['stage2_backend'] = $context['run']['execution_backend'] ?? null;

    return ['output' => $input];
}

function drain_queued_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected queued input');
    }

    $input['history'][] = 'queued';
    $input['worker_backend'] = $context['run']['execution_backend'] ?? null;

    return ['output' => $input];
}

$admittedRunId = $argv[1] ?? '';
$queuedRunId = $argv[2] ?? '';

$payload = [
    'init' => king_system_init(['component_timeout_seconds' => 1]),
];
drain_wait_until_ready();

foreach (['drain-prepare', 'drain-finalize', 'drain-queued'] as $tool) {
    king_pipeline_orchestrator_register_tool($tool, [
        'model' => 'gpt-sim',
        'max_tokens' => 64,
    ]);
}
king_pipeline_orchestrator_register_handler('drain-prepare', 'drain_prepare_handler');
king_pipeline_orchestrator_register_handler('drain-finalize', 'drain_finalize_handler');
king_pipeline_orchestrator_register_handler('drain-queued', 'drain_queued_handler');

$payload['first_work'] = king_pipeline_orchestrator_worker_run_next();
$payload['status_after_first'] = king_system_get_status();
$payload['queued_snapshot_after_first'] = king_pipeline_orchestrator_get_run($queuedRunId);
$payload['queued_files_after_first'] = count(glob(ini_get('king.orchestrator_worker_queue_path') . '/queued-*.job') ?: []);
$payload['claimed_files_after_first'] = count(glob(ini_get('king.orchestrator_worker_queue_path') . '/claimed-*.job') ?: []);

$recovered = false;
for ($attempt = 0; $attempt < 30; $attempt++) {
    $status = king_system_get_status();
    if (($status['lifecycle'] ?? null) === 'ready') {
        $payload['status_ready_before_second'] = $status;
        $recovered = true;
        break;
    }
    usleep(100000);
}
$payload['recovered_before_second'] = $recovered;

$payload['second_work'] = king_pipeline_orchestrator_worker_run_next();
$payload['admitted_snapshot_final'] = king_pipeline_orchestrator_get_run($admittedRunId);
$payload['queued_snapshot_final'] = king_pipeline_orchestrator_get_run($queuedRunId);
$payload['queued_files_final'] = count(glob(ini_get('king.orchestrator_worker_queue_path') . '/queued-*.job') ?: []);
$payload['claimed_files_final'] = count(glob(ini_get('king.orchestrator_worker_queue_path') . '/claimed-*.job') ?: []);
$payload['empty_after_second'] = king_pipeline_orchestrator_worker_run_next();
$payload['shutdown'] = king_system_shutdown();
drain_wait_until_stopped();
$payload['initialized_after_shutdown'] = king_system_get_status()['initialized'];

echo json_encode($payload), "\n";
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

$controllerCommand = $phpPrefix . ' ' . escapeshellarg($controllerScript);
exec($controllerCommand, $controllerOutput, $controllerStatus);
$controller = json_decode(trim($controllerOutput[0] ?? ''), true);

king_system_drain_file_worker_assert($controllerStatus === 0, 'controller failed to dispatch runs');
king_system_drain_file_worker_assert(is_array($controller), 'controller returned malformed JSON');
$admittedRunId = $controller['admitted_run_id'] ?? '';
$queuedRunId = $controller['queued_run_id'] ?? '';
king_system_drain_file_worker_assert(
    preg_match('/^run-\d+$/', $admittedRunId) === 1,
    'controller did not return a valid admitted run id'
);
king_system_drain_file_worker_assert(
    preg_match('/^run-\d+$/', $queuedRunId) === 1,
    'controller did not return a valid queued run id'
);
king_system_drain_file_worker_assert(
    ($controller['admitted_status'] ?? null) === 'queued',
    'admitted run was not queued initially'
);
king_system_drain_file_worker_assert(
    ($controller['queued_status'] ?? null) === 'queued',
    'queued run was not queued initially'
);

$workerCommand = $phpPrefix . ' ' . escapeshellarg($workerScript)
    . ' ' . escapeshellarg($admittedRunId)
    . ' ' . escapeshellarg($queuedRunId);
exec($workerCommand, $workerOutput, $workerStatus);
$worker = json_decode(trim($workerOutput[0] ?? ''), true);

king_system_drain_file_worker_assert($workerStatus === 0, 'worker failed to execute the drain scenario');
king_system_drain_file_worker_assert(is_array($worker), 'worker returned malformed JSON');
king_system_drain_file_worker_assert(($worker['init'] ?? null) === true, 'worker failed to init coordinated runtime');

$first = $worker['first_work'] ?? null;
king_system_drain_file_worker_assert(is_array($first), 'first claimed work snapshot missing');
king_system_drain_file_worker_assert(($first['run_id'] ?? null) === $admittedRunId, 'worker claimed the wrong admitted run');
king_system_drain_file_worker_assert(($first['status'] ?? null) === 'completed', 'admitted run did not complete');
king_system_drain_file_worker_assert(($first['execution_backend'] ?? null) === 'file_worker', 'admitted run lost file_worker backend');
king_system_drain_file_worker_assert(
    ($first['result']['history'] ?? null) === ['prepare', 'finalize'],
    'admitted run lost its claimed step history'
);
king_system_drain_file_worker_assert(
    ($first['result']['stage1_before_lifecycle'] ?? null) === 'ready',
    'admitted run was not claimed while the system was ready'
);
king_system_drain_file_worker_assert(
    ($first['result']['stage1_before_claims'] ?? null) === true,
    'admitted run did not observe open file-worker claims before drain'
);
king_system_drain_file_worker_assert(
    ($first['result']['stage1_backend'] ?? null) === 'file_worker',
    'admitted run step-1 lost file_worker backend'
);
king_system_drain_file_worker_assert(
    ($first['result']['restart_ok'] ?? null) === true,
    'admitted run failed to request the drain restart'
);
king_system_drain_file_worker_assert(
    ($first['result']['restart_error'] ?? '') === '',
    'admitted run reported an unexpected restart error'
);
king_system_drain_file_worker_assert(
    ($first['result']['stage1_after_lifecycle'] ?? null) === 'draining',
    'admitted run did not observe draining immediately after restart'
);
king_system_drain_file_worker_assert(
    ($first['result']['stage1_after_claims'] ?? null) === false,
    'admitted run left file-worker claims open after drain'
);
king_system_drain_file_worker_assert(
    ($first['result']['stage1_after_resumes'] ?? null) === false,
    'admitted run left file-worker resumes open after drain'
);
king_system_drain_file_worker_assert(
    ($first['result']['nested_claim_unexpected_success'] ?? true) === false,
    'nested worker claim unexpectedly succeeded during drain'
);
king_system_drain_file_worker_assert(
    ($first['result']['nested_claim_class'] ?? null) === 'King\\RuntimeException',
    'nested worker claim returned the wrong exception class'
);
king_system_drain_file_worker_assert(
    str_contains((string) ($first['result']['nested_claim_message'] ?? ''), 'cannot admit file_worker_claims'),
    'nested worker claim did not report file_worker_claims gating'
);
king_system_drain_file_worker_assert(
    str_contains((string) ($first['result']['nested_claim_message'] ?? ''), "lifecycle is 'draining'"),
    'nested worker claim did not report the draining lifecycle'
);
king_system_drain_file_worker_assert(
    str_contains((string) ($first['result']['nested_claim_last_error'] ?? ''), 'cannot admit file_worker_claims'),
    'nested worker claim did not leave the expected last error'
);
king_system_drain_file_worker_assert(
    in_array(
        $first['result']['stage1_before_return_lifecycle'] ?? null,
        ['draining', 'starting'],
        true
    ),
    'admitted run left the non-ready window before step-1 returned'
);
king_system_drain_file_worker_assert(
    ($first['result']['stage1_before_return_claims'] ?? null) === false,
    'admitted run saw claims reopen before step-1 returned'
);
king_system_drain_file_worker_assert(
    in_array(
        $first['result']['stage2_lifecycle'] ?? null,
        ['draining', 'starting'],
        true
    ),
    'admitted run step-2 did not continue through the non-ready window'
);
king_system_drain_file_worker_assert(
    ($first['result']['stage2_claims'] ?? null) === false,
    'admitted run step-2 saw file-worker claims reopen too early'
);
king_system_drain_file_worker_assert(
    ($first['result']['stage2_resumes'] ?? null) === false,
    'admitted run step-2 saw file-worker resumes reopen too early'
);
king_system_drain_file_worker_assert(
    ($first['result']['stage2_backend'] ?? null) === 'file_worker',
    'admitted run step-2 lost file_worker backend'
);

$statusAfterFirst = $worker['status_after_first'] ?? null;
king_system_drain_file_worker_assert(is_array($statusAfterFirst), 'missing post-first-run system status');
king_system_drain_file_worker_assert(
    in_array($statusAfterFirst['lifecycle'] ?? null, ['draining', 'starting'], true),
    'system was not in the non-ready drain/restart window after the admitted run'
);
king_system_drain_file_worker_assert(
    ($statusAfterFirst['admission']['file_worker_claims'] ?? null) === false,
    'system reopened file-worker claims too early after the admitted run'
);

$queuedAfterFirst = $worker['queued_snapshot_after_first'] ?? null;
king_system_drain_file_worker_assert(is_array($queuedAfterFirst), 'missing queued snapshot after blocked claim');
king_system_drain_file_worker_assert(
    ($queuedAfterFirst['status'] ?? null) === 'queued',
    'queued run did not stay queued after the blocked nested claim'
);
king_system_drain_file_worker_assert(
    ($queuedAfterFirst['distributed_observability']['queue_phase'] ?? null) === 'queued',
    'queued run lost its queued phase after the blocked nested claim'
);
king_system_drain_file_worker_assert(
    ($queuedAfterFirst['distributed_observability']['claim_count'] ?? null) === 0,
    'queued run claim count drifted after the blocked nested claim'
);
king_system_drain_file_worker_assert(
    ($queuedAfterFirst['distributed_observability']['recovery_count'] ?? null) === 0,
    'queued run recovery count drifted after the blocked nested claim'
);
king_system_drain_file_worker_assert(
    ($worker['queued_files_after_first'] ?? null) === 1,
    'blocked nested claim did not restore the queued job file'
);
king_system_drain_file_worker_assert(
    ($worker['claimed_files_after_first'] ?? null) === 0,
    'blocked nested claim left behind a claimed job file'
);

king_system_drain_file_worker_assert(
    ($worker['recovered_before_second'] ?? null) === true,
    'worker did not reach ready before reclaiming the queued run'
);
$readyBeforeSecond = $worker['status_ready_before_second'] ?? null;
king_system_drain_file_worker_assert(is_array($readyBeforeSecond), 'missing ready status before second claim');
king_system_drain_file_worker_assert(
    ($readyBeforeSecond['lifecycle'] ?? null) === 'ready',
    'worker was not ready before reclaiming the queued run'
);
king_system_drain_file_worker_assert(
    ($readyBeforeSecond['admission']['file_worker_claims'] ?? null) === true,
    'worker did not reopen file-worker claims before reclaiming the queued run'
);

$second = $worker['second_work'] ?? null;
king_system_drain_file_worker_assert(is_array($second), 'second claimed work snapshot missing');
king_system_drain_file_worker_assert(($second['run_id'] ?? null) === $queuedRunId, 'worker reclaimed the wrong queued run');
king_system_drain_file_worker_assert(($second['status'] ?? null) === 'completed', 'queued run did not complete after recovery');
king_system_drain_file_worker_assert(($second['execution_backend'] ?? null) === 'file_worker', 'queued run lost file_worker backend');
king_system_drain_file_worker_assert(
    ($second['result']['history'] ?? null) === ['queued'],
    'queued run lost its execution history after recovery'
);
king_system_drain_file_worker_assert(
    ($second['result']['worker_backend'] ?? null) === 'file_worker',
    'queued run handler lost file_worker backend after recovery'
);

$admittedFinal = $worker['admitted_snapshot_final'] ?? null;
king_system_drain_file_worker_assert(is_array($admittedFinal), 'missing final admitted snapshot');
king_system_drain_file_worker_assert(
    ($admittedFinal['status'] ?? null) === 'completed',
    'final admitted snapshot did not stay completed'
);
king_system_drain_file_worker_assert(
    ($admittedFinal['distributed_observability']['queue_phase'] ?? null) === 'dequeued',
    'final admitted snapshot lost dequeued phase'
);
king_system_drain_file_worker_assert(
    ($admittedFinal['distributed_observability']['claim_count'] ?? null) === 1,
    'final admitted snapshot claim count drifted'
);
king_system_drain_file_worker_assert(
    ($admittedFinal['distributed_observability']['recovery_count'] ?? null) === 0,
    'final admitted snapshot recovery count drifted'
);

$queuedFinal = $worker['queued_snapshot_final'] ?? null;
king_system_drain_file_worker_assert(is_array($queuedFinal), 'missing final queued snapshot');
king_system_drain_file_worker_assert(
    ($queuedFinal['status'] ?? null) === 'completed',
    'final queued snapshot did not complete after recovery'
);
king_system_drain_file_worker_assert(
    ($queuedFinal['distributed_observability']['queue_phase'] ?? null) === 'dequeued',
    'final queued snapshot lost dequeued phase'
);
king_system_drain_file_worker_assert(
    ($queuedFinal['distributed_observability']['claim_count'] ?? null) === 1,
    'final queued snapshot claim count drifted'
);
king_system_drain_file_worker_assert(
    ($queuedFinal['distributed_observability']['recovery_count'] ?? null) === 0,
    'final queued snapshot recovery count drifted'
);
king_system_drain_file_worker_assert(
    ($worker['queued_files_final'] ?? null) === 0,
    'queue still had queued job files after both runs completed'
);
king_system_drain_file_worker_assert(
    ($worker['claimed_files_final'] ?? null) === 0,
    'queue still had claimed job files after both runs completed'
);
king_system_drain_file_worker_assert(
    ($worker['empty_after_second'] ?? true) === false,
    'worker unexpectedly found more work after both runs completed'
);
king_system_drain_file_worker_assert(
    ($worker['shutdown'] ?? null) === true,
    'worker failed to shut down the coordinated runtime'
);
king_system_drain_file_worker_assert(
    ($worker['initialized_after_shutdown'] ?? true) === false,
    'worker stayed initialized after shutdown'
);

foreach ([
    $controllerScript,
    $workerScript,
] as $path) {
    @unlink($path);
}
@unlink($statePath);
if (is_dir($queuePath)) {
    foreach (scandir($queuePath) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            @unlink($queuePath . '/' . $entry);
        }
    }
    @rmdir($queuePath);
}

echo "OK\n";
?>
--EXPECT--
OK
