--TEST--
King Pipeline Orchestrator: Tool registration and pipeline runner verification
--FILE--
<?php
function summarizer_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected input payload');
    }

    $input['handled_by'] = 'summarizer';
    return $input;
}

// 1. Register a mock tool
var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 100
]));
var_dump(king_pipeline_orchestrator_register_handler('summarizer', 'summarizer_handler'));

// 2. Configure logging
var_dump(king_pipeline_orchestrator_configure_logging(['level' => 'debug']));

// 3. Run a simple pipeline
$initial_data = ['text' => 'hello world'];
$pipeline = [
    ['tool' => 'summarizer', 'params' => ['ratio' => 0.5]]
];

$result = king_pipeline_orchestrator_run($initial_data, $pipeline);
var_dump(($result['handled_by'] ?? null) === 'summarizer');

$info = king_system_get_component_info('pipeline_orchestrator');
$run = king_pipeline_orchestrator_get_run($info['configuration']['last_run_id']);
var_dump($info['configuration']['tool_count']);
var_dump($info['configuration']['run_history_count']);
var_dump($info['configuration']['active_run_count']);
var_dump($info['configuration']['last_run_status']);
var_dump($info['configuration']['registered_tools']);
var_dump(($run['result']['handled_by'] ?? null) === 'summarizer');

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
bool(true)
int(1)
int(1)
int(0)
string(9) "completed"
array(1) {
  [0]=>
  string(10) "summarizer"
}
bool(true)
caught invalid name
