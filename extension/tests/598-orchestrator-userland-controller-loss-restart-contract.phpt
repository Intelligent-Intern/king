--TEST--
King userland orchestrator run can recover from controller loss in both queued and running modes
--SKIPIF--
<?php
if (!function_exists('proc_open')) {
    echo "skip proc_open is required";
}
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_failover_harness.inc';

function test_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

function king_orchestrator_userland_restart_decode_json(array $result, string $label): array
{
    test_assert(
        $result['status'] === 0,
        $label . ' exited with status ' . json_encode($result['status']) . ' and stderr ' . json_encode($result['stderr'])
    );
    test_assert(
        trim($result['stderr']) === '',
        $label . ' wrote unexpected stderr: ' . json_encode($result['stderr'])
    );

    $decoded = json_decode(trim($result['stdout']), true);
    test_assert(
        is_array($decoded),
        $label . ' did not return valid JSON: ' . json_encode($result['stdout'])
    );

    return $decoded;
}

function read_run_snapshot(
    array $harness,
    string $backend,
    string $observerScript,
    string $runId,
    bool $strict = true
): array {
    $result = king_orchestrator_failover_harness_exec($harness, $backend, $observerScript, [$runId]);

    if ($strict) {
        return king_orchestrator_userland_restart_decode_json($result, 'observer/' . $backend);
    }

    if ($result['status'] !== 0 || trim($result['stderr']) !== '') {
        return [
            'exists' => false,
            '__status' => $result['status'],
            '__stderr' => $result['stderr'],
            '__stdout' => $result['stdout'],
        ];
    }

    $decoded = json_decode(trim($result['stdout']), true);
    if (!is_array($decoded)) {
        return [
            'exists' => false,
            '__status' => $result['status'],
            '__stderr' => $result['stderr'],
            '__stdout' => $result['stdout'],
        ];
    }

    return $decoded;
}

function king_orchestrator_userland_restart_local_running_scenario(): void
{
    $harness = king_orchestrator_failover_harness_create();

    try {
        $controllerScript = king_orchestrator_failover_harness_write_script($harness, 'local-running-controller', <<<'PHP'
<?php
function queued_restart_local_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected local restart input');
    }

    $input['history'][] = 'local-step';
    return ['output' => $input];
}

king_pipeline_orchestrator_register_tool('local-running-step', [
    'model' => 'gpt-sim',
    'max_tokens' => 32,
]);
king_pipeline_orchestrator_register_handler('local-running-step', 'queued_restart_local_handler');
king_pipeline_orchestrator_run(
    ['text' => 'local-running-userland', 'history' => []],
    [['tool' => 'local-running-step', 'delay_ms' => 12000]],
    ['trace_id' => 'userland-running-recovery']
);
PHP);

        $observerScript = king_orchestrator_failover_harness_write_script($harness, 'local-running-observer', <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1] ?? 'run-1');
if ($run === false) {
    echo json_encode(['exists' => false]), "\n";
    return;
}

echo json_encode([
    'run_id' => $run['run_id'] ?? null,
    'status' => $run['status'] ?? null,
    'finished_at' => $run['finished_at'] ?? null,
    'completed_step_count' => $run['completed_step_count'] ?? null,
    'result' => $run['result'] ?? null,
    'error' => $run['error'] ?? null,
]), "\n";
PHP);

        $resumeScript = king_orchestrator_failover_harness_write_script($harness, 'local-running-resume', <<<'PHP'
<?php
function queued_restart_local_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected local resume input');
    }

    $input['history'][] = 'local-step';
    return ['output' => $input];
}

$runId = $argv[1] ?? 'run-1';
king_pipeline_orchestrator_register_tool('local-running-step', [
    'model' => 'gpt-sim',
    'max_tokens' => 32,
]);
king_pipeline_orchestrator_register_handler('local-running-step', 'queued_restart_local_handler');

$infoBefore = king_system_get_component_info('pipeline_orchestrator');
$result = king_pipeline_orchestrator_resume_run($runId);
$run = king_pipeline_orchestrator_get_run($runId);
$infoAfter = king_system_get_component_info('pipeline_orchestrator');

echo json_encode([
    'run_id' => $run['run_id'] ?? null,
    'recovered_from_state' => $infoBefore['configuration']['recovered_from_state'] ?? null,
    'execution_backend' => $infoBefore['configuration']['execution_backend'] ?? null,
    'topology_scope' => $infoBefore['configuration']['topology_scope'] ?? null,
    'result_text' => $result['text'] ?? null,
    'run' => $run,
    'last_run_status' => $infoAfter['configuration']['last_run_status'] ?? null,
]), "\n";
PHP);

        $controller = king_orchestrator_failover_harness_spawn($harness, 'local', $controllerScript);

        $runningObserved = king_orchestrator_failover_harness_wait_for(
            static function () use ($harness, $observerScript): bool {
                $snapshot = read_run_snapshot(
                    $harness,
                    'local',
                    $observerScript,
                    'run-1',
                    false
                );

                return ($snapshot['run_id'] ?? null) === 'run-1'
                    && ($snapshot['status'] ?? null) === 'running'
                    && ($snapshot['finished_at'] ?? null) === 0
                    && ($snapshot['completed_step_count'] ?? null) === 0;
            }
        );
        test_assert($runningObserved, 'local-running scenario never observed a running run');

        $controllerCrash = king_orchestrator_failover_harness_crash_process($controller);
        test_assert(
            $controllerCrash['status'] !== 0,
            'local-running scenario controller exited cleanly after forced crash'
        );
        test_assert(
            trim($controllerCrash['stdout']) === '',
            'local-running scenario controller wrote unexpected stdout'
        );
        test_assert(
            trim($controllerCrash['stderr']) === '',
            'local-running scenario controller wrote unexpected stderr'
        );

        $afterCrash = read_run_snapshot(
            $harness,
            'local',
            $observerScript,
            'run-1'
        );
        test_assert(
            ($afterCrash['status'] ?? null) === 'running',
            'local-running scenario did not preserve running status after controller loss'
        );
        test_assert(
            ($afterCrash['finished_at'] ?? null) === 0,
            'local-running scenario unexpectedly finished after controller loss'
        );
        test_assert(
            ($afterCrash['completed_step_count'] ?? null) === 0,
            'local-running scenario changed progress unexpectedly after controller loss'
        );
        test_assert(
            ($afterCrash['result'] ?? null) === null,
            'local-running scenario unexpectedly wrote partial result before controller recovery'
        );

        $resume = king_orchestrator_userland_restart_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'local', $resumeScript, ['run-1']),
            'resume/local-running'
        );
        test_assert(
            ($resume['recovered_from_state'] ?? null) === true,
            'local-running scenario did not recover state before resume'
        );
        test_assert(
            ($resume['execution_backend'] ?? null) === 'local',
            'local-running scenario execution backend drifted'
        );
        test_assert(
            ($resume['topology_scope'] ?? null) === 'local_in_process',
            'local-running scenario topology drifted'
        );
        test_assert(
            ($resume['result_text'] ?? null) === 'local-running-userland',
            'local-running scenario resumed output drifted'
        );
        test_assert(
            ($resume['run']['status'] ?? null) === 'completed',
            'local-running scenario did not complete after resume'
        );
        test_assert(
            ($resume['run']['completed_step_count'] ?? null) === 1,
            'local-running scenario completion step count drifted'
        );
        test_assert(
            (($resume['run']['handler_readiness']['requires_process_registration'] ?? null) === false)
            || (($resume['run']['handler_readiness']['ready'] ?? null) === true),
            'local-running scenario lost handler readiness after resume'
        );
        test_assert(
            ($resume['run']['error'] ?? null) === null,
            'local-running scenario left stale error after resume'
        );
        test_assert(
            ($resume['last_run_status'] ?? null) === 'completed',
            'local-running scenario component info did not record resumed completion'
        );
    } finally {
        king_orchestrator_failover_harness_destroy($harness);
    }
}

function king_orchestrator_userland_restart_queued_file_worker_scenario(): void
{
    $harness = king_orchestrator_failover_harness_create();

    try {
        $controllerScript = king_orchestrator_failover_harness_write_script($harness, 'queued-controller', <<<'PHP'
<?php
function queued_restart_prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected queued prepare input');
    }

    $input['history'][] = 'queued-prepare';
    return ['output' => $input];
}

function queued_restart_finalize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected queued finalize input');
    }

    $input['history'][] = 'queued-finalize';
    return ['output' => $input];
}

king_pipeline_orchestrator_register_tool('queued-prepare', [
    'model' => 'gpt-sim',
    'max_tokens' => 32,
]);
king_pipeline_orchestrator_register_tool('queued-finalize', [
    'model' => 'gpt-sim',
    'max_tokens' => 16,
]);
king_pipeline_orchestrator_register_handler('queued-prepare', 'queued_restart_prepare_handler');
king_pipeline_orchestrator_register_handler('queued-finalize', 'queued_restart_finalize_handler');

$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'queued-userland-restart', 'history' => []],
    [['tool' => 'queued-prepare'], ['tool' => 'queued-finalize', 'delay_ms' => 8000]],
    ['trace_id' => 'userland-queued-recovery']
);

echo json_encode($dispatch), "\n";
flush();

while (true) {
    sleep(1);
}
PHP);

        $observerScript = king_orchestrator_failover_harness_write_script($harness, 'queued-observer', <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1] ?? 'run-1');
if ($run === false) {
    echo json_encode(['exists' => false]), "\n";
    return;
}

echo json_encode([
    'run_id' => $run['run_id'] ?? null,
    'status' => $run['status'] ?? null,
    'queue_phase' => $run['distributed_observability']['queue_phase'] ?? null,
    'completed_step_count' => $run['completed_step_count'] ?? null,
    'result' => $run['result'] ?? null,
    'error' => $run['error'] ?? null,
    'handler_readiness' => $run['handler_readiness'] ?? null,
    'handler_boundary' => $run['handler_boundary'] ?? null,
]), "\n";
PHP);

        $workerScript = king_orchestrator_failover_harness_write_script($harness, 'queued-worker', <<<'PHP'
<?php
function queued_restart_prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected worker prepare input');
    }

    $input['history'][] = 'queued-prepare';
    return ['output' => $input];
}

function queued_restart_finalize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected worker finalize input');
    }

    $input['history'][] = 'queued-finalize';
    return ['output' => $input];
}

king_pipeline_orchestrator_register_tool('queued-prepare', [
    'model' => 'gpt-sim',
    'max_tokens' => 32,
]);
king_pipeline_orchestrator_register_tool('queued-finalize', [
    'model' => 'gpt-sim',
    'max_tokens' => 16,
]);
king_pipeline_orchestrator_register_handler('queued-prepare', 'queued_restart_prepare_handler');
king_pipeline_orchestrator_register_handler('queued-finalize', 'queued_restart_finalize_handler');

$work = king_pipeline_orchestrator_worker_run_next();
echo json_encode($work), "\n";
PHP);

        $controller = king_orchestrator_failover_harness_spawn($harness, 'file_worker', $controllerScript);

        $dispatched = king_orchestrator_failover_harness_wait_for(
            static function () use ($harness, $observerScript): bool {
                $snapshot = read_run_snapshot(
                    $harness,
                    'file_worker',
                    $observerScript,
                    'run-1',
                    false
                );
                return ($snapshot['run_id'] ?? null) === 'run-1'
                    && ($snapshot['status'] ?? null) === 'queued'
                    && ($snapshot['queue_phase'] ?? null) === 'queued'
                    && is_array($snapshot['handler_boundary']['required_step_refs'] ?? null)
                    && count($snapshot['handler_boundary']['required_step_refs']) === 2;
            }
        );
        test_assert($dispatched, 'queued file-worker scenario never observed the queued run');

        $queuedCount = count(glob($harness['queue_path'] . '/queued-*.job') ?: []);
        test_assert(
            $queuedCount === 1,
            'queued file-worker scenario did not create exactly one queued job file'
        );

        $controllerCrash = king_orchestrator_failover_harness_crash_process($controller);
        test_assert(
            $controllerCrash['status'] !== 0,
            'queued file-worker scenario controller exited cleanly after forced crash'
        );
        test_assert(
            trim($controllerCrash['stderr']) === '',
            'queued file-worker scenario controller wrote unexpected stderr'
        );

        $afterCrash = read_run_snapshot(
            $harness,
            'file_worker',
            $observerScript,
            'run-1'
        );
        test_assert(
            ($afterCrash['status'] ?? null) === 'queued',
            'queued file-worker scenario did not retain queued state after controller crash'
        );
        test_assert(
            ($afterCrash['queue_phase'] ?? null) === 'queued',
            'queued file-worker scenario did not retain queue phase after controller crash'
        );

        $queuedAfterCrash = count(glob($harness['queue_path'] . '/queued-*.job') ?: []);
        test_assert(
            $queuedAfterCrash === 1,
            'queued file-worker scenario lost queued job file after controller crash'
        );

        $work = king_orchestrator_userland_restart_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'file_worker', $workerScript),
            'worker/file_worker/recovery'
        );
        test_assert(($work['run_id'] ?? null) === 'run-1', 'queued file-worker scenario worker reclaimed wrong run id');
        test_assert(($work['status'] ?? null) === 'completed', 'queued file-worker scenario worker did not complete run');
        test_assert(($work['execution_backend'] ?? null) === 'file_worker', 'queued file-worker scenario lost backend');
        test_assert(($work['topology_scope'] ?? null) === 'same_host_file_worker', 'queued file-worker scenario lost topology');
        test_assert(
            (($work['result']['text'] ?? null) === 'queued-userland-restart'),
            'queued file-worker scenario result text drifted'
        );
        test_assert(
            (($work['result']['history'] ?? null) === ['queued-prepare', 'queued-finalize']),
            'queued file-worker scenario lost step history'
        );
        test_assert(($work['error'] ?? null) === null, 'queued file-worker scenario surfaced stale error');

        $final = read_run_snapshot($harness, 'file_worker', $observerScript, 'run-1');
        test_assert(
            ($final['status'] ?? null) === 'completed',
            'queued file-worker scenario final snapshot not completed'
        );
        test_assert(
            ($final['queue_phase'] ?? null) === 'dequeued',
            'queued file-worker scenario final queue phase not dequeued'
        );
        test_assert(
            count(glob($harness['queue_path'] . '/queued-*.job') ?: []) === 0,
            'queued file-worker scenario queue leak after recovery'
        );
    } finally {
        king_orchestrator_failover_harness_destroy($harness);
    }
}

king_orchestrator_userland_restart_local_running_scenario();
king_orchestrator_userland_restart_queued_file_worker_scenario();

echo "OK\n";
?>
--EXPECT--
OK
