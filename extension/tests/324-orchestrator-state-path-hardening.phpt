--TEST--
King orchestrator state path stays system-owned and refuses symlinked state files
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
$privateDir = sys_get_temp_dir() . '/king-orchestrator-state-hardening-' . getmypid();
$stateTarget = $privateDir . '/state-target.bin';
$stateSymlink = $privateDir . '/state-link.bin';
$writerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-state-writer-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';

@mkdir($privateDir, 0700, true);
file_put_contents($stateTarget, 'sentinel-before');
var_dump(symlink($stateTarget, $stateSymlink));

try {
    king_new_config([
        'orchestrator.state_path' => $stateSymlink,
    ]);
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

file_put_contents($writerScript, <<<'PHP'
<?php
try {
    king_pipeline_orchestrator_register_tool('summarizer', [
        'model' => 'gpt-sim',
        'max_tokens' => 64,
    ]);
    echo "no-exception-2\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
PHP);

$command = sprintf(
    '%s -n -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_state_path=' . $stateSymlink),
    escapeshellarg($writerScript)
);

exec($command, $output, $status);
var_dump($status);
echo implode("\n", $output), "\n";

var_dump(file_get_contents($stateTarget));
var_dump(is_link($stateSymlink));

@unlink($writerScript);
@unlink($stateSymlink);
@unlink($stateTarget);
@rmdir($privateDir);
?>
--EXPECTF--
bool(true)
string(24) "InvalidArgumentException"
string(%d) "Configuration override 'orchestrator.state_path' is only supported through the system INI king.orchestrator_state_path."
int(0)
string(21) "King\RuntimeException"
string(%d) "king_pipeline_orchestrator_register_tool() failed to persist the tool registry snapshot."
string(15) "sentinel-before"
bool(true)
