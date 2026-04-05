--TEST--
King multi-step userland-backed runs expose completed-step and compensation terminal visibility
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function visibility_step1_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected input');
    }

    $input['history'][] = 'stage-1';
    return ['output' => $input];
}

function visibility_step2_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected input');
    }

    $input['history'][] = 'stage-2';
    return ['output' => $input];
}

function visibility_failing_step2_handler(array $context): array
{
    throw new RuntimeException('visibility-stage-2 failed intentionally');
}

assert_true(
    king_pipeline_orchestrator_register_tool('visibility-stage-1', [
        'model' => 'gpt-sim',
        'max_tokens' => 64,
    ]),
    'failed to register visibility stage-1'
);
assert_true(
    king_pipeline_orchestrator_register_tool('visibility-stage-2', [
        'model' => 'gpt-sim',
        'max_tokens' => 64,
    ]),
    'failed to register visibility stage-2'
);
assert_true(
    king_pipeline_orchestrator_register_tool('visibility-stage-2-fail', [
        'model' => 'gpt-sim',
        'max_tokens' => 64,
    ]),
    'failed to register visibility stage-2-fail'
);
assert_true(
    king_pipeline_orchestrator_register_handler('visibility-stage-1', 'visibility_step1_handler'),
    'failed to register visibility stage-1 handler'
);
assert_true(
    king_pipeline_orchestrator_register_handler('visibility-stage-2', 'visibility_step2_handler'),
    'failed to register visibility stage-2 handler'
);
assert_true(
    king_pipeline_orchestrator_register_handler('visibility-stage-2-fail', 'visibility_failing_step2_handler'),
    'failed to register visibility stage-2-fail handler'
);

$success = king_pipeline_orchestrator_run(
    ['text' => 'visibility-success', 'history' => []],
    [
        ['tool' => 'visibility-stage-1'],
        ['tool' => 'visibility-stage-2'],
    ],
    ['trace_id' => 'userland-visibility-success']
);
if (!is_array($success)) {
    throw new RuntimeException('visibility success run did not return a result');
}

$successRunId = king_system_get_component_info('pipeline_orchestrator')['configuration']['last_run_id'] ?? null;
assert_true($successRunId !== null, 'missing success run id');

$successRun = king_pipeline_orchestrator_get_run($successRunId);
assert_true(is_array($successRun), 'missing success run snapshot');
assert_true(($successRun['status'] ?? null) === 'completed', 'success run is not completed');
assert_true(($successRun['completed_step_count'] ?? null) === 2, 'success run completed step count drifted');
assert_true(($successRun['compensation']['required'] ?? null) === false, 'success run compensation requirement drifted');
assert_true(
    ($successRun['compensation']['trigger'] ?? null) === 'none',
    'success run compensation trigger drifted'
);
assert_true(
    ($successRun['compensation']['pending_step_count'] ?? null) === 0,
    'success run compensation pending count drifted'
);
assert_true(
    ($successRun['compensation']['pending_steps'] ?? null) === [],
    'success run compensation pending steps drifted'
);
assert_true(($successRun['steps'][0]['status'] ?? null) === 'completed', 'success run step-1 status drifted');
assert_true(($successRun['steps'][0]['compensation_status'] ?? null) === 'not_required', 'success run step-1 compensation status drifted');
assert_true(($successRun['steps'][1]['status'] ?? null) === 'completed', 'success run step-2 status drifted');
assert_true(($successRun['steps'][1]['compensation_status'] ?? null) === 'not_required', 'success run step-2 compensation status drifted');
assert_true(
    ($successRun['result']['history'] ?? null) === ['stage-1', 'stage-2'],
    'success run history payload drifted'
);
assert_true(
    ($successRun['distributed_observability']['completed_step_count'] ?? null) === 2,
    'success run observability completed step count drifted'
);

try {
    king_pipeline_orchestrator_run(
        ['text' => 'visibility-failure', 'history' => []],
        [
            ['tool' => 'visibility-stage-1'],
            ['tool' => 'visibility-stage-2-fail'],
        ],
        ['trace_id' => 'userland-visibility-failure']
    );
    throw new RuntimeException('visibility failing run did not throw');
} catch (Throwable $e) {
    assert_true($e instanceof RuntimeException, 'failure run exception class drifted');
    assert_true(
        $e->getMessage() === 'visibility-stage-2 failed intentionally',
        'failure run exception message drifted'
    );
}

$failureRunId = king_system_get_component_info('pipeline_orchestrator')['configuration']['last_run_id'] ?? null;
assert_true($failureRunId !== null, 'missing failure run id');
$failureRun = king_pipeline_orchestrator_get_run($failureRunId);

assert_true(is_array($failureRun), 'missing failure run snapshot');
assert_true(($failureRun['status'] ?? null) === 'failed', 'failure run is not failed');
assert_true(($failureRun['error_classification']['category'] ?? null) === 'runtime', 'failure run classification category drifted');
assert_true(($failureRun['completed_step_count'] ?? null) === 1, 'failure run completed step count drifted');
assert_true(($failureRun['error_classification']['step_index'] ?? null) === 1, 'failure run failed step index drifted');
assert_true(($failureRun['steps'][0]['status'] ?? null) === 'completed', 'failure run step-1 status drifted');
assert_true(($failureRun['steps'][0]['compensation_status'] ?? null) === 'pending', 'failure run step-1 compensation status drifted');
assert_true(($failureRun['steps'][1]['status'] ?? null) === 'failed', 'failure run step-2 status drifted');
assert_true(($failureRun['steps'][1]['compensation_status'] ?? null) === 'not_applicable', 'failure run step-2 compensation status drifted');
assert_true(($failureRun['compensation']['required'] ?? null) === true, 'failure run compensation requirement drifted');
assert_true(
    ($failureRun['compensation']['trigger'] ?? null) === 'failed',
    'failure run compensation trigger drifted'
);
assert_true(
    ($failureRun['compensation']['pending_step_count'] ?? null) === 1,
    'failure run compensation pending count drifted'
);
assert_true(
    count($failureRun['compensation']['pending_steps'] ?? []) === 1,
    'failure run compensation pending steps drifted'
);
assert_true(
    ($failureRun['compensation']['pending_steps'][0]['index'] ?? null) === 0,
    'failure run compensation pending step index drifted'
);
assert_true(
    ($failureRun['compensation']['pending_steps'][0]['tool'] ?? null) === 'visibility-stage-1',
    'failure run compensation pending tool drifted'
);
assert_true(
    ($failureRun['compensation']['pending_steps'][0]['status'] ?? null) === 'pending',
    'failure run compensation pending step status drifted'
);
assert_true(
    ($failureRun['distributed_observability']['completed_step_count'] ?? null) === 1,
    'failure run observability completed step count drifted'
);

?>
--EXPECT--
