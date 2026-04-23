--TEST--
King pipeline orchestrator accepts DAG-shaped pipelines and executes a deterministic topological schedule
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

function dag_left_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('left received unexpected input');
    }

    $input['history'][] = 'left';
    return ['output' => $input];
}

function dag_right_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('right received unexpected input');
    }

    $input['history'][] = 'right';
    return ['output' => $input];
}

function dag_join_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('join received unexpected input');
    }

    $input['history'][] = 'join';
    return ['output' => $input];
}

function dag_tail_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('tail received unexpected input');
    }

    $input['history'][] = 'tail';
    return ['output' => $input];
}

assert_true(
    king_pipeline_orchestrator_register_tool('dag-left', ['model' => 'gpt-sim', 'max_tokens' => 32]),
    'failed to register dag-left tool'
);
assert_true(
    king_pipeline_orchestrator_register_tool('dag-right', ['model' => 'gpt-sim', 'max_tokens' => 32]),
    'failed to register dag-right tool'
);
assert_true(
    king_pipeline_orchestrator_register_tool('dag-join', ['model' => 'gpt-sim', 'max_tokens' => 32]),
    'failed to register dag-join tool'
);
assert_true(
    king_pipeline_orchestrator_register_tool('dag-tail', ['model' => 'gpt-sim', 'max_tokens' => 32]),
    'failed to register dag-tail tool'
);

assert_true(king_pipeline_orchestrator_register_handler('dag-left', 'dag_left_handler'), 'failed to register dag-left handler');
assert_true(king_pipeline_orchestrator_register_handler('dag-right', 'dag_right_handler'), 'failed to register dag-right handler');
assert_true(king_pipeline_orchestrator_register_handler('dag-join', 'dag_join_handler'), 'failed to register dag-join handler');
assert_true(king_pipeline_orchestrator_register_handler('dag-tail', 'dag_tail_handler'), 'failed to register dag-tail handler');

$result = king_pipeline_orchestrator_run(
    ['history' => []],
    [
        'steps' => [
            ['id' => 'join', 'tool' => 'dag-join', 'deps' => ['left', 'right']],
            ['id' => 'left', 'tool' => 'dag-left'],
            ['id' => 'tail', 'tool' => 'dag-tail', 'deps' => ['join']],
            ['id' => 'right', 'tool' => 'dag-right'],
        ],
    ],
    ['trace_id' => 'dag-topological-contract']
);

assert_true(is_array($result), 'DAG run did not return an array result');
assert_true(
    ($result['history'] ?? null) === ['left', 'right', 'join', 'tail'],
    'DAG topological execution order drifted'
);

$runId = king_system_get_component_info('pipeline_orchestrator')['configuration']['last_run_id'] ?? null;
assert_true(is_string($runId) && $runId !== '', 'missing DAG run id');

$run = king_pipeline_orchestrator_get_run($runId);
assert_true(is_array($run), 'missing DAG run snapshot');
assert_true(($run['status'] ?? null) === 'completed', 'DAG run did not complete');
assert_true(($run['completed_step_count'] ?? null) === 4, 'DAG completed step count drifted');
assert_true(($run['step_count'] ?? null) === 4, 'DAG step count drifted');

$pipeline = $run['pipeline'] ?? null;
assert_true(is_array($pipeline), 'persisted DAG pipeline is missing');
assert_true(($pipeline[0]['id'] ?? null) === 'left', 'normalized DAG step[0] id drifted');
assert_true(($pipeline[1]['id'] ?? null) === 'right', 'normalized DAG step[1] id drifted');
assert_true(($pipeline[2]['id'] ?? null) === 'join', 'normalized DAG step[2] id drifted');
assert_true(($pipeline[3]['id'] ?? null) === 'tail', 'normalized DAG step[3] id drifted');

$steps = $run['steps'] ?? null;
assert_true(is_array($steps), 'DAG step snapshots missing');
assert_true(($steps[0]['status'] ?? null) === 'completed', 'DAG step[0] status drifted');
assert_true(($steps[1]['status'] ?? null) === 'completed', 'DAG step[1] status drifted');
assert_true(($steps[2]['status'] ?? null) === 'completed', 'DAG step[2] status drifted');
assert_true(($steps[3]['status'] ?? null) === 'completed', 'DAG step[3] status drifted');

try {
    king_pipeline_orchestrator_run(
        ['history' => []],
        [
            'steps' => [
                ['id' => 'a', 'tool' => 'dag-left', 'deps' => ['b']],
                ['id' => 'b', 'tool' => 'dag-right', 'deps' => ['a']],
            ],
        ]
    );
    throw new RuntimeException('cycle DAG run did not throw');
} catch (Throwable $error) {
    assert_true(
        str_contains($error->getMessage(), 'cycle') || str_contains($error->getMessage(), 'dependency'),
        'cycle DAG error message drifted'
    );
}

?>
--EXPECT--
