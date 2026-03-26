--TEST--
King pipeline orchestrator dispatches and completes runs across the file-worker backend boundary
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-file-worker-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-file-worker-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-controller-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-worker-');

@unlink($statePath);
@mkdir($queuePath, 0777, true);

file_put_contents($controllerScript, <<<'PHP'
<?php
var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 100,
]));
var_dump(king_pipeline_orchestrator_configure_logging(['level' => 'debug']));
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'queued'],
    [['tool' => 'summarizer']],
    ['trace_id' => 'file-worker-1']
);
var_dump($dispatch['backend']);
var_dump($dispatch['status']);
var_dump(preg_match('/^run-\d+$/', $dispatch['run_id']) === 1);
$run = king_pipeline_orchestrator_get_run($dispatch['run_id']);
var_dump($run['status']);
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['execution_backend']);
var_dump($info['configuration']['queued_run_count']);

try {
    king_pipeline_orchestrator_run(['text' => 'blocked'], [['tool' => 'summarizer']]);
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
}
PHP);

file_put_contents($workerScript, <<<'PHP'
<?php
$work = king_pipeline_orchestrator_worker_run_next();
var_dump(preg_match('/^run-\d+$/', $work['run_id']) === 1);
var_dump($work['status']);
var_dump($work['result']['text']);
$run = king_pipeline_orchestrator_get_run($work['run_id']);
var_dump($run['status']);
var_dump($run['result']['text']);
var_dump(king_pipeline_orchestrator_worker_run_next());
$info = king_system_get_component_info('pipeline_orchestrator');
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
$workerCommand = sprintf($baseCommand, escapeshellarg($workerScript));

exec($controllerCommand, $controllerOutput, $controllerStatus);
var_dump($controllerStatus);
echo implode("\n", $controllerOutput), "\n";

exec($workerCommand, $workerOutput, $workerStatus);
var_dump($workerStatus);
echo implode("\n", $workerOutput), "\n";

@unlink($controllerScript);
@unlink($workerScript);
@unlink($statePath);
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
bool(true)
string(11) "file_worker"
string(6) "queued"
bool(true)
string(7) "running"
string(11) "file_worker"
int(1)
string(21) "King\RuntimeException"
int(0)
bool(true)
string(9) "completed"
string(6) "queued"
string(9) "completed"
string(6) "queued"
bool(false)
int(0)
