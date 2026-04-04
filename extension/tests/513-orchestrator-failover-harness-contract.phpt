--TEST--
King pipeline orchestrator failover harness proves controller, worker, and remote-peer recovery through one reusable process harness
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_failover_harness.inc';

function king_orchestrator_failover_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_orchestrator_failover_decode_json(array $result, string $label): array
{
    king_orchestrator_failover_assert(
        $result['status'] === 0,
        $label . ' exited with status ' . json_encode($result['status']) . ' and stderr ' . json_encode($result['stderr'])
    );
    king_orchestrator_failover_assert(
        trim($result['stderr']) === '',
        $label . ' wrote unexpected stderr: ' . json_encode($result['stderr'])
    );

    $decoded = json_decode(trim($result['stdout']), true);
    king_orchestrator_failover_assert(
        is_array($decoded),
        $label . ' did not return valid JSON: ' . json_encode($result['stdout'])
    );

    return $decoded;
}

function king_orchestrator_failover_read_run_snapshot(
    array $harness,
    string $backend,
    string $observerScript,
    string $runId,
    bool $strict = true
): array {
    $result = king_orchestrator_failover_harness_exec($harness, $backend, $observerScript, [$runId]);

    if ($strict) {
        return king_orchestrator_failover_decode_json($result, 'observer/' . $backend);
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

function king_orchestrator_failover_controller_loss_scenario(): void
{
    $harness = king_orchestrator_failover_harness_create();

    try {
        $controllerScript = king_orchestrator_failover_harness_write_script($harness, 'controller-loss-controller', <<<'PHP'
<?php
function summarizer_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected handler input');
    }

    return $input;
}

king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('summarizer', 'summarizer_handler');
king_pipeline_orchestrator_run(
    ['text' => 'controller-failover'],
    [['tool' => 'summarizer', 'delay_ms' => 15000]],
    ['trace_id' => 'controller-failover-run']
);
PHP);
        $observerScript = king_orchestrator_failover_harness_write_script($harness, 'controller-loss-observer', <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1]);
if ($run === false) {
    echo json_encode(['exists' => false]), "\n";
    return;
}
echo json_encode($run), "\n";
PHP);
        $resumeScript = king_orchestrator_failover_harness_write_script($harness, 'controller-loss-resume', <<<'PHP'
<?php
function summarizer_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected handler input');
    }

    return $input;
}

$runId = $argv[1];
king_pipeline_orchestrator_register_handler('summarizer', 'summarizer_handler');
$infoBefore = king_system_get_component_info('pipeline_orchestrator');
$result = king_pipeline_orchestrator_resume_run($runId);
$run = king_pipeline_orchestrator_get_run($runId);
$infoAfter = king_system_get_component_info('pipeline_orchestrator');
echo json_encode([
    'recovered_from_state' => $infoBefore['configuration']['recovered_from_state'],
    'execution_backend' => $infoBefore['configuration']['execution_backend'],
    'topology_scope' => $infoBefore['configuration']['topology_scope'],
    'result_text' => $result['text'] ?? null,
    'run' => $run,
    'last_run_status' => $infoAfter['configuration']['last_run_status'],
]), "\n";
PHP);

        $controller = king_orchestrator_failover_harness_spawn($harness, 'local', $controllerScript);

        $runningObserved = king_orchestrator_failover_harness_wait_for(
            static function () use ($harness, $observerScript): bool {
                $snapshot = king_orchestrator_failover_read_run_snapshot(
                    $harness,
                    'local',
                    $observerScript,
                    'run-1',
                    false
                );

                return ($snapshot['run_id'] ?? null) === 'run-1'
                    && ($snapshot['status'] ?? null) === 'running'
                    && ($snapshot['finished_at'] ?? null) === 0
                    && ($snapshot['error'] ?? null) === null;
            }
        );
        king_orchestrator_failover_assert($runningObserved, 'controller-loss scenario never observed a running local run');

        $controllerCrash = king_orchestrator_failover_harness_crash_process($controller);
        king_orchestrator_failover_assert(
            $controllerCrash['status'] !== 0,
            'controller-loss scenario controller exited cleanly after forced crash'
        );
        king_orchestrator_failover_assert(
            trim($controllerCrash['stdout']) === '',
            'controller-loss scenario controller wrote unexpected stdout'
        );
        king_orchestrator_failover_assert(
            trim($controllerCrash['stderr']) === '',
            'controller-loss scenario controller wrote unexpected stderr'
        );

        $afterCrash = king_orchestrator_failover_read_run_snapshot($harness, 'local', $observerScript, 'run-1');
        king_orchestrator_failover_assert(
            ($afterCrash['status'] ?? null) === 'running',
            'controller-loss scenario did not preserve the running snapshot after controller crash'
        );
        king_orchestrator_failover_assert(
            ($afterCrash['finished_at'] ?? null) === 0,
            'controller-loss scenario unexpectedly finished the run after controller crash'
        );

        $resume = king_orchestrator_failover_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'local', $resumeScript, ['run-1']),
            'resume/local'
        );
        king_orchestrator_failover_assert(
            ($resume['recovered_from_state'] ?? null) === true,
            'controller-loss scenario did not recover orchestrator state before resume'
        );
        king_orchestrator_failover_assert(
            ($resume['execution_backend'] ?? null) === 'local',
            'controller-loss scenario execution backend drifted'
        );
        king_orchestrator_failover_assert(
            ($resume['topology_scope'] ?? null) === 'local_in_process',
            'controller-loss scenario topology scope drifted'
        );
        king_orchestrator_failover_assert(
            ($resume['result_text'] ?? null) === 'controller-failover',
            'controller-loss scenario resume result drifted'
        );
        king_orchestrator_failover_assert(
            ($resume['run']['status'] ?? null) === 'completed',
            'controller-loss scenario did not complete after resume'
        );
        king_orchestrator_failover_assert(
            ($resume['run']['result']['text'] ?? null) === 'controller-failover',
            'controller-loss scenario persisted completed result drifted'
        );
        king_orchestrator_failover_assert(
            ($resume['run']['error'] ?? null) === null,
            'controller-loss scenario left a stale error after resume'
        );
        king_orchestrator_failover_assert(
            ($resume['last_run_status'] ?? null) === 'completed',
            'controller-loss scenario component info did not record the resumed completion'
        );
    } finally {
        king_orchestrator_failover_harness_destroy($harness);
    }
}

function king_orchestrator_failover_worker_loss_scenario(): void
{
    $harness = king_orchestrator_failover_harness_create();

    try {
        $dispatchScript = king_orchestrator_failover_harness_write_script($harness, 'worker-loss-dispatch', <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'worker-failover'],
    [['tool' => 'summarizer', 'delay_ms' => 15000]],
    ['trace_id' => 'worker-failover-run']
);
echo json_encode($dispatch), "\n";
PHP);
        $observerScript = king_orchestrator_failover_harness_write_script($harness, 'worker-loss-observer', <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1]);
if ($run === false) {
    echo json_encode(['exists' => false]), "\n";
    return;
}
echo json_encode($run), "\n";
PHP);
        $workerScript = king_orchestrator_failover_harness_write_script($harness, 'worker-loss-worker', <<<'PHP'
<?php
$work = king_pipeline_orchestrator_worker_run_next();
echo json_encode($work), "\n";
PHP);

        $dispatch = king_orchestrator_failover_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'file_worker', $dispatchScript),
            'dispatch/file_worker'
        );
        $runId = $dispatch['run_id'] ?? null;
        king_orchestrator_failover_assert($runId === 'run-1', 'worker-loss scenario run id drifted');

        $worker = king_orchestrator_failover_harness_spawn($harness, 'file_worker', $workerScript);

        $claimedObserved = king_orchestrator_failover_harness_wait_for(
            static function () use ($harness, $observerScript, $runId): bool {
                $claimedFiles = glob($harness['queue_path'] . '/claimed-*.job');
                $snapshot = king_orchestrator_failover_read_run_snapshot(
                    $harness,
                    'file_worker',
                    $observerScript,
                    $runId,
                    false
                );

                return is_array($claimedFiles)
                    && count($claimedFiles) === 1
                    && ($snapshot['status'] ?? null) === 'running'
                    && ($snapshot['finished_at'] ?? null) === 0
                    && ($snapshot['error'] ?? null) === null;
            }
        );
        king_orchestrator_failover_assert($claimedObserved, 'worker-loss scenario never observed a claimed running job');

        $workerCrash = king_orchestrator_failover_harness_crash_process($worker);
        king_orchestrator_failover_assert(
            $workerCrash['status'] !== 0,
            'worker-loss scenario worker exited cleanly after forced crash'
        );
        king_orchestrator_failover_assert(
            trim($workerCrash['stdout']) === '',
            'worker-loss scenario crashed worker wrote unexpected stdout'
        );
        king_orchestrator_failover_assert(
            trim($workerCrash['stderr']) === '',
            'worker-loss scenario crashed worker wrote unexpected stderr'
        );

        $afterCrash = king_orchestrator_failover_read_run_snapshot(
            $harness,
            'file_worker',
            $observerScript,
            $runId
        );
        king_orchestrator_failover_assert(
            ($afterCrash['status'] ?? null) === 'running',
            'worker-loss scenario did not keep the run in running state after worker loss'
        );
        king_orchestrator_failover_assert(
            ($afterCrash['finished_at'] ?? null) === 0,
            'worker-loss scenario unexpectedly finished after worker loss'
        );

        $recovered = king_orchestrator_failover_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'file_worker', $workerScript),
            'worker/file_worker/recovery'
        );
        king_orchestrator_failover_assert(
            ($recovered['run_id'] ?? null) === $runId,
            'worker-loss scenario recovery worker claimed the wrong run'
        );
        king_orchestrator_failover_assert(
            ($recovered['status'] ?? null) === 'completed',
            'worker-loss scenario recovery worker did not complete the run'
        );
        king_orchestrator_failover_assert(
            ($recovered['cancel_requested'] ?? null) === false,
            'worker-loss scenario recovery worker observed a stale cancel flag'
        );
        king_orchestrator_failover_assert(
            ($recovered['result']['text'] ?? null) === 'worker-failover',
            'worker-loss scenario recovery result drifted'
        );
        king_orchestrator_failover_assert(
            ($recovered['error'] ?? null) === null,
            'worker-loss scenario recovery left a stale error'
        );

        $afterRecovery = king_orchestrator_failover_read_run_snapshot(
            $harness,
            'file_worker',
            $observerScript,
            $runId
        );
        $claimedAfterRecovery = glob($harness['queue_path'] . '/claimed-*.job');
        $queuedAfterRecovery = glob($harness['queue_path'] . '/queued-*.job');
        king_orchestrator_failover_assert(
            ($afterRecovery['status'] ?? null) === 'completed',
            'worker-loss scenario final snapshot did not converge to completed'
        );
        king_orchestrator_failover_assert(
            ($afterRecovery['result']['text'] ?? null) === 'worker-failover',
            'worker-loss scenario final snapshot result drifted'
        );
        king_orchestrator_failover_assert(
            is_array($claimedAfterRecovery) && count($claimedAfterRecovery) === 0,
            'worker-loss scenario left claimed jobs behind after recovery'
        );
        king_orchestrator_failover_assert(
            is_array($queuedAfterRecovery) && count($queuedAfterRecovery) === 0,
            'worker-loss scenario left queued jobs behind after recovery'
        );
    } finally {
        king_orchestrator_failover_harness_destroy($harness);
    }
}

function king_orchestrator_failover_remote_peer_loss_scenario(): void
{
    $harness = king_orchestrator_failover_harness_create();

    try {
        king_orchestrator_failover_harness_remote_peer_start($harness);

        $controllerScript = king_orchestrator_failover_harness_write_script($harness, 'remote-loss-controller', <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_run(
    ['text' => 'remote-failover'],
    [['tool' => 'summarizer', 'delay_ms' => 15000]],
    ['trace_id' => 'remote-failover-run']
);
PHP);
        $observerScript = king_orchestrator_failover_harness_write_script($harness, 'remote-loss-observer', <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1]);
if ($run === false) {
    echo json_encode(['exists' => false]), "\n";
    return;
}
echo json_encode($run), "\n";
PHP);
        $resumeScript = king_orchestrator_failover_harness_write_script($harness, 'remote-loss-resume', <<<'PHP'
<?php
$runId = $argv[1];
$infoBefore = king_system_get_component_info('pipeline_orchestrator');
$result = king_pipeline_orchestrator_resume_run($runId);
$run = king_pipeline_orchestrator_get_run($runId);
$infoAfter = king_system_get_component_info('pipeline_orchestrator');
echo json_encode([
    'recovered_from_state' => $infoBefore['configuration']['recovered_from_state'],
    'execution_backend' => $infoBefore['configuration']['execution_backend'],
    'topology_scope' => $infoBefore['configuration']['topology_scope'],
    'result_text' => $result['text'] ?? null,
    'run' => $run,
    'last_run_status' => $infoAfter['configuration']['last_run_status'],
]), "\n";
PHP);

        $controller = king_orchestrator_failover_harness_spawn($harness, 'remote_peer', $controllerScript);

        $attemptObserved = king_orchestrator_failover_harness_wait_for(
            static function () use ($harness, $observerScript): bool {
                $snapshot = king_orchestrator_failover_read_run_snapshot(
                    $harness,
                    'remote_peer',
                    $observerScript,
                    'run-1',
                    false
                );
                $capturePath = $harness['remote_peer']['server']['capture'] ?? null;
                $capture = is_string($capturePath) && is_file($capturePath)
                    ? json_decode((string) file_get_contents($capturePath), true)
                    : null;

                return ($snapshot['run_id'] ?? null) === 'run-1'
                    && ($snapshot['status'] ?? null) === 'running'
                    && ($snapshot['finished_at'] ?? null) === 0
                    && ($snapshot['error'] ?? null) === null
                    && is_array($capture)
                    && (($capture['events'][0]['run_id'] ?? null) === 'run-1');
            }
        );
        king_orchestrator_failover_assert(
            $attemptObserved,
            'remote-peer loss scenario never observed a running run plus the first remote-peer attempt'
        );

        $controllerCrash = king_orchestrator_failover_harness_crash_process($controller);
        king_orchestrator_failover_assert(
            $controllerCrash['status'] !== 0,
            'remote-peer loss scenario controller exited cleanly after forced crash'
        );
        king_orchestrator_failover_assert(
            trim($controllerCrash['stdout']) === '',
            'remote-peer loss scenario controller wrote unexpected stdout'
        );
        king_orchestrator_failover_assert(
            trim($controllerCrash['stderr']) === '',
            'remote-peer loss scenario controller wrote unexpected stderr'
        );

        $firstRemoteCapture = king_orchestrator_failover_harness_remote_peer_crash($harness);
        king_orchestrator_failover_assert(
            count($firstRemoteCapture['events'] ?? []) === 1,
            'remote-peer loss scenario expected exactly one first-attempt remote event'
        );
        king_orchestrator_failover_assert(
            ($firstRemoteCapture['events'][0]['run_id'] ?? null) === 'run-1',
            'remote-peer loss scenario first capture run id drifted'
        );
        king_orchestrator_failover_assert(
            ($firstRemoteCapture['events'][0]['options']['trace_id'] ?? null) === 'remote-failover-run',
            'remote-peer loss scenario first capture trace id drifted'
        );

        king_orchestrator_failover_harness_remote_peer_start($harness);

        $afterRestart = king_orchestrator_failover_read_run_snapshot(
            $harness,
            'remote_peer',
            $observerScript,
            'run-1'
        );
        king_orchestrator_failover_assert(
            ($afterRestart['status'] ?? null) === 'running',
            'remote-peer loss scenario did not preserve the running snapshot after controller and peer loss'
        );
        king_orchestrator_failover_assert(
            ($afterRestart['finished_at'] ?? null) === 0,
            'remote-peer loss scenario unexpectedly finished after controller and peer loss'
        );

        $resume = king_orchestrator_failover_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'remote_peer', $resumeScript, ['run-1']),
            'resume/remote_peer'
        );
        king_orchestrator_failover_assert(
            ($resume['recovered_from_state'] ?? null) === true,
            'remote-peer loss scenario did not recover orchestrator state before resume'
        );
        king_orchestrator_failover_assert(
            ($resume['execution_backend'] ?? null) === 'remote_peer',
            'remote-peer loss scenario execution backend drifted'
        );
        king_orchestrator_failover_assert(
            ($resume['topology_scope'] ?? null) === 'tcp_host_port_execution_peer',
            'remote-peer loss scenario topology scope drifted'
        );
        king_orchestrator_failover_assert(
            ($resume['result_text'] ?? null) === 'remote-failover',
            'remote-peer loss scenario resume result drifted'
        );
        king_orchestrator_failover_assert(
            ($resume['run']['status'] ?? null) === 'completed',
            'remote-peer loss scenario did not complete after resume'
        );
        king_orchestrator_failover_assert(
            ($resume['run']['result']['text'] ?? null) === 'remote-failover',
            'remote-peer loss scenario final result drifted'
        );
        king_orchestrator_failover_assert(
            ($resume['run']['error'] ?? null) === null,
            'remote-peer loss scenario left a stale error after resume'
        );
        king_orchestrator_failover_assert(
            ($resume['last_run_status'] ?? null) === 'completed',
            'remote-peer loss scenario component info did not record the resumed completion'
        );

        $secondRemoteCapture = king_orchestrator_failover_harness_remote_peer_stop($harness);
        king_orchestrator_failover_assert(
            count($secondRemoteCapture['events'] ?? []) === 1,
            'remote-peer loss scenario expected exactly one resumed remote event'
        );
        king_orchestrator_failover_assert(
            ($secondRemoteCapture['events'][0]['run_id'] ?? null) === 'run-1',
            'remote-peer loss scenario second capture run id drifted'
        );
        king_orchestrator_failover_assert(
            ($secondRemoteCapture['events'][0]['options']['trace_id'] ?? null) === 'remote-failover-run',
            'remote-peer loss scenario second capture trace id drifted'
        );

        $history = king_orchestrator_failover_harness_remote_peer_history($harness);
        king_orchestrator_failover_assert(
            count($history) === 2
                && ($history[0]['termination'] ?? null) === 'crash'
                && ($history[1]['termination'] ?? null) === 'stop',
            'remote-peer loss scenario remote-peer capture history drifted'
        );
    } finally {
        king_orchestrator_failover_harness_destroy($harness);
    }
}

king_orchestrator_failover_controller_loss_scenario();
king_orchestrator_failover_worker_loss_scenario();
king_orchestrator_failover_remote_peer_loss_scenario();

echo "OK\n";
?>
--EXPECT--
OK
