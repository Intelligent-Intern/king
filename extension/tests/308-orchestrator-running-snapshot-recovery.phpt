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
$prepareToolName = base64_encode('prepare');
$finalizeToolName = base64_encode('finalize');
$toolConfig = base64_encode(serialize(['model' => 'gpt-sim', 'max_tokens' => 100]));
$initial = base64_encode(serialize(['text' => 'mid-flight', 'history' => []]));
$pipeline = base64_encode(serialize([
    ['tool' => 'prepare'],
    ['tool' => 'finalize'],
]));
$options = base64_encode(serialize(['trace_id' => 'recovering-42']));
$partialResult = base64_encode(serialize([
    'text' => 'mid-flight',
    'history' => ['prepare'],
]));
$nullPayload = base64_encode(serialize(null));

file_put_contents(
    $statePath,
    "version\t1\n"
    . "logging\t{$logging}\n"
    . "tool\t{$prepareToolName}\t{$toolConfig}\n"
    . "tool\t{$finalizeToolName}\t{$toolConfig}\n"
    . "run\trun-42\trunning\t100\t0\t{$initial}\t{$pipeline}\t{$options}\t{$partialResult}\t{$nullPayload}\t0\t\t\t\t\t1\n"
);

file_put_contents($readerScript, <<<'PHP'
<?php
function prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected prepare input');
    }

    $input['history'][] = 'prepare';
    return $input;
}

function finalize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected finalize input');
    }

    $input['history'][] = 'finalize';
    return $input;
}

$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['recovered_from_state']);
var_dump($info['configuration']['logging_configured']);
var_dump($info['configuration']['tool_count'] === 2);
var_dump($info['configuration']['run_history_count']);
var_dump($info['configuration']['active_run_count']);
var_dump($info['configuration']['last_run_id']);
var_dump($info['configuration']['last_run_status']);
var_dump($info['configuration']['registered_tools']);
var_dump(king_pipeline_orchestrator_register_handler('prepare', 'prepare_handler'));
var_dump(king_pipeline_orchestrator_register_handler('finalize', 'finalize_handler'));
$result = king_pipeline_orchestrator_resume_run('run-42');
var_dump($result['text']);
var_dump($result['history']);
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['run_history_count']);
var_dump($info['configuration']['active_run_count']);
var_dump($info['configuration']['last_run_id']);
var_dump($info['configuration']['last_run_status']);
$run = king_pipeline_orchestrator_get_run('run-42');
var_dump($run['status']);
var_dump($run['result']['history']);
var_dump($run['distributed_observability']['completed_step_count']);
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
bool(true)
int(1)
int(1)
string(6) "run-42"
string(7) "running"
array(2) {
  [0]=>
  string(7) "prepare"
  [1]=>
  string(8) "finalize"
}
bool(true)
bool(true)
string(10) "mid-flight"
array(2) {
  [0]=>
  string(7) "prepare"
  [1]=>
  string(8) "finalize"
}
int(1)
int(0)
string(6) "run-42"
string(9) "completed"
string(9) "completed"
array(2) {
  [0]=>
  string(7) "prepare"
  [1]=>
  string(8) "finalize"
}
int(2)
