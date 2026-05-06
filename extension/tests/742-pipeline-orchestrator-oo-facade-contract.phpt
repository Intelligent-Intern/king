--TEST--
King PipelineOrchestrator OO facade maps to the native orchestrator kernel
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-orch-oo-state-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$runnerScript = tempnam(sys_get_temp_dir(), 'king-orch-oo-runner-');

@unlink($statePath);

file_put_contents($runnerScript, <<<'PHP'
<?php
use King\PipelineOrchestrator;

function oo_prepare_handler(array $context): array
{
    $input = $context['input'] ?? [];
    if (!is_array($input)) {
        throw new RuntimeException('oo_prepare: unexpected input type');
    }

    $input['history'][] = 'prepare';
    return ['output' => $input];
}

function oo_finalize_handler(array $context): array
{
    $input = $context['input'] ?? [];
    if (!is_array($input)) {
        throw new RuntimeException('oo_finalize: unexpected input type');
    }

    $input['history'][] = 'finalize';
    return ['output' => $input];
}

if (!class_exists(PipelineOrchestrator::class)) {
    fwrite(STDERR, "missing King\\PipelineOrchestrator\n");
    exit(1);
}

$reflection = new ReflectionClass(PipelineOrchestrator::class);
if (!$reflection->isFinal()) {
    fwrite(STDERR, "King\\PipelineOrchestrator is not final\n");
    exit(1);
}

foreach ([
    'run',
    'dispatch',
    'registerTool',
    'registerHandler',
    'configureLogging',
    'workerRunNext',
    'resumeRun',
    'getRun',
    'cancelRun',
] as $method) {
    if (!$reflection->hasMethod($method) || !$reflection->getMethod($method)->isStatic()) {
        fwrite(STDERR, "missing static method {$method}\n");
        exit(1);
    }
}

if (!PipelineOrchestrator::registerTool('oo-prepare', ['model' => 'gpt-sim'])) {
    fwrite(STDERR, "register oo-prepare failed\n");
    exit(1);
}

if (!PipelineOrchestrator::registerTool('oo-finalize', ['model' => 'gpt-sim'])) {
    fwrite(STDERR, "register oo-finalize failed\n");
    exit(1);
}

if (!PipelineOrchestrator::registerHandler('oo-prepare', 'oo_prepare_handler')) {
    fwrite(STDERR, "register oo-prepare handler failed\n");
    exit(1);
}

if (!PipelineOrchestrator::registerHandler('oo-finalize', 'oo_finalize_handler')) {
    fwrite(STDERR, "register oo-finalize handler failed\n");
    exit(1);
}

$result = PipelineOrchestrator::run(
    ['history' => []],
    [
        ['tool' => 'oo-prepare'],
        ['tool' => 'oo-finalize'],
    ],
    ['trace_id' => 'pipeline-orchestrator-oo-facade-contract']
);

if (($result['history'] ?? null) !== ['prepare', 'finalize']) {
    fwrite(STDERR, "unexpected OO run result\n");
    exit(1);
}

$info = king_system_get_component_info('pipeline_orchestrator');
$runId = $info['configuration']['last_run_id'] ?? null;
if (!is_string($runId) || $runId === '') {
    fwrite(STDERR, "missing last_run_id\n");
    exit(1);
}

$run = PipelineOrchestrator::getRun($runId);
if (!is_array($run)) {
    fwrite(STDERR, "OO getRun returned non-array\n");
    exit(1);
}

$checks = [
    'status_completed' => ($run['status'] ?? null) === 'completed',
    'backend_local' => ($run['execution_backend'] ?? null) === 'local',
    'topology_local' => ($run['topology_scope'] ?? null) === 'local_in_process',
    'steps_2' => ($run['completed_step_count'] ?? null) === 2,
    'history' => ($run['result']['history'] ?? null) === ['prepare', 'finalize'],
];

$failed = [];
foreach ($checks as $name => $passed) {
    if (!$passed) {
        $failed[] = $name;
    }
}

if (count($failed) > 0) {
    fwrite(STDERR, "FAILED checks: " . implode(', ', $failed) . "\n");
    exit(1);
}

echo "ok\n";
PHP);

$command = sprintf(
    '%s -n -d %s -d %s -d %s %s 2>&1',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_state_path=' . $statePath),
    escapeshellarg($runnerScript)
);

exec($command, $output, $status);

var_dump($status === 0);
var_dump(trim($output[0] ?? '') === 'ok');
var_dump(is_file($statePath) && filesize($statePath) > 0);

@unlink($runnerScript);
@unlink($statePath);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
