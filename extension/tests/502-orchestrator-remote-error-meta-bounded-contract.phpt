--TEST--
King pipeline orchestrator bounds remote error metadata strings from remote peers
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_remote_peer_helper.inc';

$extensionPath = dirname(__DIR__) . '/modules/king.so';
$remoteScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-meta-');

file_put_contents($remoteScript, <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);

try {
    king_pipeline_orchestrator_run(
        ['text' => 'remote-long-meta'],
        unserialize(base64_decode($argv[1]), ['allowed_classes' => false]),
        ['trace_id' => $argv[2]]
    );
    echo "no-exception\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

$run = king_pipeline_orchestrator_get_run(
    king_system_get_component_info('pipeline_orchestrator')['configuration']['last_run_id']
);
echo json_encode([
    'status' => $run['status'] ?? null,
    'error_classification' => $run['error_classification'] ?? null,
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP
);

$runRemoteCase = static function (array $pipeline, string $traceId, array $server) use ($extensionPath, $remoteScript): array {
    $statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-remote-meta-state-');
    @unlink($statePath);

    $command = sprintf(
        '%s -n -d %s -d %s -d %s -d %s -d %s -d %s %s %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg('king.orchestrator_execution_backend=remote_peer'),
        escapeshellarg('king.orchestrator_remote_host=' . $server['host']),
        escapeshellarg('king.orchestrator_remote_port=' . $server['port']),
        escapeshellarg('king.orchestrator_state_path=' . $statePath),
        escapeshellarg($remoteScript),
        escapeshellarg(base64_encode(serialize($pipeline))),
        escapeshellarg($traceId)
    );

    $output = [];
    $status = -1;
    exec($command, $output, $status);
    @unlink($statePath);

    if ($status !== 0) {
        throw new RuntimeException('remote orchestrator child failed');
    }

    return [
        'class' => $output[0] ?? null,
        'snapshot' => json_decode($output[1] ?? 'null', true),
    ];
};

$server = king_orchestrator_remote_peer_start();
$result = $runRemoteCase(
    [[
        'tool' => 'summarizer',
        'remote_error' => 'forced long remote metadata failure',
        'remote_error_category' => str_repeat('c', 40),
        'remote_error_retry_disposition' => str_repeat('r', 41),
        'remote_error_backend' => str_repeat('b', 39),
    ]],
    'remote-long-meta',
    $server
);
$capture = king_orchestrator_remote_peer_stop($server);

var_dump(($result['class'] ?? null) === 'King\\RuntimeException');
var_dump(($result['snapshot']['status'] ?? null) === 'failed');
var_dump(($result['snapshot']['error_classification']['category'] ?? null) === str_repeat('c', 31));
var_dump(($result['snapshot']['error_classification']['retry_disposition'] ?? null) === str_repeat('r', 31));
var_dump(($result['snapshot']['error_classification']['backend'] ?? null) === str_repeat('b', 31));
var_dump(($result['snapshot']['error_classification']['step_index'] ?? null) === 0);
var_dump(($capture['events'][0]['failed_step_index'] ?? null) === 0);

@unlink($remoteScript);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
