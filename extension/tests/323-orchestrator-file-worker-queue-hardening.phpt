--TEST--
King file-worker queue rejects unsafe directories and symlinked queued-job targets
--SKIPIF--
<?php
if (!function_exists('symlink')) {
    echo "skip symlink support unavailable";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$unsafeQueuePath = sys_get_temp_dir() . '/king-orchestrator-unsafe-queue-' . getmypid();
$safeQueuePath = sys_get_temp_dir() . '/king-orchestrator-safe-queue-' . getmypid();
$unsafeScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-unsafe-script-');
$symlinkScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-symlink-script-');
$targetPath = tempnam(sys_get_temp_dir(), 'king-orchestrator-queue-target-');

@mkdir($unsafeQueuePath, 0700, true);
@chmod($unsafeQueuePath, 0777);
@mkdir($safeQueuePath, 0700, true);
file_put_contents($targetPath, 'sentinel-before');

if (!symlink($targetPath, $safeQueuePath . '/queued-run-1.job')) {
    die("symlink failed\n");
}

$scriptBody = <<<'PHP'
<?php
var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));

try {
    king_pipeline_orchestrator_dispatch(
        ['text' => 'queued'],
        [['tool' => 'summarizer']]
    );
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
PHP;

file_put_contents($unsafeScript, $scriptBody);
file_put_contents($symlinkScript, $scriptBody);

$baseCommand = sprintf(
    '%s -n -d %s -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_execution_backend=file_worker'),
    '%s',
    '%s'
);

$unsafeCommand = sprintf(
    $baseCommand,
    escapeshellarg('king.orchestrator_worker_queue_path=' . $unsafeQueuePath),
    escapeshellarg($unsafeScript)
);
$symlinkCommand = sprintf(
    $baseCommand,
    escapeshellarg('king.orchestrator_worker_queue_path=' . $safeQueuePath),
    escapeshellarg($symlinkScript)
);

exec($unsafeCommand, $unsafeOutput, $unsafeStatus);
var_dump($unsafeStatus);
echo implode("\n", $unsafeOutput), "\n";

exec($symlinkCommand, $symlinkOutput, $symlinkStatus);
var_dump($symlinkStatus);
echo implode("\n", $symlinkOutput), "\n";

var_dump(file_get_contents($targetPath));
var_dump(is_link($safeQueuePath . '/queued-run-1.job'));

foreach ([$unsafeScript, $symlinkScript, $targetPath] as $path) {
    @unlink($path);
}
foreach ([$unsafeQueuePath, $safeQueuePath] as $path) {
    if (is_dir($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
?>
--EXPECTF--
int(0)
bool(true)
string(21) "King\RuntimeException"
string(%d) "king_pipeline_orchestrator_dispatch() failed to enqueue the run for the file-worker backend."
int(0)
bool(true)
string(21) "King\RuntimeException"
string(%d) "king_pipeline_orchestrator_dispatch() failed to enqueue the run for the file-worker backend."
string(15) "sentinel-before"
bool(true)
