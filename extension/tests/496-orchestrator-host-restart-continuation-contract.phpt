--TEST--
King pipeline orchestrator can continue a remote-peer run after controller and remote host restart
--SKIPIF--
<?php
require __DIR__ . '/skipif_capability.inc';

king_skipif_require_functions([
    'proc_open',
    'stream_socket_server',
    'king_pipeline_orchestrator_register_tool',
    'king_pipeline_orchestrator_run',
    'king_pipeline_orchestrator_resume_run',
    'king_system_get_component_info',
]);
king_skipif_require_loopback_bind('tcp');
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_remote_peer_helper.inc';

$server = king_orchestrator_remote_peer_start();
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-host-restart-state-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-host-restart-controller-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-host-restart-observer-');
$resumeScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-host-restart-resume-');

@unlink($statePath);

file_put_contents($controllerScript, <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_run(
    ['text' => 'host-restart'],
    [['tool' => 'summarizer', 'delay_ms' => 2000]],
    ['trace_id' => 'host-restart-run']
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
var_dump($info['configuration']['recovered_from_state']);
var_dump($info['configuration']['execution_backend']);
var_dump($info['configuration']['topology_scope']);
$result = king_pipeline_orchestrator_resume_run('run-1');
var_dump($result['text']);
$run = king_pipeline_orchestrator_get_run('run-1');
var_dump($run['run_id']);
var_dump($run['status']);
var_dump($run['finished_at'] > 0);
var_dump($run['result']['text']);
var_dump($run['error']);
$info = king_system_get_component_info('pipeline_orchestrator');
var_dump($info['configuration']['run_history_count']);
var_dump($info['configuration']['active_run_count']);
var_dump($info['configuration']['last_run_id']);
var_dump($info['configuration']['last_run_status']);
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
$remoteAttemptObserved = false;
for ($i = 0; $i < 400; $i++) {
    $observerOutput = [];
    $observerStatus = -1;
    exec($observerCommand, $observerOutput, $observerStatus);
    $snapshot = json_decode(trim($observerOutput[0] ?? ''), true);
    if (
        !$runningObserved
        && $observerStatus === 0
        && is_array($snapshot)
        && ($snapshot['run_id'] ?? null) === 'run-1'
        && ($snapshot['status'] ?? null) === 'running'
        && ($snapshot['finished_at'] ?? null) === 0
        && ($snapshot['error'] ?? null) === null
    ) {
        $runningObserved = true;
    }

    if (!$remoteAttemptObserved && is_file($server['capture'])) {
        $serverCapture = json_decode((string) file_get_contents($server['capture']), true);
        if (
            is_array($serverCapture)
            && isset($serverCapture['events'][0]['run_id'])
            && $serverCapture['events'][0]['run_id'] === 'run-1'
        ) {
            $remoteAttemptObserved = true;
        }
    }

    if ($runningObserved && $remoteAttemptObserved) {
        break;
    }

    usleep(10000);
}

var_dump($runningObserved);
var_dump($remoteAttemptObserved);

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

$serverStatusInfo = proc_get_status($server['process']);
$serverPid = (int) ($serverStatusInfo['pid'] ?? 0);
$serverKillStatus = -1;
exec('/bin/kill -9 ' . $serverPid, $serverKillOutput, $serverKillStatus);
var_dump($serverKillStatus);

$firstCapture = king_orchestrator_remote_peer_finalize($server);
var_dump(count($firstCapture['events'] ?? []));
var_dump(($firstCapture['events'][0]['run_id'] ?? null) === 'run-1');
var_dump(($firstCapture['events'][0]['options']['trace_id'] ?? null) === 'host-restart-run');

$restartedServer = king_orchestrator_remote_peer_start($server['port'], $server['host']);

$observerAfterRestartOutput = [];
$observerAfterRestartStatus = -1;
exec($observerCommand, $observerAfterRestartOutput, $observerAfterRestartStatus);
$afterRestart = json_decode(trim($observerAfterRestartOutput[0] ?? ''), true);
var_dump($observerAfterRestartStatus);
var_dump(($afterRestart['run_id'] ?? null) === 'run-1');
var_dump(($afterRestart['status'] ?? null) === 'running');
var_dump(($afterRestart['finished_at'] ?? null) === 0);
var_dump(($afterRestart['error'] ?? null) === null);

$resumeOutput = [];
$resumeStatus = -1;
exec($resumeCommand, $resumeOutput, $resumeStatus);
var_dump($resumeStatus);
echo implode("\n", $resumeOutput), "\n";

$secondCapture = king_orchestrator_remote_peer_stop($restartedServer);
var_dump(count($secondCapture['events']));
var_dump(($secondCapture['events'][0]['run_id'] ?? null) === 'run-1');
var_dump(($secondCapture['events'][0]['options']['trace_id'] ?? null) === 'host-restart-run');

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
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
int(0)
int(1)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
string(11) "remote_peer"
string(28) "tcp_host_port_execution_peer"
string(12) "host-restart"
string(5) "run-1"
string(9) "completed"
bool(true)
string(12) "host-restart"
NULL
int(1)
int(0)
string(5) "run-1"
string(9) "completed"
int(1)
bool(true)
bool(true)
