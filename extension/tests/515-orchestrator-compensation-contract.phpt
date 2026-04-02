--TEST--
King pipeline orchestrator exposes explicit caller-managed compensation snapshots for failed multi-step runs
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-compensation-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-compensation-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-compensation-controller-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-compensation-worker-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($controllerScript, <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);

$failed = king_pipeline_orchestrator_dispatch(
    ['text' => 'needs-compensation'],
    [
        ['tool' => 'summarizer'],
        ['tool' => 'missing-tool'],
    ],
    ['trace_id' => 'compensation-failed']
);
$completed = king_pipeline_orchestrator_dispatch(
    ['text' => 'already-good'],
    [
        ['tool' => 'summarizer'],
    ],
    ['trace_id' => 'compensation-completed']
);
$queued = king_pipeline_orchestrator_get_run($failed['run_id']);
$info = king_system_get_component_info('pipeline_orchestrator');

echo json_encode([
    'failed_run_id' => $failed['run_id'],
    'completed_run_id' => $completed['run_id'],
    'queued' => $queued,
    'configuration' => $info['configuration'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP);

file_put_contents($workerScript, <<<'PHP'
<?php
$failedRunId = $argv[1];
$completedRunId = $argv[2];

$firstException = null;
try {
    $first = king_pipeline_orchestrator_worker_run_next();
} catch (Throwable $e) {
    $firstException = [
        'class' => get_class($e),
        'message' => $e->getMessage(),
    ];
    $first = king_pipeline_orchestrator_get_run($failedRunId);
}
$second = king_pipeline_orchestrator_worker_run_next();
$failed = king_pipeline_orchestrator_get_run($failedRunId);
$completed = king_pipeline_orchestrator_get_run($completedRunId);
$info = king_system_get_component_info('pipeline_orchestrator');

echo json_encode([
    'first_exception' => $firstException,
    'first' => $first,
    'second' => $second,
    'failed' => $failed,
    'completed' => $completed,
    'configuration' => $info['configuration'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
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
$controller = json_decode(trim(implode("\n", $controllerOutput)), true);
var_dump(is_array($controller));
var_dump(($controller['configuration']['compensation_policy'] ?? null) === 'caller_managed');
var_dump(($controller['queued']['compensation_policy'] ?? null) === 'caller_managed');
var_dump(($controller['queued']['compensation']['policy'] ?? null) === 'caller_managed');
var_dump(($controller['queued']['compensation']['strategy'] ?? null) === 'reverse_completed_steps');
var_dump(($controller['queued']['compensation']['required'] ?? null) === false);
var_dump(($controller['queued']['compensation']['pending_step_count'] ?? null) === 0);
var_dump(($controller['queued']['compensation']['pending_steps'] ?? null) === []);
var_dump(($controller['queued']['steps'][0]['compensation_status'] ?? null) === 'not_applicable');
var_dump(($controller['queued']['steps'][1]['compensation_status'] ?? null) === 'not_applicable');

$workerCommand = sprintf(
    $baseCommand . ' %s %s',
    escapeshellarg($workerScript),
    escapeshellarg($controller['failed_run_id'] ?? ''),
    escapeshellarg($controller['completed_run_id'] ?? '')
);
exec($workerCommand, $workerOutput, $workerStatus);
var_dump($workerStatus);
$worker = json_decode(trim(implode("\n", $workerOutput)), true);
var_dump(is_array($worker));
var_dump(($worker['configuration']['compensation_policy'] ?? null) === 'caller_managed');
var_dump(($worker['first_exception']['class'] ?? null) === 'King\\RuntimeException');
var_dump(str_contains((string) ($worker['first_exception']['message'] ?? ''), "unknown tool 'missing-tool'."));
var_dump(($worker['first']['run_id'] ?? null) === ($controller['failed_run_id'] ?? null));
var_dump(($worker['first']['status'] ?? null) === 'failed');
var_dump(($worker['second']['run_id'] ?? null) === ($controller['completed_run_id'] ?? null));
var_dump(($worker['second']['status'] ?? null) === 'completed');
var_dump(($worker['failed']['retry_policy'] ?? null) === 'single_attempt');
var_dump(($worker['failed']['idempotency_policy'] ?? null) === 'caller_managed');
var_dump(($worker['failed']['compensation_policy'] ?? null) === 'caller_managed');
var_dump(($worker['failed']['compensation']['policy'] ?? null) === 'caller_managed');
var_dump(($worker['failed']['compensation']['strategy'] ?? null) === 'reverse_completed_steps');
var_dump(($worker['failed']['compensation']['required'] ?? null) === true);
var_dump(($worker['failed']['compensation']['trigger'] ?? null) === 'failed');
var_dump(($worker['failed']['compensation']['pending_step_count'] ?? null) === 1);
var_dump(count($worker['failed']['compensation']['pending_steps'] ?? []) === 1);
var_dump(($worker['failed']['compensation']['pending_steps'][0]['index'] ?? null) === 0);
var_dump(($worker['failed']['compensation']['pending_steps'][0]['tool'] ?? null) === 'summarizer');
var_dump(($worker['failed']['compensation']['pending_steps'][0]['status'] ?? null) === 'pending');
var_dump(($worker['failed']['steps'][0]['status'] ?? null) === 'completed');
var_dump(($worker['failed']['steps'][0]['compensation_status'] ?? null) === 'pending');
var_dump(($worker['failed']['steps'][1]['status'] ?? null) === 'failed');
var_dump(($worker['failed']['steps'][1]['compensation_status'] ?? null) === 'not_applicable');
var_dump(($worker['completed']['status'] ?? null) === 'completed');
var_dump(($worker['completed']['compensation']['required'] ?? null) === false);
var_dump(($worker['completed']['compensation']['pending_step_count'] ?? null) === 0);
var_dump(($worker['completed']['steps'][0]['compensation_status'] ?? null) === 'not_required');

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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
