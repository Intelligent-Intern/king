--TEST--
King file-worker rejects traversal-tainted run ids before unadmitted-claim requeue
--SKIPIF--
<?php
if (!function_exists('proc_open')) {
    echo "skip proc_open is required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$queuePath = sys_get_temp_dir() . '/king-orchestrator-runid-hardening-queue-' . getmypid();
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-runid-hardening-state-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-runid-hardening-worker-');
$outsideBase = 'king-orchestrator-runid-hardening-' . getmypid();
$outsidePath = dirname($queuePath) . '/' . $outsideBase . '.job';

@unlink($statePath);
@unlink($outsidePath);
@mkdir($queuePath, 0700, true);
@mkdir($queuePath . '/queued-', 0700, true);

$maliciousRunId = '/../../' . $outsideBase;
file_put_contents($queuePath . '/queued-run-1.job', $maliciousRunId . "\n");

file_put_contents($workerScript, <<<'PHP'
<?php
$queuePath = getenv('KING_TEST_QUEUE_PATH') ?: '';
$payload = [
    'init' => king_system_init(['component_timeout_seconds' => 1]),
    'restart' => king_system_restart_component('telemetry'),
];

try {
    king_pipeline_orchestrator_worker_run_next();
    $payload['exception_class'] = null;
    $payload['exception_message'] = null;
} catch (Throwable $e) {
    $payload['exception_class'] = get_class($e);
    $payload['exception_message'] = $e->getMessage();
}

$queuedFiles = glob($queuePath . '/queued-*.job');
$claimedFiles = glob($queuePath . '/claimed-*.job');

$payload['queued_files'] = is_array($queuedFiles) ? count($queuedFiles) : 0;
$payload['claimed_files'] = is_array($claimedFiles) ? count($claimedFiles) : 0;

echo json_encode($payload), "\n";
PHP);

$command = sprintf(
    '%s -n -d %s -d %s -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_execution_backend=file_worker'),
    escapeshellarg('king.orchestrator_worker_queue_path=' . $queuePath),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    escapeshellarg($workerScript)
);

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$environment = $_ENV;
$environment['KING_TEST_QUEUE_PATH'] = $queuePath;

$process = proc_open($command, $descriptors, $pipes, null, $environment);
if (!is_resource($process)) {
    throw new RuntimeException('failed to launch worker hardening probe process');
}

$stdout = trim((string) stream_get_contents($pipes[1]));
$stderr = trim((string) stream_get_contents($pipes[2]));
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);

$payload = json_decode($stdout, true);

var_dump($status);
var_dump(is_array($payload));
var_dump(($payload['init'] ?? null) === true);
var_dump(($payload['restart'] ?? null) === true);
var_dump(($payload['exception_class'] ?? null) === 'King\\RuntimeException');
var_dump(str_contains((string) ($payload['exception_message'] ?? ''), 'could not claim a queued run.'));
var_dump(($payload['queued_files'] ?? null) <= 1);
var_dump(($payload['claimed_files'] ?? null) === 0);
var_dump(file_exists($outsidePath) === false);
var_dump($stderr === '');

@unlink($workerScript);
@unlink($statePath);
@unlink($outsidePath);
if (is_dir($queuePath)) {
    foreach (scandir($queuePath) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $queuePath . '/' . $entry;
        if (is_dir($path)) {
            foreach (scandir($path) as $subEntry) {
                if ($subEntry === '.' || $subEntry === '..') {
                    continue;
                }
                @unlink($path . '/' . $subEntry);
            }
            @rmdir($path);
            continue;
        }

        @unlink($path);
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
