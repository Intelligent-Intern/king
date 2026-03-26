--TEST--
King pipeline orchestrator rehydrates running snapshots without losing recovered tool state
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-running-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$readerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-recovery-reader-');

$logging = base64_encode(serialize(['level' => 'debug']));
$toolName = base64_encode('summarizer');
$toolConfig = base64_encode(serialize(['model' => 'gpt-sim', 'max_tokens' => 100]));
$initial = base64_encode(serialize(['text' => 'mid-flight']));
$pipeline = base64_encode(serialize([
    ['tool' => 'summarizer', 'params' => ['ratio' => 0.5]],
]));
$options = base64_encode(serialize(['trace_id' => 'recovering-42']));
$nullPayload = base64_encode(serialize(null));

file_put_contents(
    $statePath,
    "version\t1\n"
    . "logging\t{$logging}\n"
    . "tool\t{$toolName}\t{$toolConfig}\n"
    . "run\trun-42\trunning\t100\t0\t{$initial}\t{$pipeline}\t{$options}\t{$nullPayload}\t{$nullPayload}\n"
);

file_put_contents($readerScript, <<<'PHP'
<?php
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['recovered_from_state']);
var_dump($info['configuration']['logging_configured']);
var_dump($info['configuration']['tool_count']);
var_dump($info['configuration']['run_history_count']);
var_dump($info['configuration']['active_run_count']);
var_dump($info['configuration']['last_run_id']);
var_dump($info['configuration']['last_run_status']);
var_dump($info['configuration']['registered_tools']);
$result = king_pipeline_orchestrator_run(
    ['text' => 'resumed'],
    [['tool' => 'summarizer']],
    ['trace_id' => 'resume-now']
);
var_dump($result['text']);
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['run_history_count']);
var_dump($info['configuration']['active_run_count']);
var_dump($info['configuration']['last_run_id']);
var_dump($info['configuration']['last_run_status']);
PHP);

$command = sprintf(
    '%s -n -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    escapeshellarg($readerScript)
);

exec($command, $readerOutput, $readerStatus);
var_dump($readerStatus);
echo implode("\n", $readerOutput), "\n";

@unlink($readerScript);
@unlink($statePath);
?>
--EXPECT--
int(0)
bool(true)
bool(true)
int(1)
int(1)
int(1)
string(6) "run-42"
string(7) "running"
array(1) {
  [0]=>
  string(10) "summarizer"
}
string(7) "resumed"
int(2)
int(1)
string(6) "run-43"
string(9) "completed"
