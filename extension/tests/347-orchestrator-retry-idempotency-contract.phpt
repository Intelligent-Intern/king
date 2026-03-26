--TEST--
King file-worker orchestrator exposes single-attempt retry semantics and caller-managed idempotency
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-retry-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-retry-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-retry-controller-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-retry-worker-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($controllerScript, <<<'PHP'
<?php
var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));
$first = king_pipeline_orchestrator_dispatch(
    ['text' => 'dup'],
    [['tool' => 'summarizer']],
    ['trace_id' => 'dup-trace']
);
$second = king_pipeline_orchestrator_dispatch(
    ['text' => 'dup'],
    [['tool' => 'summarizer']],
    ['trace_id' => 'dup-trace']
);
$failed = king_pipeline_orchestrator_dispatch(
    ['text' => 'bad'],
    [['tool' => 'missing-tool']],
    ['trace_id' => 'dup-trace']
);
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['retry_policy']);
var_dump($info['configuration']['idempotency_policy']);
var_dump($first['status']);
var_dump($second['status']);
var_dump($failed['status']);
var_dump(
    count(array_unique([$first['run_id'], $second['run_id'], $failed['run_id']])) === 3
);
var_dump($info['configuration']['queued_run_count']);
echo $first['run_id'], "\n", $second['run_id'], "\n", $failed['run_id'], "\n";
PHP);

file_put_contents($workerScript, <<<'PHP'
<?php
$firstRunId = $argv[1];
$secondRunId = $argv[2];
$failedRunId = $argv[3];
$seen = [
    'completed' => 0,
    'failed' => 0,
];

for ($i = 0; $i < 3; $i++) {
    try {
        $work = king_pipeline_orchestrator_worker_run_next();
        $seen[$work['status']]++;
    } catch (Throwable $e) {
        $seen['failed']++;
    }
}

ksort($seen);
var_dump($seen);
var_dump(king_pipeline_orchestrator_worker_run_next());

$first = king_pipeline_orchestrator_get_run($firstRunId);
$second = king_pipeline_orchestrator_get_run($secondRunId);
$failed = king_pipeline_orchestrator_get_run($failedRunId);
$info = king_system_get_component_info('pipeline_orchestrator');

var_dump($first['status']);
var_dump($first['result']['text']);
var_dump($second['status']);
var_dump($second['result']['text']);
var_dump($failed['status']);
var_dump(str_contains($failed['error'], "references unknown tool 'missing-tool'."));
var_dump($info['configuration']['queued_run_count']);
PHP);

$baseCommand = sprintf(
    '%s -n -d %s -d %s -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_execution_backend=file_worker'),
    escapeshellarg('king.orchestrator_worker_queue_path=' . $queuePath),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    '%s'
);

$controllerCommand = sprintf($baseCommand, escapeshellarg($controllerScript));
exec($controllerCommand, $controllerOutput, $controllerStatus);
var_dump($controllerStatus);
echo implode("\n", $controllerOutput), "\n";

$runIds = array_slice($controllerOutput, -3);
$workerCommand = sprintf(
    $baseCommand . ' %s %s %s',
    escapeshellarg($workerScript),
    escapeshellarg($runIds[0] ?? ''),
    escapeshellarg($runIds[1] ?? ''),
    escapeshellarg($runIds[2] ?? '')
);
exec($workerCommand, $workerOutput, $workerStatus);
var_dump($workerStatus);
echo implode("\n", $workerOutput), "\n";

foreach ([$controllerScript, $workerScript, $statePath] as $path) {
    @unlink($path);
}
if (is_dir($queuePath)) {
    foreach (scandir($queuePath) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            @unlink($queuePath . '/' . $entry);
        }
    }
    @rmdir($queuePath);
}
?>
--EXPECT--
int(0)
bool(true)
string(14) "single_attempt"
string(14) "caller_managed"
string(6) "queued"
string(6) "queued"
string(6) "queued"
bool(true)
int(3)
run-1
run-2
run-3
int(0)
array(2) {
  ["completed"]=>
  int(2)
  ["failed"]=>
  int(1)
}
bool(false)
string(9) "completed"
string(3) "dup"
string(9) "completed"
string(3) "dup"
string(6) "failed"
bool(true)
int(0)
