--TEST--
King pipeline orchestrator persists distributed observability for queue claims recovery and remote execution attempts
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
if (!extension_loaded('pcntl')) {
    echo "skip pcntl extension required for orchestrator tests";
}
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_failover_harness.inc';

function king_orchestrator_observability_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_orchestrator_observability_decode_json(array $result, string $label): array
{
    king_orchestrator_observability_assert(
        $result['status'] === 0,
        $label . ' exited with status ' . json_encode($result['status']) . ' and stderr ' . json_encode($result['stderr'])
    );
    king_orchestrator_observability_assert(
        trim($result['stderr']) === '',
        $label . ' wrote unexpected stderr: ' . json_encode($result['stderr'])
    );

    $decoded = json_decode(trim($result['stdout']), true);
    king_orchestrator_observability_assert(
        is_array($decoded),
        $label . ' did not return valid JSON: ' . json_encode($result['stdout'])
    );

    return $decoded;
}

function king_orchestrator_observability_read_state(
    array $harness,
    string $backend,
    string $observerScript,
    string $runId,
    bool $strict = true
): array {
    $result = king_orchestrator_failover_harness_exec($harness, $backend, $observerScript, [$runId]);

    if ($strict) {
        return king_orchestrator_observability_decode_json($result, 'observer/' . $backend);
    }

    if ($result['status'] !== 0 || trim($result['stderr']) !== '') {
        return ['exists' => false];
    }

    $decoded = json_decode(trim($result['stdout']), true);
    return is_array($decoded) ? $decoded : ['exists' => false];
}

function king_orchestrator_distributed_observability_file_worker_scenario(): void
{
    $harness = king_orchestrator_failover_harness_create();

    try {
        $dispatchScript = king_orchestrator_failover_harness_write_script($harness, 'observability-file-worker-dispatch', <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'observability-file-worker'],
    [['tool' => 'summarizer', 'delay_ms' => 15000]],
    ['trace_id' => 'observability-file-worker-run']
);
echo json_encode($dispatch), "\n";
PHP);
        $observerScript = king_orchestrator_failover_harness_write_script($harness, 'observability-file-worker-observer', <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1]);
if ($run === false) {
    echo json_encode(['exists' => false]), "\n";
    return;
}
$info = king_system_get_component_info('pipeline_orchestrator');
echo json_encode([
    'run' => $run,
    'component' => $info['configuration'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP);
        $workerScript = king_orchestrator_failover_harness_write_script($harness, 'observability-file-worker-worker', <<<'PHP'
<?php
$work = king_pipeline_orchestrator_worker_run_next();
echo json_encode($work, JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP);

        $dispatch = king_orchestrator_observability_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'file_worker', $dispatchScript),
            'dispatch/file_worker'
        );
        king_orchestrator_observability_assert(
            ($dispatch['run_id'] ?? null) === 'run-1',
            'file-worker observability scenario run id drifted'
        );

        $beforeClaim = king_orchestrator_observability_read_state($harness, 'file_worker', $observerScript, 'run-1');
        king_orchestrator_observability_assert(
            ($beforeClaim['run']['execution_backend'] ?? null) === 'file_worker',
            'file-worker observability execution backend drifted before claim'
        );
        king_orchestrator_observability_assert(
            ($beforeClaim['run']['topology_scope'] ?? null) === 'same_host_file_worker',
            'file-worker observability topology scope drifted before claim'
        );
        king_orchestrator_observability_assert(
            ($beforeClaim['run']['retry_policy'] ?? null) === 'single_attempt',
            'file-worker observability retry policy drifted before claim'
        );
        king_orchestrator_observability_assert(
            ($beforeClaim['run']['idempotency_policy'] ?? null) === 'caller_managed',
            'file-worker observability idempotency policy drifted before claim'
        );
        king_orchestrator_observability_assert(
            ($beforeClaim['run']['distributed_observability']['queue_phase'] ?? null) === 'queued',
            'file-worker observability queue phase was not queued before claim'
        );
        king_orchestrator_observability_assert(
            (($beforeClaim['run']['distributed_observability']['enqueued_at'] ?? 0) > 0),
            'file-worker observability missing enqueued_at'
        );
        king_orchestrator_observability_assert(
            ($beforeClaim['run']['distributed_observability']['claim_count'] ?? null) === 0,
            'file-worker observability claim_count was not zero before claim'
        );
        king_orchestrator_observability_assert(
            ($beforeClaim['run']['distributed_observability']['recovery_count'] ?? null) === 0,
            'file-worker observability recovery_count was not zero before claim'
        );
        king_orchestrator_observability_assert(
            ($beforeClaim['run']['distributed_observability']['remote_attempt_count'] ?? null) === 0,
            'file-worker observability remote_attempt_count drifted before claim'
        );
        king_orchestrator_observability_assert(
            ($beforeClaim['run']['step_count'] ?? null) === 1,
            'file-worker observability step_count drifted before claim'
        );
        king_orchestrator_observability_assert(
            ($beforeClaim['run']['completed_step_count'] ?? null) === 0,
            'file-worker observability completed_step_count drifted before claim'
        );
        king_orchestrator_observability_assert(
            ($beforeClaim['run']['steps'][0]['execution_backend'] ?? null) === 'file_worker',
            'file-worker step execution backend drifted before claim'
        );
        king_orchestrator_observability_assert(
            ($beforeClaim['run']['steps'][0]['topology_scope'] ?? null) === 'same_host_file_worker',
            'file-worker step topology scope drifted before claim'
        );
        king_orchestrator_observability_assert(
            ($beforeClaim['component']['distributed_observability']['claimed_run_count'] ?? null) === 0,
            'file-worker component claimed_run_count drifted before claim'
        );

        $worker = king_orchestrator_failover_harness_spawn($harness, 'file_worker', $workerScript);

        $claimedObserved = king_orchestrator_failover_harness_wait_for(
            static function () use ($harness, $observerScript): bool {
                $state = king_orchestrator_observability_read_state(
                    $harness,
                    'file_worker',
                    $observerScript,
                    'run-1',
                    false
                );

                return ($state['run']['distributed_observability']['queue_phase'] ?? null) === 'claimed'
                    && ($state['run']['distributed_observability']['claim_count'] ?? null) === 1
                    && ($state['component']['distributed_observability']['claimed_run_count'] ?? null) === 1;
            }
        );
        king_orchestrator_observability_assert(
            $claimedObserved,
            'file-worker observability scenario never observed a claimed run'
        );

        $workerCrash = king_orchestrator_failover_harness_crash_process($worker);
        king_orchestrator_observability_assert(
            $workerCrash['status'] !== 0,
            'file-worker observability worker exited cleanly after forced crash'
        );

        $afterCrash = king_orchestrator_observability_read_state($harness, 'file_worker', $observerScript, 'run-1');
        king_orchestrator_observability_assert(
            ($afterCrash['run']['status'] ?? null) === 'running',
            'file-worker observability run did not stay running after worker crash'
        );
        king_orchestrator_observability_assert(
            ($afterCrash['run']['distributed_observability']['queue_phase'] ?? null) === 'claimed',
            'file-worker observability queue phase drifted after worker crash'
        );
        king_orchestrator_observability_assert(
            ($afterCrash['run']['distributed_observability']['claim_count'] ?? null) === 1,
            'file-worker observability claim_count drifted after worker crash'
        );
        king_orchestrator_observability_assert(
            (($afterCrash['run']['distributed_observability']['last_claimed_at'] ?? 0) > 0),
            'file-worker observability missing last_claimed_at after worker crash'
        );
        king_orchestrator_observability_assert(
            (($afterCrash['run']['distributed_observability']['last_claimed_by_pid'] ?? 0) > 0),
            'file-worker observability missing last_claimed_by_pid after worker crash'
        );
        king_orchestrator_observability_assert(
            ($afterCrash['run']['distributed_observability']['recovery_count'] ?? null) === 0,
            'file-worker observability recovery_count drifted before recovery claim'
        );
        king_orchestrator_observability_assert(
            ($afterCrash['component']['distributed_observability']['claimed_run_count'] ?? null) === 1,
            'file-worker component claimed_run_count drifted after worker crash'
        );
        king_orchestrator_observability_assert(
            ($afterCrash['component']['distributed_observability']['last_claimed_run_id'] ?? null) === 'run-1',
            'file-worker component last_claimed_run_id drifted after worker crash'
        );

        $recovered = king_orchestrator_observability_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'file_worker', $workerScript),
            'worker/file_worker-recovery'
        );
        king_orchestrator_observability_assert(
            ($recovered['status'] ?? null) === 'completed',
            'file-worker observability recovery worker did not complete the run'
        );
        king_orchestrator_observability_assert(
            ($recovered['distributed_observability']['queue_phase'] ?? null) === 'dequeued',
            'file-worker observability queue phase did not become dequeued after recovery'
        );
        king_orchestrator_observability_assert(
            ($recovered['distributed_observability']['claim_count'] ?? null) === 2,
            'file-worker observability claim_count did not record the recovery claim'
        );
        king_orchestrator_observability_assert(
            ($recovered['distributed_observability']['recovery_count'] ?? null) === 1,
            'file-worker observability recovery_count did not record claimed-job recovery'
        );
        king_orchestrator_observability_assert(
            ($recovered['distributed_observability']['last_recovery_reason'] ?? null) === 'claimed_job_recovery',
            'file-worker observability last_recovery_reason drifted after recovery'
        );
        king_orchestrator_observability_assert(
            (($recovered['distributed_observability']['last_recovered_at'] ?? 0) > 0),
            'file-worker observability missing last_recovered_at after recovery'
        );

        $afterRecovery = king_orchestrator_observability_read_state($harness, 'file_worker', $observerScript, 'run-1');
        king_orchestrator_observability_assert(
            ($afterRecovery['component']['distributed_observability']['claimed_run_count'] ?? null) === 0,
            'file-worker component claimed_run_count did not clear after recovery'
        );
        king_orchestrator_observability_assert(
            ($afterRecovery['component']['distributed_observability']['recovered_run_count'] ?? null) === 1,
            'file-worker component recovered_run_count drifted after recovery'
        );
        king_orchestrator_observability_assert(
            ($afterRecovery['component']['distributed_observability']['last_recovered_run_id'] ?? null) === 'run-1',
            'file-worker component last_recovered_run_id drifted after recovery'
        );
        king_orchestrator_observability_assert(
            ($afterRecovery['component']['distributed_observability']['last_recovery_reason'] ?? null) === 'claimed_job_recovery',
            'file-worker component last_recovery_reason drifted after recovery'
        );
    } finally {
        king_orchestrator_failover_harness_destroy($harness);
    }
}

function king_orchestrator_distributed_observability_remote_peer_scenario(): void
{
    $harness = king_orchestrator_failover_harness_create();

    try {
        king_orchestrator_failover_harness_remote_peer_start($harness);

        $controllerScript = king_orchestrator_failover_harness_write_script($harness, 'observability-remote-controller', <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_run(
    ['text' => 'observability-remote'],
    [['tool' => 'summarizer', 'delay_ms' => 15000]],
    ['trace_id' => 'observability-remote-run']
);
PHP);
        $observerScript = king_orchestrator_failover_harness_write_script($harness, 'observability-remote-observer', <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1]);
if ($run === false) {
    echo json_encode(['exists' => false]), "\n";
    return;
}
$info = king_system_get_component_info('pipeline_orchestrator');
echo json_encode([
    'run' => $run,
    'component' => $info['configuration'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP);
        $resumeScript = king_orchestrator_failover_harness_write_script($harness, 'observability-remote-resume', <<<'PHP'
<?php
$runId = $argv[1];
$infoBefore = king_system_get_component_info('pipeline_orchestrator');
$result = king_pipeline_orchestrator_resume_run($runId);
$run = king_pipeline_orchestrator_get_run($runId);
$infoAfter = king_system_get_component_info('pipeline_orchestrator');
echo json_encode([
    'result' => $result,
    'run' => $run,
    'before' => $infoBefore['configuration'] ?? null,
    'after' => $infoAfter['configuration'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP);

        $controller = king_orchestrator_failover_harness_spawn($harness, 'remote_peer', $controllerScript);

        $runningObserved = king_orchestrator_failover_harness_wait_for(
            static function () use ($harness, $observerScript): bool {
                $state = king_orchestrator_observability_read_state(
                    $harness,
                    'remote_peer',
                    $observerScript,
                    'run-1',
                    false
                );

                return ($state['run']['status'] ?? null) === 'running'
                    && ($state['run']['execution_backend'] ?? null) === 'remote_peer'
                    && ($state['run']['distributed_observability']['remote_attempt_count'] ?? null) === 1
                    && ($state['component']['distributed_observability']['remote_attempted_run_count'] ?? null) === 1;
            }
        );
        king_orchestrator_observability_assert(
            $runningObserved,
            'remote observability scenario never observed the initial remote attempt'
        );

        $running = king_orchestrator_observability_read_state($harness, 'remote_peer', $observerScript, 'run-1');
        king_orchestrator_observability_assert(
            ($running['run']['topology_scope'] ?? null) === 'tcp_host_port_execution_peer',
            'remote observability topology scope drifted before controller crash'
        );
        king_orchestrator_observability_assert(
            ($running['run']['distributed_observability']['queue_phase'] ?? null) === 'not_queued',
            'remote observability queue phase drifted before controller crash'
        );
        king_orchestrator_observability_assert(
            ($running['run']['steps'][0]['execution_backend'] ?? null) === 'remote_peer',
            'remote step execution backend drifted before controller crash'
        );
        king_orchestrator_observability_assert(
            ($running['run']['steps'][0]['topology_scope'] ?? null) === 'tcp_host_port_execution_peer',
            'remote step topology scope drifted before controller crash'
        );
        king_orchestrator_observability_assert(
            (($running['run']['distributed_observability']['last_remote_attempt_at'] ?? 0) > 0),
            'remote observability missing last_remote_attempt_at before controller crash'
        );

        $controllerCrash = king_orchestrator_failover_harness_crash_process($controller);
        king_orchestrator_observability_assert(
            $controllerCrash['status'] !== 0,
            'remote observability controller exited cleanly after forced crash'
        );
        king_orchestrator_failover_harness_remote_peer_crash($harness);
        king_orchestrator_failover_harness_remote_peer_start($harness);

        $afterRestart = king_orchestrator_observability_read_state($harness, 'remote_peer', $observerScript, 'run-1');
        king_orchestrator_observability_assert(
            ($afterRestart['run']['status'] ?? null) === 'running',
            'remote observability run did not stay running after controller and peer loss'
        );
        king_orchestrator_observability_assert(
            ($afterRestart['run']['distributed_observability']['remote_attempt_count'] ?? null) === 1,
            'remote observability remote_attempt_count drifted before resume'
        );
        king_orchestrator_observability_assert(
            ($afterRestart['run']['distributed_observability']['recovery_count'] ?? null) === 0,
            'remote observability recovery_count drifted before resume'
        );

        $resumed = king_orchestrator_observability_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'remote_peer', $resumeScript, ['run-1']),
            'resume/remote_peer'
        );
        king_orchestrator_observability_assert(
            ($resumed['result']['text'] ?? null) === 'observability-remote',
            'remote observability resume result drifted'
        );
        king_orchestrator_observability_assert(
            ($resumed['run']['status'] ?? null) === 'completed',
            'remote observability resume did not complete the run'
        );
        king_orchestrator_observability_assert(
            ($resumed['run']['distributed_observability']['remote_attempt_count'] ?? null) === 2,
            'remote observability remote_attempt_count did not record the resumed remote execution'
        );
        king_orchestrator_observability_assert(
            ($resumed['run']['distributed_observability']['recovery_count'] ?? null) === 1,
            'remote observability recovery_count did not record resume_run'
        );
        king_orchestrator_observability_assert(
            ($resumed['run']['distributed_observability']['last_recovery_reason'] ?? null) === 'resume_run',
            'remote observability last_recovery_reason drifted after resume'
        );
        king_orchestrator_observability_assert(
            (($resumed['run']['distributed_observability']['last_recovered_at'] ?? 0) > 0),
            'remote observability missing last_recovered_at after resume'
        );
        king_orchestrator_observability_assert(
            (($resumed['run']['distributed_observability']['last_remote_attempt_at'] ?? 0) > 0),
            'remote observability missing last_remote_attempt_at after resume'
        );
        king_orchestrator_observability_assert(
            ($resumed['run']['steps'][0]['execution_backend'] ?? null) === 'remote_peer',
            'remote step execution backend drifted after resume'
        );
        king_orchestrator_observability_assert(
            ($resumed['after']['distributed_observability']['remote_attempted_run_count'] ?? null) === 1,
            'remote component remote_attempted_run_count drifted after resume'
        );
        king_orchestrator_observability_assert(
            ($resumed['after']['distributed_observability']['last_remote_attempt_run_id'] ?? null) === 'run-1',
            'remote component last_remote_attempt_run_id drifted after resume'
        );
        king_orchestrator_observability_assert(
            ($resumed['after']['distributed_observability']['recovered_run_count'] ?? null) === 1,
            'remote component recovered_run_count drifted after resume'
        );
        king_orchestrator_observability_assert(
            ($resumed['after']['distributed_observability']['last_recovered_run_id'] ?? null) === 'run-1',
            'remote component last_recovered_run_id drifted after resume'
        );
        king_orchestrator_observability_assert(
            ($resumed['after']['distributed_observability']['last_recovery_reason'] ?? null) === 'resume_run',
            'remote component last_recovery_reason drifted after resume'
        );
    } finally {
        king_orchestrator_failover_harness_destroy($harness);
    }
}

king_orchestrator_distributed_observability_file_worker_scenario();
king_orchestrator_distributed_observability_remote_peer_scenario();
echo "OK\n";
?>
--EXPECT--
OK
