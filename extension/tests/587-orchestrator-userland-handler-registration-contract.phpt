--TEST--
King pipeline orchestrator exposes a process-local userland handler registration API over durable tool names
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-handler-state-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$writerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-handler-writer-');
$readerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-handler-reader-');

@unlink($statePath);

file_put_contents($writerScript, <<<'PHP'
<?php
function writer_handler(array $context): array
{
    return ['output' => $context['input'] ?? []];
}

var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));
var_dump(king_pipeline_orchestrator_register_handler('summarizer', 'writer_handler'));
$reflection = new ReflectionFunction('king_pipeline_orchestrator_register_handler');
$parameters = $reflection->getParameters();
var_dump($reflection->getNumberOfRequiredParameters());
var_dump($parameters[1]->getType()->getName());
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['tool_count']);
var_dump($info['configuration']['registered_tools']);
PHP);

file_put_contents($readerScript, <<<'PHP'
<?php
function reader_handler(array $context): array
{
    return ['output' => $context['input'] ?? []];
}

$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['recovered_from_state']);
var_dump($info['configuration']['tool_count']);
var_dump(king_pipeline_orchestrator_register_handler('summarizer', 'reader_handler'));

try {
    king_pipeline_orchestrator_register_handler('missing', 'reader_handler');
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    king_pipeline_orchestrator_register_handler('', 'reader_handler');
    echo "no-exception-2\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
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
--EXPECTF--
int(0)
bool(true)
bool(true)
int(2)
string(8) "callable"
int(1)
array(1) {
  [0]=>
  string(10) "summarizer"
}
int(0)
bool(true)
int(1)
bool(true)
string(%d) "King\RuntimeException"
string(%d) "king_pipeline_orchestrator_register_handler() requires a previously registered tool 'missing'."
string(%d) "King\ValidationException"
string(%d) "king_pipeline_orchestrator_register_handler() requires a non-empty tool name."
