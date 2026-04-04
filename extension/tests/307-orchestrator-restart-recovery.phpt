--TEST--
King pipeline orchestrator persists tool registry and completed run history across restart
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-state-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$writerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-writer-');
$readerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-reader-');

@unlink($statePath);

file_put_contents($writerScript, <<<'PHP'
<?php
function writer_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected handler input');
    }

    $input['handled_by'] = 'writer';
    return ['output' => $input];
}

var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 100,
]));
var_dump(king_pipeline_orchestrator_register_handler('summarizer', 'writer_handler'));
var_dump(king_pipeline_orchestrator_configure_logging(['level' => 'debug']));
$result = king_pipeline_orchestrator_run(
    ['text' => 'hello world'],
    [['tool' => 'summarizer', 'params' => ['ratio' => 0.5]]],
    ['trace_id' => 'warm-1']
);
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($result['text']);
var_dump($result['handled_by']);
var_dump($info['configuration']['tool_count']);
var_dump($info['configuration']['run_history_count']);
var_dump($info['configuration']['last_run_status']);
var_dump($info['configuration']['registered_tools']);
PHP);

file_put_contents($readerScript, <<<'PHP'
<?php
function reader_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected handler input');
    }

    $input['handled_by'] = 'reader';
    return ['output' => $input];
}

$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['recovered_from_state']);
var_dump($info['configuration']['tool_count']);
var_dump($info['configuration']['run_history_count']);
var_dump($info['configuration']['last_run_status']);
var_dump($info['configuration']['registered_tools']);
var_dump(king_pipeline_orchestrator_register_handler('summarizer', 'reader_handler'));
$result = king_pipeline_orchestrator_run(
    ['text' => 'again'],
    [['tool' => 'summarizer']],
    ['trace_id' => 'warm-2']
);
var_dump($result['text']);
var_dump($result['handled_by']);
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['run_history_count']);
var_dump($info['configuration']['last_run_status']);
PHP);

$baseCommand = sprintf(
    '%s -n -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    '%s'
);

$writerCommand = sprintf($baseCommand, escapeshellarg($writerScript));
$readerCommand = sprintf($baseCommand, escapeshellarg($readerScript));

exec($writerCommand, $writerOutput, $writerStatus);
var_dump($writerStatus);
echo implode("\n", $writerOutput), "\n";

exec($readerCommand, $readerOutput, $readerStatus);
var_dump($readerStatus);
echo implode("\n", $readerOutput), "\n";

@unlink($writerScript);
@unlink($readerScript);
@unlink($statePath);
?>
--EXPECT--
int(0)
bool(true)
bool(true)
bool(true)
string(11) "hello world"
string(6) "writer"
int(1)
int(1)
string(9) "completed"
array(1) {
  [0]=>
  string(10) "summarizer"
}
int(0)
bool(true)
int(1)
int(1)
string(9) "completed"
array(1) {
  [0]=>
  string(10) "summarizer"
}
bool(true)
string(5) "again"
string(6) "reader"
int(2)
string(9) "completed"
