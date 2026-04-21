--TEST--
King queued file-worker runs persist only durable userland handler references, not executable PHP callables
--INI--
king.security_allow_config_override=1
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) {
    echo "skip pcntl extension required";
}
?>
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-handler-boundary-state-');
$queuePath = sys_get_temp_dir() . '/king-orchestrator-handler-boundary-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-handler-boundary-controller-');
$readerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-handler-boundary-reader-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($controllerScript, <<<'PHP'
<?php
function prepare_handler(array $context): array
{
    return ['output' => $context['input'] ?? []];
}

function finalize_handler(array $context): array
{
    return ['output' => $context['input'] ?? []];
}

king_pipeline_orchestrator_register_tool('prepare', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_tool('finalize', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('prepare', 'prepare_handler');
king_pipeline_orchestrator_register_handler('finalize', 'finalize_handler');

$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'queued-userland'],
    [
        ['tool' => 'prepare'],
        ['tool' => 'finalize'],
        ['tool' => 'prepare'],
    ],
    ['trace_id' => 'queued-userland-handler-boundary']
);

echo json_encode($dispatch), "\n";
PHP);

file_put_contents($readerScript, <<<'PHP'
<?php
$runId = $argv[1] ?? '';
$info = king_system_get_component_info('pipeline_orchestrator');
$run = king_pipeline_orchestrator_get_run($runId);

echo json_encode([
    'recovered_from_state' => $info['configuration']['recovered_from_state'],
    'status' => $run['status'] ?? null,
    'queue_phase' => $run['distributed_observability']['queue_phase'] ?? null,
    'handler_boundary' => $run['handler_boundary'] ?? null,
]), "\n";
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
$dispatch = json_decode(trim($controllerOutput[0] ?? ''), true);

var_dump($controllerStatus);
var_dump(preg_match('/^run-\d+$/', $dispatch['run_id'] ?? '') === 1);
var_dump(($dispatch['status'] ?? null) === 'queued');

$decodedStateLeak = false;
foreach (preg_split("/[\t\r\n]+/", (string) file_get_contents($statePath)) as $field) {
    $decoded = base64_decode($field, true);
    if ($decoded === false) {
        continue;
    }

    if (str_contains($decoded, 'prepare_handler') || str_contains($decoded, 'finalize_handler')) {
        $decodedStateLeak = true;
        break;
    }
}
var_dump($decodedStateLeak === false);

$readerCommand = sprintf(
    $baseCommand,
    escapeshellarg($readerScript) . ' ' . escapeshellarg($dispatch['run_id'] ?? '')
);
exec($readerCommand, $readerOutput, $readerStatus);
$snapshot = json_decode(trim($readerOutput[0] ?? ''), true);

var_dump($readerStatus);
var_dump(($snapshot['recovered_from_state'] ?? null) === true);
var_dump(($snapshot['status'] ?? null) === 'queued');
var_dump(($snapshot['queue_phase'] ?? null) === 'queued');
var_dump(($snapshot['handler_boundary']['contract'] ?? null) === 'durable_tool_name_refs_only');
var_dump(($snapshot['handler_boundary']['binding_scope'] ?? null) === 'process_local_re_registration');
var_dump(($snapshot['handler_boundary']['execution_backend'] ?? null) === 'file_worker');
var_dump(($snapshot['handler_boundary']['topology_scope'] ?? null) === 'same_host_file_worker');
var_dump(($snapshot['handler_boundary']['required_tools'] ?? null) === ['prepare', 'finalize']);
var_dump(($snapshot['handler_boundary']['required_step_refs'] ?? null) === [
    ['index' => 0, 'tool_name' => 'prepare'],
    ['index' => 1, 'tool_name' => 'finalize'],
    ['index' => 2, 'tool_name' => 'prepare'],
]);
var_dump(($snapshot['handler_boundary']['required_tool_count'] ?? null) === 2);
var_dump(($snapshot['handler_boundary']['required_step_count'] ?? null) === 3);
var_dump(($snapshot['handler_boundary']['requires_process_registration'] ?? null) === true);

@unlink($controllerScript);
@unlink($readerScript);
@unlink($statePath);
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
