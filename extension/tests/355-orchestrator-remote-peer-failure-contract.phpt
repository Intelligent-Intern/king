--TEST--
King pipeline orchestrator records failed runs when a remote TCP worker peer returns an execution error
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
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-failure-state-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$runnerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-failure-runner-');

@unlink($statePath);

file_put_contents($runnerScript, <<<'PHP'
<?php
var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));

try {
    king_pipeline_orchestrator_run(
        ['text' => 'remote-fail'],
        [['tool' => 'summarizer', 'remote_error' => 'forced remote peer failure']],
        ['trace_id' => 'remote-fail']
    );
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

$info = king_system_get_component_info('pipeline_orchestrator');
var_dump(preg_match('/^run-\d+$/', $info['configuration']['last_run_id']) === 1);
var_dump($info['configuration']['last_run_status']);

$run = king_pipeline_orchestrator_get_run($info['configuration']['last_run_id']);
var_dump($run['status']);
var_dump($run['error']);
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
var_dump($capture['events'][0]['remote_error']);
var_dump($capture['events'][0]['options']['trace_id']);

@unlink($runnerScript);
@unlink($statePath);
?>
--EXPECT--
int(0)
bool(true)
string(21) "King\RuntimeException"
string(26) "forced remote peer failure"
bool(true)
string(6) "failed"
string(6) "failed"
string(26) "forced remote peer failure"
int(1)
string(26) "forced remote peer failure"
string(11) "remote-fail"
