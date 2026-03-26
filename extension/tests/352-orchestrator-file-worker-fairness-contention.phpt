--TEST--
King file-worker queue stays fair under sustained contention and parallel workers
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-fairness-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-fairness-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$bootstrapScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-fairness-bootstrap-');
$dispatchScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-fairness-dispatch-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-fairness-worker-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($bootstrapScript, <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);

$runIds = [];
foreach (['one', 'two', 'three', 'four'] as $text) {
    $dispatch = king_pipeline_orchestrator_dispatch(
        ['text' => $text],
        [['tool' => 'summarizer', 'delay_ms' => 1800]],
        ['trace_id' => 'fairness-initial-' . $text]
    );
    $runIds[] = $dispatch['run_id'];
}

echo json_encode($runIds), "\n";
PHP);

file_put_contents($dispatchScript, <<<'PHP'
<?php
$delayMs = (int) ($argv[1] ?? 0);
$tracePrefix = (string) ($argv[2] ?? 'fairness');
$texts = array_slice($argv, 3);
$runIds = [];

foreach ($texts as $text) {
    $step = ['tool' => 'summarizer'];
    if ($delayMs > 0) {
        $step['delay_ms'] = $delayMs;
    }

    $dispatch = king_pipeline_orchestrator_dispatch(
        ['text' => $text],
        [$step],
        ['trace_id' => $tracePrefix . '-' . $text]
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

echo json_encode([
    'run_id' => $work['run_id'],
    'status' => $work['status'],
    'text' => $work['result']['text'] ?? null,
]), "\n";
PHP);

function king_wait_for_queue_layout(string $queuePath, int $expectedClaimed, int $expectedQueued, int $timeoutMs): bool
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    do {
        $claimed = glob($queuePath . '/claimed-*.job');
        $queued = glob($queuePath . '/queued-*.job');
        if (
            is_array($claimed)
            && is_array($queued)
            && count($claimed) === $expectedClaimed
            && count($queued) === $expectedQueued
        ) {
            return true;
        }

        usleep(10000);
    } while (microtime(true) < $deadline);

    return false;
}

function king_build_orchestrator_command(
    string $phpBinary,
    string $extensionPath,
    string $queuePath,
    string $statePath,
    string $scriptPath,
    array $args = []
): string {
    $parts = [
        escapeshellarg($phpBinary),
        '-n',
        '-d', escapeshellarg('extension=' . $extensionPath),
        '-d', escapeshellarg('king.security_allow_config_override=1'),
        '-d', escapeshellarg('king.orchestrator_execution_backend=file_worker'),
        '-d', escapeshellarg('king.orchestrator_worker_queue_path=' . $queuePath),
        '-d', escapeshellarg('king.orchestrator_state_path=' . $statePath),
        escapeshellarg($scriptPath),
    ];

    foreach ($args as $arg) {
        $parts[] = escapeshellarg((string) $arg);
    }

    return implode(' ', $parts);
}

function king_decode_worker_output(string $stdout): ?array
{
    $trimmed = trim($stdout);
    if ($trimmed === '' || $trimmed === 'false') {
        return null;
    }

    $decoded = json_decode($trimmed, true);
    return is_array($decoded) ? $decoded : null;
}

$bootstrapCommand = king_build_orchestrator_command(
    PHP_BINARY,
    $extensionPath,
    $queuePath,
    $statePath,
    $bootstrapScript
);
$lateDispatchCommand = king_build_orchestrator_command(
    PHP_BINARY,
    $extensionPath,
    $queuePath,
    $statePath,
    $dispatchScript,
    [0, 'fairness-late', 'five', 'six']
);
$workerCommand = king_build_orchestrator_command(
    PHP_BINARY,
    $extensionPath,
    $queuePath,
    $statePath,
    $workerScript
);

exec($bootstrapCommand, $bootstrapOutput, $bootstrapStatus);
$initialRunIds = json_decode(trim($bootstrapOutput[count($bootstrapOutput) - 1] ?? ''), true);
var_dump($bootstrapStatus);
var_dump(is_array($initialRunIds));
var_dump(count($initialRunIds) === 4);

[$run1, $run2, $run3, $run4] = $initialRunIds;

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$workerA = proc_open($workerCommand, $descriptors, $pipesA);
$workerB = proc_open($workerCommand, $descriptors, $pipesB);

var_dump(king_wait_for_queue_layout($queuePath, 2, 2, 5000));

exec($lateDispatchCommand, $lateDispatchOutput, $lateDispatchStatus);
$lateRunIds = json_decode(trim($lateDispatchOutput[count($lateDispatchOutput) - 1] ?? ''), true);
var_dump($lateDispatchStatus);
var_dump(is_array($lateRunIds));
var_dump(count($lateRunIds) === 2);

[$run5, $run6] = $lateRunIds;

var_dump(king_wait_for_queue_layout($queuePath, 2, 4, 5000));

$workerC = proc_open($workerCommand, $descriptors, $pipesC);
$workerD = proc_open($workerCommand, $descriptors, $pipesD);

var_dump(king_wait_for_queue_layout($queuePath, 4, 2, 5000));

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

$stdoutC = stream_get_contents($pipesC[1]);
$stderrC = stream_get_contents($pipesC[2]);
fclose($pipesC[1]);
fclose($pipesC[2]);
$statusC = proc_close($workerC);

$stdoutD = stream_get_contents($pipesD[1]);
$stderrD = stream_get_contents($pipesD[2]);
fclose($pipesD[1]);
fclose($pipesD[2]);
$statusD = proc_close($workerD);

$payloadA = king_decode_worker_output($stdoutA);
$payloadB = king_decode_worker_output($stdoutB);
$payloadC = king_decode_worker_output($stdoutC);
$payloadD = king_decode_worker_output($stdoutD);

var_dump($statusA);
var_dump($statusB);
var_dump($statusC);
var_dump($statusD);
var_dump(trim($stderrA) === '');
var_dump(trim($stderrB) === '');
var_dump(trim($stderrC) === '');
var_dump(trim($stderrD) === '');
var_dump(is_array($payloadA));
var_dump(is_array($payloadB));
var_dump(is_array($payloadC));
var_dump(is_array($payloadD));

$firstWave = [$payloadA['run_id'], $payloadB['run_id']];
sort($firstWave);
$expectedFirstWave = [$run1, $run2];
sort($expectedFirstWave);
var_dump($firstWave === $expectedFirstWave);

$firstWaveTexts = [$payloadA['text'], $payloadB['text']];
sort($firstWaveTexts);
var_dump($firstWaveTexts === ['one', 'two']);
var_dump($payloadA['status'] === 'completed');
var_dump($payloadB['status'] === 'completed');

$secondWave = [$payloadC['run_id'], $payloadD['run_id']];
sort($secondWave);
$expectedSecondWave = [$run3, $run4];
sort($expectedSecondWave);
var_dump($secondWave === $expectedSecondWave);

$secondWaveTexts = [$payloadC['text'], $payloadD['text']];
sort($secondWaveTexts);
var_dump($secondWaveTexts === ['four', 'three']);
var_dump($payloadC['status'] === 'completed');
var_dump($payloadD['status'] === 'completed');

$workerEOutput = [];
$workerEStatus = -1;
exec($workerCommand, $workerEOutput, $workerEStatus);
$payloadE = json_decode(trim($workerEOutput[0] ?? ''), true);
var_dump($workerEStatus);
var_dump(($payloadE['run_id'] ?? null) === $run5);
var_dump(($payloadE['status'] ?? null) === 'completed');
var_dump(($payloadE['text'] ?? null) === 'five');

$workerFOutput = [];
$workerFStatus = -1;
exec($workerCommand, $workerFOutput, $workerFStatus);
$payloadF = json_decode(trim($workerFOutput[0] ?? ''), true);
var_dump($workerFStatus);
var_dump(($payloadF['run_id'] ?? null) === $run6);
var_dump(($payloadF['status'] ?? null) === 'completed');
var_dump(($payloadF['text'] ?? null) === 'six');

$emptyOutput = [];
$emptyStatus = -1;
exec($workerCommand, $emptyOutput, $emptyStatus);
var_dump($emptyStatus);
var_dump(trim($emptyOutput[0] ?? '') === 'false');
var_dump(count(glob($queuePath . '/claimed-*.job')) === 0);
var_dump(count(glob($queuePath . '/queued-*.job')) === 0);

foreach ([
    $bootstrapScript,
    $dispatchScript,
    $workerScript,
    $statePath,
] as $path) {
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
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
int(0)
int(0)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
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
int(0)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
