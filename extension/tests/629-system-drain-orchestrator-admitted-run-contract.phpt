--TEST--
King drain blocks new local orchestrator submissions while preserving already admitted local multi-step runs
--FILE--
<?php
function king_system_drain_orchestrator_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_system_drain_stage1_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('stage-1 received unexpected input');
    }

    $statusBefore = king_system_get_status();
    $input['history'][] = 'stage-1';
    $input['stage1_before_lifecycle'] = $statusBefore['lifecycle'] ?? null;
    $input['stage1_before_submissions'] = $statusBefore['admission']['orchestrator_submissions'] ?? null;
    $input['stage1_backend'] = $context['run']['execution_backend'] ?? null;

    $input['restart_ok'] = king_system_restart_component('telemetry');
    $input['restart_error'] = king_get_last_error();

    $statusAfterRestart = king_system_get_status();
    $input['stage1_after_lifecycle'] = $statusAfterRestart['lifecycle'] ?? null;
    $input['stage1_after_submissions'] = $statusAfterRestart['admission']['orchestrator_submissions'] ?? null;
    $input['stage1_after_drain_requested'] = $statusAfterRestart['drain_intent']['requested'] ?? null;

    $blockedClass = '';
    $blockedMessage = '';
    try {
        king_pipeline_orchestrator_run(
            ['text' => 'nested-blocked'],
            [['tool' => 'drain-nested']]
        );
        $input['nested_unexpected_success'] = true;
    } catch (Throwable $e) {
        $blockedClass = get_class($e);
        $blockedMessage = $e->getMessage();
        $input['nested_unexpected_success'] = false;
    }

    $input['nested_blocked_class'] = $blockedClass;
    $input['nested_blocked_contains_gate'] = str_contains(
        $blockedMessage,
        'cannot admit orchestrator_submissions'
    );
    $input['nested_blocked_contains_drain'] = str_contains(
        $blockedMessage,
        "lifecycle is 'draining'"
    );
    $input['nested_blocked_last_error'] = king_get_last_error();

    usleep(250000);

    $statusBeforeReturn = king_system_get_status();
    $input['stage1_before_return_lifecycle'] = $statusBeforeReturn['lifecycle'] ?? null;
    $input['stage1_before_return_submissions'] = $statusBeforeReturn['admission']['orchestrator_submissions'] ?? null;

    return ['output' => $input];
}

function king_system_drain_stage2_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('stage-2 received unexpected input');
    }

    $status = king_system_get_status();
    $input['history'][] = 'stage-2';
    $input['stage2_lifecycle'] = $status['lifecycle'] ?? null;
    $input['stage2_submissions'] = $status['admission']['orchestrator_submissions'] ?? null;
    $input['stage2_drain_requested'] = $status['drain_intent']['requested'] ?? null;
    $input['stage2_backend'] = $context['run']['execution_backend'] ?? null;

    return ['output' => $input];
}

function king_system_drain_nested_handler(array $context): array
{
    throw new RuntimeException('nested handler should not run while drain gates new submissions');
}

function king_system_drain_recovery_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('recovery handler received unexpected input');
    }

    $input['handled_by'] = $context['tool']['name'] ?? null;
    $input['execution_backend'] = $context['run']['execution_backend'] ?? null;

    return ['output' => $input];
}

king_system_drain_orchestrator_assert(
    king_system_init(['component_timeout_seconds' => 1]),
    'failed to init coordinated system runtime'
);

foreach ([
    'drain-stage-1',
    'drain-stage-2',
    'drain-nested',
    'drain-recovery',
] as $tool) {
    king_system_drain_orchestrator_assert(
        king_pipeline_orchestrator_register_tool($tool, [
            'model' => 'gpt-sim',
            'max_tokens' => 64,
        ]),
        'failed to register tool ' . $tool
    );
}

king_system_drain_orchestrator_assert(
    king_pipeline_orchestrator_register_handler('drain-stage-1', 'king_system_drain_stage1_handler'),
    'failed to register stage-1 handler'
);
king_system_drain_orchestrator_assert(
    king_pipeline_orchestrator_register_handler('drain-stage-2', 'king_system_drain_stage2_handler'),
    'failed to register stage-2 handler'
);
king_system_drain_orchestrator_assert(
    king_pipeline_orchestrator_register_handler('drain-nested', 'king_system_drain_nested_handler'),
    'failed to register nested handler'
);
king_system_drain_orchestrator_assert(
    king_pipeline_orchestrator_register_handler('drain-recovery', 'king_system_drain_recovery_handler'),
    'failed to register recovery handler'
);

$result = king_pipeline_orchestrator_run(
    ['text' => 'outer-drain-run', 'history' => []],
    [
        ['tool' => 'drain-stage-1'],
        ['tool' => 'drain-stage-2'],
    ],
    ['trace_id' => 'system-drain-orchestrator-admitted-run']
);

king_system_drain_orchestrator_assert(is_array($result), 'outer run did not return an array result');
king_system_drain_orchestrator_assert(
    ($result['history'] ?? null) === ['stage-1', 'stage-2'],
    'outer run did not preserve both admitted stages'
);
king_system_drain_orchestrator_assert(
    ($result['stage1_before_lifecycle'] ?? null) === 'ready',
    'stage-1 was not admitted while the system was ready'
);
king_system_drain_orchestrator_assert(
    ($result['stage1_before_submissions'] ?? null) === true,
    'stage-1 did not observe open orchestrator submission admission'
);
king_system_drain_orchestrator_assert(
    ($result['stage1_backend'] ?? null) === 'local',
    'stage-1 did not execute on the local backend'
);
king_system_drain_orchestrator_assert(
    ($result['restart_ok'] ?? null) === true,
    'stage-1 failed to request telemetry restart'
);
king_system_drain_orchestrator_assert(
    ($result['restart_error'] ?? '') === '',
    'stage-1 reported an unexpected restart error'
);
king_system_drain_orchestrator_assert(
    ($result['stage1_after_lifecycle'] ?? null) === 'draining',
    'stage-1 did not observe draining immediately after restart'
);
king_system_drain_orchestrator_assert(
    ($result['stage1_after_submissions'] ?? null) === false,
    'stage-1 left orchestrator submissions open after drain'
);
king_system_drain_orchestrator_assert(
    ($result['stage1_after_drain_requested'] ?? null) === true,
    'stage-1 did not observe drain intent after restart'
);
king_system_drain_orchestrator_assert(
    ($result['nested_unexpected_success'] ?? true) === false,
    'a nested new run slipped through during drain'
);
king_system_drain_orchestrator_assert(
    ($result['nested_blocked_class'] ?? null) === 'King\\RuntimeException',
    'nested blocked run returned the wrong exception class'
);
king_system_drain_orchestrator_assert(
    ($result['nested_blocked_contains_gate'] ?? false) === true,
    'nested blocked run did not report orchestrator_submissions gating'
);
king_system_drain_orchestrator_assert(
    ($result['nested_blocked_contains_drain'] ?? false) === true,
    'nested blocked run did not report the draining lifecycle'
);
king_system_drain_orchestrator_assert(
    str_contains(
        (string) ($result['nested_blocked_last_error'] ?? ''),
        'cannot admit orchestrator_submissions'
    ),
    'nested blocked run did not leave the expected last error'
);
king_system_drain_orchestrator_assert(
    in_array(
        $result['stage1_before_return_lifecycle'] ?? null,
        ['draining', 'starting'],
        true
    ),
    'stage-1 left the non-ready drain/restart window before it returned'
);
king_system_drain_orchestrator_assert(
    ($result['stage1_before_return_submissions'] ?? null) === false,
    'stage-1 saw new submissions reopen before it returned'
);
king_system_drain_orchestrator_assert(
    in_array(
        $result['stage2_lifecycle'] ?? null,
        ['draining', 'starting'],
        true
    ),
    'stage-2 did not continue while the system was still non-ready after drain'
);
king_system_drain_orchestrator_assert(
    ($result['stage2_submissions'] ?? null) === false,
    'stage-2 saw new submissions reopen during drain'
);
king_system_drain_orchestrator_assert(
    ($result['stage2_backend'] ?? null) === 'local',
    'stage-2 did not continue on the local backend'
);

$status = king_system_get_status();
king_system_drain_orchestrator_assert(
    in_array(
        $status['lifecycle'] ?? null,
        ['draining', 'starting'],
        true
    ),
    'system was no longer inside the non-ready drain/restart window after the admitted run completed'
);
king_system_drain_orchestrator_assert(
    ($status['admission']['orchestrator_submissions'] ?? null) === false,
    'system reopened new orchestrator submissions too early after the admitted run'
);

$runId = king_system_get_component_info('pipeline_orchestrator')['configuration']['last_run_id'] ?? null;
king_system_drain_orchestrator_assert(is_string($runId) && $runId !== '', 'missing last run id after admitted run');

$run = king_pipeline_orchestrator_get_run($runId);
king_system_drain_orchestrator_assert(is_array($run), 'missing admitted run snapshot');
king_system_drain_orchestrator_assert(
    ($run['status'] ?? null) === 'completed',
    'admitted run snapshot did not finish completed'
);
king_system_drain_orchestrator_assert(
    ($run['completed_step_count'] ?? null) === 2,
    'admitted run did not persist both completed steps'
);
king_system_drain_orchestrator_assert(
    ($run['steps'][0]['status'] ?? null) === 'completed',
    'admitted run step-1 was not persisted completed'
);
king_system_drain_orchestrator_assert(
    ($run['steps'][1]['status'] ?? null) === 'completed',
    'admitted run step-2 was not persisted completed'
);
king_system_drain_orchestrator_assert(
    in_array(
        $run['result']['stage2_lifecycle'] ?? null,
        ['draining', 'starting'],
        true
    ),
    'admitted run snapshot lost the non-ready proof from step-2'
);

$statusBeforeBlockedRetry = king_system_get_status();
$blockedClass = '';
$blockedMessage = '';
try {
    king_pipeline_orchestrator_run(
        ['text' => 'post-drain-blocked'],
        [['tool' => 'drain-nested']]
    );
} catch (Throwable $e) {
    $blockedClass = get_class($e);
    $blockedMessage = $e->getMessage();
}

king_system_drain_orchestrator_assert(
    $blockedClass === 'King\\RuntimeException',
    'fresh post-drain submission returned the wrong exception class'
);
king_system_drain_orchestrator_assert(
    str_contains($blockedMessage, 'cannot admit orchestrator_submissions'),
    'fresh post-drain submission did not report admission gating'
);
king_system_drain_orchestrator_assert(
    in_array(
        $statusBeforeBlockedRetry['lifecycle'] ?? null,
        ['draining', 'starting'],
        true
    ),
    'fresh post-drain submission was attempted outside the non-ready drain/restart window'
);
king_system_drain_orchestrator_assert(
    str_contains(king_get_last_error(), 'cannot admit orchestrator_submissions'),
    'fresh post-drain submission did not leave the expected last error'
);

$recovered = false;
for ($attempt = 0; $attempt < 30; $attempt++) {
    $status = king_system_get_status();
    if (($status['lifecycle'] ?? null) === 'ready') {
        king_system_drain_orchestrator_assert(
            ($status['admission']['orchestrator_submissions'] ?? null) === true,
            'system did not reopen orchestrator submissions after recovery'
        );
        $recovered = true;
        break;
    }

    king_system_drain_orchestrator_assert(
        ($status['admission']['orchestrator_submissions'] ?? null) === false,
        'system reopened orchestrator submissions before it returned to ready'
    );
    usleep(100000);
}

king_system_drain_orchestrator_assert(
    $recovered,
    'system did not recover to ready after drain within the expected local interval'
);

$recovery = king_pipeline_orchestrator_run(
    ['text' => 'recovered'],
    [['tool' => 'drain-recovery']]
);
king_system_drain_orchestrator_assert(
    ($recovery['text'] ?? null) === 'recovered',
    'recovery run lost the input payload'
);
king_system_drain_orchestrator_assert(
    ($recovery['handled_by'] ?? null) === 'drain-recovery',
    'recovery run used the wrong handler'
);
king_system_drain_orchestrator_assert(
    ($recovery['execution_backend'] ?? null) === 'local',
    'recovery run used the wrong execution backend'
);
king_system_drain_orchestrator_assert(
    king_get_last_error() === '',
    'recovery run left an unexpected last error'
);

king_system_drain_orchestrator_assert(king_system_shutdown(), 'system shutdown failed');
king_system_drain_orchestrator_assert(
    king_system_get_status()['initialized'] === false,
    'system stayed initialized after shutdown'
);

echo "OK\n";
?>
--EXPECT--
OK
