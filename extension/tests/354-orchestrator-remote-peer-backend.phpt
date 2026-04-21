--TEST--
King pipeline orchestrator can execute runs over a remote TCP worker peer boundary
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
if (!function_exists('pcntl_signal')) {
    echo "skip pcntl is required for remote peer signal handling";
}
if (!extension_loaded('king')) {
    echo "skip king extension is required";
}
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_remote_peer_helper.inc';

$server = king_orchestrator_remote_peer_start();
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-peer-state-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$runnerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-peer-runner-');

@unlink($statePath);

file_put_contents($runnerScript, <<<'PHP'
<?php
$expectedHost = $argv[1];
$expectedPort = (int) $argv[2];

var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));

$result = king_pipeline_orchestrator_run(
    ['text' => 'remote-peer'],
    [['tool' => 'summarizer', 'delay_ms' => 20]],
    ['trace_id' => 'remote-run']
);
var_dump($result['text']);

$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['execution_backend']);
var_dump($info['configuration']['topology_scope']);
var_dump($info['configuration']['remote_host'] === $expectedHost);
var_dump($info['configuration']['remote_port'] === $expectedPort);
var_dump($info['configuration']['scheduler_policy']);

$run = king_pipeline_orchestrator_get_run($info['configuration']['last_run_id']);
var_dump($run['status']);
var_dump($run['result']['text']);
PHP);

$command = sprintf(
    '%s -n -d %s -d %s -d %s -d %s -d %s -d %s %s %s %d',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_execution_backend=remote_peer'),
    escapeshellarg('king.orchestrator_remote_host=' . $server['host']),
    escapeshellarg('king.orchestrator_remote_port=' . $server['port']),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    escapeshellarg($runnerScript),
    escapeshellarg($server['host']),
    $server['port']
);

exec($command, $output, $status);
var_dump($status);
echo implode("\n", $output), "\n";

$capture = king_orchestrator_remote_peer_stop($server);
var_dump(count($capture['events']));
var_dump(preg_match('/^run-\d+$/', $capture['events'][0]['run_id']) === 1);
var_dump($capture['events'][0]['initial_data']['text']);
var_dump($capture['events'][0]['options']['trace_id']);

@unlink($runnerScript);
@unlink($statePath);
?>
--EXPECT--
int(0)
bool(true)
string(11) "remote-peer"
string(11) "remote_peer"
string(28) "tcp_host_port_execution_peer"
bool(true)
bool(true)
string(28) "controller_direct_remote_run"
string(9) "completed"
string(11) "remote-peer"
int(1)
bool(true)
string(11) "remote-peer"
string(10) "remote-run"
