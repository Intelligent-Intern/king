--TEST--
King pipeline orchestrator seeded registry churn keeps tool registration and pipeline execution stable
--FILE--
<?php
function seeded_registry_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected handler input');
    }

    return $input;
}

$registered = true;
for ($i = 0; $i < 32; $i++) {
    $registered = $registered && king_pipeline_orchestrator_register_tool(
        sprintf('tool_%02d', $i % 10),
        [
            'model' => 'gpt-sim',
            'weight' => $i,
            'bucket' => $i % 4,
        ]
    );
    $registered = $registered && king_pipeline_orchestrator_register_handler(
        sprintf('tool_%02d', $i % 10),
        'seeded_registry_handler'
    );
}

$configured = king_pipeline_orchestrator_configure_logging([
    'level' => 'debug',
    'seed' => 294,
]);

$stable = true;
for ($i = 0; $i < 64; $i++) {
    $initial = [
        'iteration' => $i,
        'hash' => sha1((string) $i),
    ];
    $pipeline = [];
    $stepCount = ($i % 4) + 1;

    for ($step = 0; $step < $stepCount; $step++) {
        $pipeline[] = [
            'tool' => sprintf('tool_%02d', ($i + $step) % 10),
            'params' => [
                'temperature' => (($i + $step) % 7) / 10,
                'max_tokens' => 64 + $step,
            ],
        ];
    }

    $result = king_pipeline_orchestrator_run($initial, $pipeline, [
        'trace_id' => sprintf('trace-%02d', $i),
    ]);

    if ($result !== $initial) {
        $stable = false;
        break;
    }
}

$rejected = false;
try {
    king_pipeline_orchestrator_register_tool('', []);
} catch (Throwable $e) {
    $rejected = $e instanceof King\ValidationException;
}

var_dump($registered);
var_dump($configured);
var_dump($stable);
var_dump($rejected);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
