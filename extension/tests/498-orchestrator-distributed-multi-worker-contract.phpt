--TEST--
King pipeline orchestrator remote peer execution stays stable when one peer distributes steps across multiple workers
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_remote_peer_helper.inc';

$server = king_orchestrator_remote_peer_start(
    null,
    '127.0.0.1',
    __DIR__ . '/orchestrator_distributed_peer_server.inc',
    [2]
);
$statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-distributed-workers-state-');
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$runnerScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-distributed-workers-runner-');

@unlink($statePath);

file_put_contents($runnerScript, <<<'PHP'
<?php
var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));

$result = king_pipeline_orchestrator_run(
    [
        'text' => 'distributed-workers',
        'claims' => [],
        'step_order' => [],
        'workers' => [],
    ],
    [
        ['tool' => 'summarizer', 'delay_ms' => 5],
        ['tool' => 'summarizer', 'delay_ms' => 5],
        ['tool' => 'summarizer', 'delay_ms' => 5],
        ['tool' => 'summarizer', 'delay_ms' => 5],
    ],
    ['trace_id' => 'distributed-workers-run']
);

var_dump(($result['text'] ?? null) === 'distributed-workers');
var_dump(count($result['claims'] ?? []) === 4);
var_dump(($result['step_order'] ?? null) === [0, 1, 2, 3]);
var_dump(($result['workers'] ?? null) === ['worker-1', 'worker-2', 'worker-1', 'worker-2']);

$run = king_pipeline_orchestrator_get_run(
    king_system_get_component_info('pipeline_orchestrator')['configuration']['last_run_id']
);
var_dump(($run['status'] ?? null) === 'completed');
var_dump(($run['result']['workers'] ?? null) === ['worker-1', 'worker-2', 'worker-1', 'worker-2']);
var_dump(($run['steps'][0]['status'] ?? null) === 'completed');
var_dump(($run['steps'][1]['status'] ?? null) === 'completed');
var_dump(($run['steps'][2]['status'] ?? null) === 'completed');
var_dump(($run['steps'][3]['status'] ?? null) === 'completed');
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
var_dump(($capture['workers'] ?? null) === ['worker-1', 'worker-2']);
var_dump(count($capture['events'] ?? []) === 1);
var_dump(($capture['events'][0]['run_id'] ?? null) === 'run-1');
var_dump(($capture['events'][0]['options']['trace_id'] ?? null) === 'distributed-workers-run');
var_dump(($capture['events'][0]['claims'] ?? null) === [
    ['step_index' => 0, 'tool' => 'summarizer', 'worker' => 'worker-1'],
    ['step_index' => 1, 'tool' => 'summarizer', 'worker' => 'worker-2'],
    ['step_index' => 2, 'tool' => 'summarizer', 'worker' => 'worker-1'],
    ['step_index' => 3, 'tool' => 'summarizer', 'worker' => 'worker-2'],
]);
var_dump(($capture['events'][0]['result']['workers'] ?? null) === ['worker-1', 'worker-2', 'worker-1', 'worker-2']);

@unlink($runnerScript);
@unlink($statePath);
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
bool(true)
