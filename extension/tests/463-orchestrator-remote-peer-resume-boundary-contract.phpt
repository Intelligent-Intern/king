--TEST--
King pipeline orchestrator keeps resumed running runs on the remote peer backend instead of degrading them to local execution
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
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-resume-state-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-resume-controller-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-resume-observer-');
$resumeScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-resume-runner-');

@unlink($statePath);

file_put_contents($controllerScript, <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_run(
    ['text' => 'remote-resume'],
    [[
        'tool' => 'summarizer',
        'delay_ms' => 15000,
        'remote_object_result' => true,
    ]],
    ['trace_id' => 'remote-resume']
);
PHP);

file_put_contents($observerScript, <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run('run-1');
if ($run === false) {
    echo "false\n";
    return;
}

echo json_encode([
    'run_id' => $run['run_id'],
    'status' => $run['status'],
    'finished_at' => $run['finished_at'],
    'error' => $run['error'],
]), "\n";
PHP);

file_put_contents($resumeScript, <<<'PHP'
<?php
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['execution_backend']);
var_dump($info['configuration']['topology_scope']);

try {
    king_pipeline_orchestrator_resume_run('run-1');
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

$run = king_pipeline_orchestrator_get_run('run-1');
var_dump($run['status']);
var_dump($run['error']);
PHP);

$baseCommand = sprintf(
    '%s -n -d %s -d %s -d %s -d %s -d %s -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_execution_backend=remote_peer'),
    escapeshellarg('king.orchestrator_remote_host=' . $server['host']),
    escapeshellarg('king.orchestrator_remote_port=' . $server['port']),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    '%s'
);

$observerCommand = sprintf($baseCommand, escapeshellarg($observerScript));
$resumeCommand = sprintf($baseCommand, escapeshellarg($resumeScript));
$controllerArgv = [
    PHP_BINARY,
    '-n',
    '-d', 'extension=' . $extensionPath,
    '-d', 'king.security_allow_config_override=1',
    '-d', 'king.orchestrator_execution_backend=remote_peer',
    '-d', 'king.orchestrator_remote_host=' . $server['host'],
    '-d', 'king.orchestrator_remote_port=' . $server['port'],
    '-d', 'king.orchestrator_state_path=' . $statePath,
    $controllerScript,
];

$controllerProcess = proc_open($controllerArgv, [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $controllerPipes);

$runningObserved = false;
for ($i = 0; $i < 400; $i++) {
    $observerOutput = [];
    $observerStatus = -1;
    exec($observerCommand, $observerOutput, $observerStatus);
    $snapshot = json_decode(trim($observerOutput[0] ?? ''), true);
    if (
        $observerStatus === 0
        && is_array($snapshot)
        && ($snapshot['run_id'] ?? null) === 'run-1'
        && ($snapshot['status'] ?? null) === 'running'
        && ($snapshot['finished_at'] ?? null) === 0
        && ($snapshot['error'] ?? null) === null
    ) {
        $runningObserved = true;
        break;
    }
    usleep(10000);
}

var_dump($runningObserved);

$controllerStatusInfo = proc_get_status($controllerProcess);
$controllerPid = (int) ($controllerStatusInfo['pid'] ?? 0);
$killStatus = -1;
exec('/bin/kill -9 ' . $controllerPid, $killOutput, $killStatus);
var_dump($killStatus);

$controllerStdout = stream_get_contents($controllerPipes[1]);
$controllerStderr = stream_get_contents($controllerPipes[2]);
fclose($controllerPipes[1]);
fclose($controllerPipes[2]);
$controllerExit = proc_close($controllerProcess);
var_dump($controllerExit !== 0);
var_dump(trim($controllerStdout) === '');
var_dump(trim($controllerStderr) === '');

$resumeOutput = [];
$resumeStatus = -1;
exec($resumeCommand, $resumeOutput, $resumeStatus);
var_dump($resumeStatus);
echo implode("\n", $resumeOutput), "\n";

$capture = king_orchestrator_remote_peer_stop($server);
var_dump(count($capture['events']));
var_dump($capture['events'][0]['run_id']);
var_dump($capture['events'][1]['run_id']);
var_dump($capture['events'][0]['pipeline'][0]['remote_object_result']);
var_dump($capture['events'][1]['pipeline'][0]['remote_object_result']);
var_dump($capture['events'][1]['options']['trace_id']);

foreach ([
    $controllerScript,
    $observerScript,
    $resumeScript,
    $statePath,
] as $path) {
    @unlink($path);
}
?>
--EXPECT--
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
int(0)
string(11) "remote_peer"
string(28) "tcp_host_port_execution_peer"
string(21) "King\RuntimeException"
string(92) "king_pipeline_orchestrator_run() received an invalid serialized result from the remote peer."
string(6) "failed"
string(92) "king_pipeline_orchestrator_run() received an invalid serialized result from the remote peer."
int(2)
string(5) "run-1"
string(5) "run-1"
bool(true)
bool(true)
string(13) "remote-resume"
