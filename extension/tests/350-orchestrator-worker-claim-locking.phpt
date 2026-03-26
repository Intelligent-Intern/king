--TEST--
King file-worker claim locking keeps concurrent workers from duplicating one active claimed run
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-claim-lock-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-claim-lock-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-claim-lock-controller-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-claim-lock-worker-');
$gatePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-claim-lock-gate-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);
@unlink($gatePath);

file_put_contents($controllerScript, <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'racy'],
    [['tool' => 'summarizer', 'delay_ms' => 300]],
    ['trace_id' => 'claim-lock']
);
echo $dispatch['run_id'], "\n";
PHP);

file_put_contents($workerScript, <<<'PHP'
<?php
$gatePath = $argv[1];
$deadline = microtime(true) + 5.0;
while (!file_exists($gatePath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "gate-timeout\n");
        exit(2);
    }
    usleep(1000);
}

$work = king_pipeline_orchestrator_worker_run_next();
if ($work === false) {
    echo "false\n";
    return;
}

echo json_encode([
    'run_id' => $work['run_id'],
    'status' => $work['status'],
    'text' => $work['result']['text'] ?? null,
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
$workerCommand = sprintf(
    $baseCommand . ' %s',
    escapeshellarg($workerScript),
    escapeshellarg($gatePath)
);

exec($controllerCommand, $controllerOutput, $controllerStatus);
$runId = trim($controllerOutput[0] ?? '');
var_dump($controllerStatus);
var_dump(preg_match('/^run-\d+$/', $runId) === 1);

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$workerA = proc_open($workerCommand, $descriptors, $pipesA);
$workerB = proc_open($workerCommand, $descriptors, $pipesB);

usleep(50000);
touch($gatePath);

$stdoutA = stream_get_contents($pipesA[1]);
$stderrA = stream_get_contents($pipesA[2]);
fclose($pipesA[1]);
fclose($pipesA[2]);
$statusA = proc_close($workerA);

$stdoutB = stream_get_contents($pipesB[1]);
$stderrB = stream_get_contents($pipesB[2]);
fclose($pipesB[1]);
fclose($pipesB[2]);
$statusB = proc_close($workerB);

$outputs = [trim($stdoutA), trim($stdoutB)];
$jsonOutputs = [];
$falseCount = 0;
foreach ($outputs as $output) {
    if ($output === 'false') {
        $falseCount++;
        continue;
    }
    if ($output !== '') {
        $jsonOutputs[] = json_decode($output, true);
    }
}

var_dump($statusA);
var_dump($statusB);
var_dump(trim($stderrA) === '');
var_dump(trim($stderrB) === '');
var_dump(count($jsonOutputs) === 1);
var_dump($falseCount === 1);
var_dump($jsonOutputs[0]['run_id'] === $runId);
var_dump($jsonOutputs[0]['status'] === 'completed');
var_dump($jsonOutputs[0]['text'] === 'racy');

@unlink($controllerScript);
@unlink($workerScript);
@unlink($statePath);
@unlink($gatePath);
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
int(0)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
