--TEST--
King pipeline orchestrator rejects remote peer object payloads instead of unserializing network classes
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_remote_peer_helper.inc';

$server = king_orchestrator_remote_peer_start();
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-object-state-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$runnerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-object-runner-');

@unlink($statePath);

file_put_contents($runnerScript, <<<'PHP'
<?php
var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));

try {
    king_pipeline_orchestrator_run(
        ['text' => 'remote-object'],
        [['tool' => 'summarizer', 'remote_object_result' => true]],
        ['trace_id' => 'remote-object']
    );
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
PHP);

$command = sprintf(
    '%s -n -d %s -d %s -d %s -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_execution_backend=remote_peer'),
    escapeshellarg('king.orchestrator_remote_host=' . $server['host']),
    escapeshellarg('king.orchestrator_remote_port=' . $server['port']),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    escapeshellarg($runnerScript)
);

exec($command, $output, $status);
var_dump($status);
echo implode("\n", $output), "\n";

$capture = king_orchestrator_remote_peer_stop($server);
var_dump(count($capture['events']));
var_dump($capture['events'][0]['pipeline'][0]['remote_object_result']);

@unlink($runnerScript);
@unlink($statePath);
?>
--EXPECT--
int(0)
bool(true)
string(21) "King\RuntimeException"
string(92) "king_pipeline_orchestrator_run() received an invalid serialized result from the remote peer."
int(1)
bool(true)
