--TEST--
King remote-peer userland handler boundary rejects unsupported topology shapes and fails closed
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_remote_peer_helper.inc';

function mutate_remote_peer_step_refs_to_invalid(string $statePath, string $runId): bool
{
    $lines = file($statePath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return false;
    }

    $updated = false;
    foreach ($lines as $lineIndex => $line) {
        if (strpos($line, "run\t{$runId}\t") !== 0) {
            continue;
        }

        $parts = explode("\t", $line);
        $fieldCount = count($parts);
        if ($fieldCount < 28) {
            continue;
        }

        if ($fieldCount >= 29) {
            $boundaryEncoded = $parts[28];
            $boundaryPartIndex = 28;
        } elseif ($fieldCount === 28) {
            $boundaryEncoded = $parts[27];
            $boundaryPartIndex = 27;
        } else {
            continue;
        }
        if ($boundaryEncoded === '') {
            continue;
        }

        $boundarySerialized = base64_decode($boundaryEncoded, true);
        if ($boundarySerialized === false) {
            continue;
        }

        $boundary = unserialize($boundarySerialized, ['allowed_classes' => false]);
        if (!is_array($boundary) || !is_array($boundary['required_step_refs'] ?? null)) {
            continue;
        }

        $boundary['required_step_refs'] = [['index' => -1, 'tool_name' => 'summarizer']];
        $parts[$boundaryPartIndex] = base64_encode(serialize($boundary));
        $lines[$lineIndex] = implode("\t", $parts);
        $updated = true;
        break;
    }

    if (!$updated) {
        return false;
    }

    return file_put_contents($statePath, implode(PHP_EOL, $lines) . PHP_EOL) !== false;
}

$extensionPath = dirname(__DIR__) . '/modules/king.so';
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-peer-topology-state-');
$bootstrapScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-peer-topology-bootstrap-');
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-peer-topology-controller-');
$observerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-peer-topology-observer-');
$resumeScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-peer-topology-resume-');

@unlink($statePath);

file_put_contents($bootstrapScript, <<<'BOOTSTRAP'
<?php
function summarize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected remote summarize input');
    }

    $input['history'][] = 'remote-summary';
    return ['output' => $input];
}

return ['summarizer' => 'summarize_handler'];
BOOTSTRAP);

$server = king_orchestrator_remote_peer_start(null, '127.0.0.1', null, [$bootstrapScript]);

file_put_contents($controllerScript, <<<'CONTROLLER'
<?php
function summarize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected controller summarize input');
    }

    $input['history'][] = 'controller-summary';
    return ['output' => $input];
}

king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('summarizer', 'summarize_handler');
king_pipeline_orchestrator_run(
    ['text' => 'remote-peer-topology', 'history' => []],
    [['tool' => 'summarizer', 'delay_ms' => 8000]],
    ['trace_id' => 'remote-peer-topology-failclosed']
);
CONTROLLER);

file_put_contents($observerScript, <<<'OBSERVER'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1] ?? 'run-1');
if ($run === false) {
    echo "false\n";
    return;
}

echo json_encode([
    'run_id' => $run['run_id'] ?? null,
    'status' => $run['status'] ?? null,
    'finished_at' => $run['finished_at'] ?? null,
    'completed_step_count' => $run['completed_step_count'] ?? null,
    'handler_boundary' => $run['handler_boundary'] ?? null,
]), "\n";
OBSERVER);

file_put_contents($resumeScript, <<<'RESUME'
<?php
$runId = $argv[1] ?? 'run-1';
try {
    king_pipeline_orchestrator_resume_run($runId);
    $exception = null;
} catch (Throwable $e) {
    $exception = [
        'resume_exception_class' => get_class($e),
        'resume_exception_message' => $e->getMessage(),
    ];
}

$info = king_system_get_component_info('pipeline_orchestrator');
$run = king_pipeline_orchestrator_get_run($runId);
$classification = $run['error_classification'] ?? null;

echo json_encode([
    'recovered_from_state' => $info['configuration']['recovered_from_state'] ?? null,
    'exception' => $exception,
    'run_status' => $run['status'] ?? null,
    'run_error' => $run['error'] ?? null,
    'error_classification' => is_array($classification) ? $classification : null,
    'handler_boundary' => $run['handler_boundary'] ?? null,
]), "\n";
RESUME);

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

$observerCommand = static function (string $runId) use ($baseCommand, $observerScript): string {
    return sprintf(
        '%s %s',
        sprintf($baseCommand, escapeshellarg($observerScript)),
        escapeshellarg($runId)
    );
};
$resumeCommand = static function (string $runId) use ($baseCommand, $resumeScript): string {
    return sprintf(
        '%s %s',
        sprintf($baseCommand, escapeshellarg($resumeScript)),
        escapeshellarg($runId)
    );
};

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
    0 => ['file', 'php://stdin', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $controllerPipes);

$runningObserved = false;
for ($i = 0; $i < 1200; $i++) {
    $observerOutput = [];
    $observerStatus = -1;
    exec($observerCommand('run-1'), $observerOutput, $observerStatus);
    $snapshot = json_decode(trim($observerOutput[0] ?? ''), true);
    if (($i % 50) === 0 && getenv('KING_597_DEBUG_LOG')) {
        file_put_contents(
            '/tmp/king-597-remote-peer-debug.log',
            json_encode([
                'i' => $i,
                'status' => $observerStatus,
                'run_id' => $snapshot['run_id'] ?? null,
                'status_value' => $snapshot['status'] ?? null,
                'finished_at' => $snapshot['finished_at'] ?? null,
                'completed_step_count' => $snapshot['completed_step_count'] ?? null,
            ]) . "\n",
            FILE_APPEND
        );
    }

    if (
        $observerStatus === 0
        && is_array($snapshot)
        && ($snapshot['run_id'] ?? null) === 'run-1'
        && ($snapshot['status'] ?? null) === 'running'
        && ($snapshot['finished_at'] ?? null) === 0
        && ($snapshot['completed_step_count'] ?? null) === 0
        && ($snapshot['handler_boundary']['required_step_refs'] ?? null) === [['index' => 0, 'tool_name' => 'summarizer']]
    ) {
        $runningObserved = true;
        break;
    }

    usleep(10000);
}

var_dump($runningObserved);

$controllerStatusInfo = proc_get_status($controllerProcess);
$controllerPid = (int) ($controllerStatusInfo['pid'] ?? 0);
stream_set_blocking($controllerPipes[1], false);
stream_set_blocking($controllerPipes[2], false);
$controllerStdout = stream_get_contents($controllerPipes[1]);
$controllerStderr = stream_get_contents($controllerPipes[2]);
fclose($controllerPipes[1]);
fclose($controllerPipes[2]);
$killStatus = -1;
if ($controllerPid > 0) {
    if ($controllerStatusInfo['running'] ?? false) {
        $terminatedGracefully = @proc_terminate($controllerProcess);
        if ($terminatedGracefully) {
            usleep(100000);
            $controllerStatusInfo = proc_get_status($controllerProcess);
        }

        if ($controllerStatusInfo['running'] ?? false) {
            $terminated = function_exists('posix_kill')
                ? (bool) @posix_kill($controllerPid, 9)
                : (bool) @proc_terminate($controllerProcess, 9);
            if ($terminated) {
                usleep(100000);
                $controllerStatusInfo = proc_get_status($controllerProcess);
            }
        }
    } else {
        $killStatus = 0;
    }

    $killStatus = ($controllerStatusInfo['running'] ?? false) ? 1 : 0;
}
var_dump($killStatus === 0);
$controllerExit = proc_close($controllerProcess);
var_dump($controllerExit !== 0);
var_dump(trim($controllerStdout) === '');
var_dump(trim($controllerStderr) === '');

var_dump(mutate_remote_peer_step_refs_to_invalid($statePath, 'run-1'));

$resumeOutput = [];
$resumeStatus = -1;
exec($resumeCommand('run-1'), $resumeOutput, $resumeStatus);
$resumed = json_decode(trim($resumeOutput[0] ?? ''), true);

var_dump($resumeStatus);
var_dump(($resumed['recovered_from_state'] ?? null) === true);
var_dump(is_array($resumed['exception']));
var_dump(($resumed['exception']['resume_exception_class'] ?? null) === 'King\\RuntimeException');
var_dump(
    ($resumed['exception']['resume_exception_message'] ?? null)
    === 'remote peer received an invalid handler boundary topology.'
);
var_dump(($resumed['run_status'] ?? null) === 'failed');
var_dump(($resumed['run_error'] ?? null) === 'remote peer received an invalid handler boundary topology.');
var_dump(($resumed['error_classification']['category'] ?? null) === 'backend');
var_dump(($resumed['error_classification']['scope'] ?? null) === 'run');
var_dump(($resumed['error_classification']['backend'] ?? null) === 'remote_peer');
var_dump(($resumed['handler_boundary']['required_step_refs'] ?? null) === [['index' => -1, 'tool_name' => 'summarizer']]);

$capture = king_orchestrator_remote_peer_stop($server);
var_dump(count($capture['events']) >= 1);
$last = $capture['events'][count($capture['events']) - 1] ?? [];
var_dump(($last['remote_error'] ?? null) === 'remote peer received an invalid handler boundary topology.');
var_dump(($last['failed_step_index'] ?? null) === -1);

foreach ([
    $bootstrapScript,
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
