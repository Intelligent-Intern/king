--TEST--
King pipeline orchestrator enforces cancel, deadline, and max_concurrency runtime controls
--INI--
king.security_allow_config_override=1
--FILE--
<?php
var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));

$cancelled = new King\CancelToken();
$cancelled->cancel();

try {
    king_pipeline_orchestrator_run(
        ['text' => 'cancelled'],
        [['tool' => 'summarizer']],
        ['cancel' => $cancelled]
    );
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    king_pipeline_orchestrator_run(
        ['text' => 'expired'],
        [['tool' => 'summarizer']],
        ['deadline_ms' => 1]
    );
    echo "no-exception-2\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-controls-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-controls-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-controls-controller-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($controllerScript, <<<'PHP'
<?php
var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));

$first = king_pipeline_orchestrator_dispatch(
    ['text' => 'queued'],
    [['tool' => 'summarizer']],
    ['max_concurrency' => 1]
);
var_dump($first['status']);

try {
    king_pipeline_orchestrator_dispatch(
        ['text' => 'blocked'],
        [['tool' => 'summarizer']],
        ['max_concurrency' => 1]
    );
    echo "no-exception-3\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
PHP);

$command = sprintf(
    '%s -n -d %s -d %s -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_execution_backend=file_worker'),
    escapeshellarg('king.orchestrator_worker_queue_path=' . $queuePath),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    escapeshellarg($controllerScript)
);

exec($command, $output, $status);
var_dump($status);
echo implode("\n", $output), "\n";

@unlink($controllerScript);
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
--EXPECTF--
bool(true)
string(21) "King\RuntimeException"
string(%d) "king_pipeline_orchestrator_run() cancelled the active orchestrator run via CancelToken."
string(21) "King\TimeoutException"
string(%d) "king_pipeline_orchestrator_run() exceeded the active orchestrator deadline budget."
int(0)
bool(true)
string(6) "queued"
string(21) "King\RuntimeException"
string(%d) "king_pipeline_orchestrator_dispatch() cannot exceed the active orchestrator max_concurrency of 1 while 1 run(s) are already in flight."
