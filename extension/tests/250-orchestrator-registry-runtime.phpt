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
    if (($context['tool']['name'] ?? null) !== 'summarizer') {
        throw new RuntimeException('unexpected tool name');
    }
    if (($context['tool']['config']['model'] ?? null) !== 'gpt-sim') {
        throw new RuntimeException('unexpected tool config');
    }
    if (($context['run']['execution_backend'] ?? null) !== 'local') {
        throw new RuntimeException('unexpected execution backend');
    }
    if (($context['run']['topology_scope'] ?? null) !== 'local_in_process') {
        throw new RuntimeException('unexpected topology scope');
    }
    if (($context['run']['attempt_number'] ?? null) !== 1) {
        throw new RuntimeException('unexpected attempt number');
    }
    if (($context['step']['index'] ?? null) !== 0) {
        throw new RuntimeException('unexpected step index');
    }
    if (($context['step']['tool_name'] ?? null) !== 'summarizer') {
        throw new RuntimeException('unexpected step tool name');
    }
    if (($context['step']['definition']['params']['ratio'] ?? null) !== 0.5) {
        throw new RuntimeException('unexpected step definition');
    }
    if (!is_string($context['run_id'] ?? null)) {
        throw new RuntimeException('unexpected run id alias');
    }

    $input['handled_by'] = 'summarizer';
    $input['tool_model'] = $context['tool']['config']['model'];
    $input['step_index'] = $context['step']['index'];
    return ['output' => $input];
}

function invalid_result_handler(array $context): array
{
    return $context['input'] ?? [];
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
var_dump(($result['tool_model'] ?? null) === 'gpt-sim');
var_dump(($result['step_index'] ?? null) === 0);

$info = king_system_get_component_info('pipeline_orchestrator');
$run = king_pipeline_orchestrator_get_run($info['configuration']['last_run_id']);
var_dump($info['configuration']['tool_count']);
var_dump($info['configuration']['run_history_count']);
var_dump($info['configuration']['active_run_count']);
var_dump($info['configuration']['last_run_status']);
var_dump($info['configuration']['registered_tools']);
var_dump(($run['result']['handled_by'] ?? null) === 'summarizer');

// 4. Enforce the explicit handler result contract
var_dump(king_pipeline_orchestrator_register_tool('legacy', [
    'model' => 'gpt-sim',
    'max_tokens' => 8
]));
var_dump(king_pipeline_orchestrator_register_handler('legacy', 'invalid_result_handler'));
try {
   king_pipeline_orchestrator_run(['text' => 'legacy'], [['tool' => 'legacy']]);
} catch (Throwable $e) {
   var_dump(get_class($e));
   var_dump(str_contains($e->getMessage(), "key 'output'"));
}

// 5. Try invalid tool registration
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
bool(true)
bool(true)
string(21) "King\RuntimeException"
bool(true)
caught invalid name
