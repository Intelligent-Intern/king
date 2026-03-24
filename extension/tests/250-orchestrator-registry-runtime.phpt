--TEST--
King Pipeline Orchestrator: Tool registration and pipeline runner verification
--FILE--
<?php
// 1. Register a mock tool
var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 100
]));

// 2. Configure logging
var_dump(king_pipeline_orchestrator_configure_logging(['level' => 'debug']));

// 3. Run a simple pipeline
$initial_data = ['text' => 'hello world'];
$pipeline = [
    ['tool' => 'summarizer', 'params' => ['ratio' => 0.5]]
];

$result = king_pipeline_orchestrator_run($initial_data, $pipeline);
var_dump($result === $initial_data); // Current skeleton just reflects initial data

// 4. Try invalid tool registration
try {
   king_pipeline_orchestrator_register_tool('', []);
} catch (Throwable $e) {
   echo "caught invalid name\n";
}

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
caught invalid name
