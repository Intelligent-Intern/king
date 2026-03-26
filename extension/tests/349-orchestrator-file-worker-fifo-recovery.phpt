--TEST--
King file-worker scheduler recovers claimed runs first and keeps queued work in FIFO run-id order
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-fifo-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-fifo-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-fifo-controller-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-fifo-worker-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($controllerScript, <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);

$runIds = [];
foreach (['first', 'second', 'third'] as $text) {
    $dispatch = king_pipeline_orchestrator_dispatch(
        ['text' => $text],
        [['tool' => 'summarizer']],
        ['trace_id' => 'fifo-' . $text]
    );
    $runIds[] = $dispatch['run_id'];
}

echo json_encode($runIds), "\n";
PHP);

file_put_contents($workerScript, <<<'PHP'
<?php
$work = king_pipeline_orchestrator_worker_run_next();
if ($work === false) {
    echo "false\n";
    return;
}

$info = king_system_get_component_info('pipeline_orchestrator');
echo json_encode([
    'run_id' => $work['run_id'],
    'status' => $work['status'],
    'text' => $work['result']['text'] ?? null,
    'scheduler_policy' => $info['configuration']['scheduler_policy'],
]), "\n";
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
$runIds = json_decode(trim($controllerOutput[count($controllerOutput) - 1] ?? ''), true);
var_dump($controllerStatus);
var_dump(is_array($runIds));
var_dump(count($runIds) === 3);

$run1 = $runIds[0];
$run2 = $runIds[1];
$run3 = $runIds[2];

$queued1 = $queuePath . '/queued-' . $run1 . '.job';
$queued2 = $queuePath . '/queued-' . $run2 . '.job';
$queued3 = $queuePath . '/queued-' . $run3 . '.job';
$claimed1 = $queuePath . '/claimed-9000-queued-' . $run1 . '.job';

var_dump(rename($queued1, $claimed1));

$payload2 = file_get_contents($queued2);
$payload3 = file_get_contents($queued3);
var_dump($payload2 === $run2 . "\n");
var_dump($payload3 === $run3 . "\n");
@unlink($queued2);
@unlink($queued3);
var_dump(file_put_contents($queued3, $payload3) !== false);
var_dump(file_put_contents($queued2, $payload2) !== false);

$workerRuns = [];
for ($i = 0; $i < 3; $i++) {
    $workerOutput = [];
    $workerStatus = -1;
    exec($workerCommand, $workerOutput, $workerStatus);
    $workerRuns[] = [
        'status' => $workerStatus,
        'payload' => json_decode(trim($workerOutput[0] ?? ''), true),
    ];
}

foreach ($workerRuns as $index => $workerRun) {
    var_dump($workerRun['status']);
    var_dump($workerRun['payload']['run_id'] === $runIds[$index]);
    var_dump($workerRun['payload']['status'] === 'completed');
    var_dump($workerRun['payload']['text'] === ['first', 'second', 'third'][$index]);
    var_dump($workerRun['payload']['scheduler_policy'] === 'claimed_recovery_then_fifo_run_id');
}

$emptyOutput = [];
$emptyStatus = -1;
exec($workerCommand, $emptyOutput, $emptyStatus);
var_dump($emptyStatus);
var_dump(trim($emptyOutput[0] ?? '') === 'false');

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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
