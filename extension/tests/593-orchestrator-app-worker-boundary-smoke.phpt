--TEST--
King app-worker workflow dispatch is driven by durable tool names, not transported userland callbacks
--SKIPIF--
<?php
require __DIR__ . '/skipif_capability.inc';

king_skipif_require_functions([
    'proc_open',
    'stream_socket_server',
    'king_pipeline_orchestrator_register_tool',
    'king_pipeline_orchestrator_run',
    'king_system_get_component_info',
]);
king_skipif_require_loopback_bind('tcp');
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_remote_peer_helper.inc';

$extensionPath = dirname(__DIR__) . '/modules/king.so';
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-app-worker-smoke-state-');
$bootstrapScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-app-worker-smoke-bootstrap-');
$controllerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-app-worker-smoke-controller-');

@unlink($statePath);

function king_orchestrator_contains_callback_names(mixed $value, array $forbiddenNames): bool
{
    if (is_array($value)) {
        foreach ($value as $entry) {
            if (king_orchestrator_contains_callback_names($entry, $forbiddenNames)) {
                return true;
            }
        }
        return false;
    }

    if (is_string($value)) {
        return in_array($value, $forbiddenNames, true);
    }

    if (is_object($value)) {
        return false;
    }

    return false;
}

file_put_contents($bootstrapScript, <<<'PHP'
<?php
function remote_prepare_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected remote prepare input');
    }

    $input['history'][] = sprintf(
        'remote-prepare:%s:%d:%d',
        (($context['tool']['config']['label'] ?? null) ?? 'missing'),
        (int) $context['timeout_budget_ms'],
        (int) $context['deadline_budget_ms']
    );
    return ['output' => $input];
}

function remote_finalize_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected remote finalize input');
    }

    $input['history'][] = sprintf(
        'remote-finalize:%s:%d:%d',
        (($context['tool']['config']['label'] ?? null) ?? 'missing'),
        (int) $context['timeout_budget_ms'],
        (int) $context['deadline_budget_ms']
    );
    return ['output' => $input];
}

return [
    'prepare' => 'remote_prepare_handler',
    'finalize' => 'remote_finalize_handler',
];
PHP);

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
    'label' => 'prepare-config',
    'max_tokens' => 128,
]);
king_pipeline_orchestrator_register_tool('finalize', [
    'label' => 'finalize-config',
    'max_tokens' => 128,
]);
king_pipeline_orchestrator_register_handler('prepare', 'prepare_handler');
king_pipeline_orchestrator_register_handler('finalize', 'finalize_handler');

$result = king_pipeline_orchestrator_run(
    ['text' => 'app-worker-smoke', 'history' => []],
    [['tool' => 'prepare'], ['tool' => 'finalize']],
    ['trace_id' => 'app-worker-smoke-run']
);

$info = king_system_get_component_info('pipeline_orchestrator');
$runId = $info['configuration']['last_run_id'] ?? 'run-1';
$run = king_pipeline_orchestrator_get_run($runId);

echo json_encode([
    'run_id' => $runId,
    'result' => $result,
    'run' => $run,
    'component' => $info['configuration'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP);

$buildCommand = static function (string $serverHost, int $serverPort, string $script) use ($extensionPath, $statePath): string {
    return sprintf(
        '%s -n -d %s -d %s -d %s -d %s -d %s -d %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg('king.orchestrator_execution_backend=remote_peer'),
        escapeshellarg('king.orchestrator_remote_host=' . $serverHost),
        escapeshellarg('king.orchestrator_remote_port=' . $serverPort),
        escapeshellarg('king.orchestrator_state_path=' . $statePath),
        escapeshellarg($script)
    );
};

$server = king_orchestrator_remote_peer_start(null, '127.0.0.1', null, [$bootstrapScript]);
$controllerOutput = [];
$controllerStatus = -1;
exec($buildCommand($server['host'], $server['port'], $controllerScript), $controllerOutput, $controllerStatus);
$captured = king_orchestrator_remote_peer_stop($server);
$snapshot = json_decode(trim($controllerOutput[0] ?? ''), true);

var_dump($controllerStatus);
var_dump(is_array($snapshot));
var_dump(($snapshot['component']['execution_backend'] ?? null) === 'remote_peer');
var_dump(($snapshot['component']['topology_scope'] ?? null) === 'tcp_host_port_execution_peer');
var_dump(($snapshot['run']['status'] ?? null) === 'completed');
var_dump(($snapshot['run']['handler_boundary']['requires_process_registration'] ?? null) === true);
var_dump(($snapshot['run']['handler_boundary']['required_tools'] ?? null) === ['prepare', 'finalize']);
var_dump(str_starts_with((string) ($snapshot['result']['history'][0] ?? ''), 'remote-prepare:prepare-config:'));
var_dump(str_starts_with((string) ($snapshot['result']['history'][1] ?? ''), 'remote-finalize:finalize-config:'));
var_dump(is_array($captured['events'] ?? null));
var_dump(count($captured['events'] ?? []) === 1);
var_dump(($captured['events'][0]['handler_boundary']['required_tools'] ?? null) === ['prepare', 'finalize']);
var_dump(($captured['events'][0]['tool_configs']['prepare']['label'] ?? null) === 'prepare-config');
var_dump(($captured['events'][0]['tool_configs']['finalize']['label'] ?? null) === 'finalize-config');

$forbiddenCallbackNames = ['remote_prepare_handler', 'remote_finalize_handler'];
$stateLeak = false;
$stateFields = preg_split("/[\\t\\r\\n]+/", (string) file_get_contents($statePath));
foreach ((array) $stateFields as $field) {
    $decoded = base64_decode((string) $field, true);
    if ($decoded === false) {
        continue;
    }

    foreach ($forbiddenCallbackNames as $forbidden) {
        if (str_contains((string) $decoded, $forbidden)) {
            $stateLeak = true;
            break 2;
        }
    }
}
var_dump($stateLeak === false);
var_dump(king_orchestrator_contains_callback_names($captured['events'][0] ?? null, $forbiddenCallbackNames) === false);
var_dump(king_orchestrator_contains_callback_names($snapshot['run'] ?? null, $forbiddenCallbackNames) === false);

foreach ([$statePath, $bootstrapScript, $controllerScript] as $path) {
    @unlink($path);
}
?>
--EXPECT--
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
bool(true)
bool(true)
bool(true)
