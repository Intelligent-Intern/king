--TEST--
King pipeline orchestrator keeps per-step error classification explicit across local and remote failures
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--FILE--
<?php
require __DIR__ . '/orchestrator_remote_peer_helper.inc';

var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));

try {
    king_pipeline_orchestrator_run(
        ['text' => 'validation'],
        [
            ['tool' => 'summarizer'],
            ['tool' => 'missing-tool'],
        ]
    );
    echo "no-exception-validation\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
}

$validationRunId = king_system_get_component_info('pipeline_orchestrator')['configuration']['last_run_id'];
$validation = king_pipeline_orchestrator_get_run($validationRunId);
var_dump(($validation['status'] ?? null) === 'failed');
var_dump(($validation['error_classification']['category'] ?? null) === 'validation');
var_dump(($validation['error_classification']['retry_disposition'] ?? null) === 'non_retryable');
var_dump(($validation['error_classification']['scope'] ?? null) === 'step');
var_dump(($validation['error_classification']['step_index'] ?? null) === 1);
var_dump(($validation['error_classification']['step_tool'] ?? null) === 'missing-tool');
var_dump(($validation['steps'][0]['status'] ?? null) === 'completed');
var_dump(($validation['steps'][1]['status'] ?? null) === 'failed');

try {
    king_pipeline_orchestrator_run(
        ['text' => 'timeout'],
        [
            ['tool' => 'summarizer'],
            ['tool' => 'summarizer', 'delay_ms' => 100],
        ],
        ['timeout_ms' => 20]
    );
    echo "no-exception-timeout\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
}

$timeoutRunId = king_system_get_component_info('pipeline_orchestrator')['configuration']['last_run_id'];
$timeout = king_pipeline_orchestrator_get_run($timeoutRunId);
var_dump(($timeout['status'] ?? null) === 'failed');
var_dump(($timeout['error_classification']['category'] ?? null) === 'timeout');
var_dump(($timeout['error_classification']['retry_disposition'] ?? null) === 'caller_managed_retry');
var_dump(($timeout['error_classification']['step_index'] ?? null) === 1);
var_dump(($timeout['steps'][0]['status'] ?? null) === 'completed');
var_dump(($timeout['steps'][1]['status'] ?? null) === 'failed');

$extensionPath = dirname(__DIR__) . '/modules/king.so';
$remoteScript = tempnam(sys_get_temp_dir(), 'king-orchestrator-error-class-');

file_put_contents($remoteScript, <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);

try {
    king_pipeline_orchestrator_run(
        ['text' => 'remote-case'],
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
    'step_statuses' => array_map(
        static fn(array $step): mixed => $step['status'] ?? null,
        $run['steps'] ?? []
    ),
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP
);

$runRemoteCase = static function (array $pipeline, string $traceId, array $server) use ($extensionPath, $remoteScript): array {
    $statePath = tempnam(sys_get_temp_dir(), 'king-orchestrator-error-class-state-');
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

$remoteBackendServer = king_orchestrator_remote_peer_start();
$remoteBackend = $runRemoteCase(
    [
        ['tool' => 'summarizer', 'delay_ms' => 5],
        ['tool' => 'summarizer', 'remote_error' => 'forced remote backend failure'],
    ],
    'remote-backend',
    $remoteBackendServer
);
$remoteBackendCapture = king_orchestrator_remote_peer_stop($remoteBackendServer);

var_dump(($remoteBackend['class'] ?? null) === 'King\\RuntimeException');
var_dump(($remoteBackend['snapshot']['status'] ?? null) === 'failed');
var_dump(($remoteBackend['snapshot']['error_classification']['category'] ?? null) === 'backend');
var_dump(($remoteBackend['snapshot']['error_classification']['retry_disposition'] ?? null) === 'caller_managed_retry');
var_dump(($remoteBackend['snapshot']['error_classification']['backend'] ?? null) === 'remote_peer');
var_dump(($remoteBackend['snapshot']['error_classification']['step_index'] ?? null) === 1);
var_dump(($remoteBackend['snapshot']['error_classification']['step_tool'] ?? null) === 'summarizer');
var_dump(($remoteBackend['snapshot']['step_statuses'][0] ?? null) === 'completed');
var_dump(($remoteBackend['snapshot']['step_statuses'][1] ?? null) === 'failed');
var_dump(($remoteBackendCapture['events'][0]['failed_step_index'] ?? null) === 1);

$remoteTransportServer = king_orchestrator_remote_peer_start();
$remoteTransport = $runRemoteCase(
    [
        ['tool' => 'summarizer', 'delay_ms' => 5],
        ['tool' => 'summarizer', 'remote_close' => true],
    ],
    'remote-transport',
    $remoteTransportServer
);
$remoteTransportCapture = king_orchestrator_remote_peer_stop($remoteTransportServer);

var_dump(($remoteTransport['class'] ?? null) === 'King\\RuntimeException');
var_dump(($remoteTransport['snapshot']['status'] ?? null) === 'failed');
var_dump(($remoteTransport['snapshot']['error_classification']['category'] ?? null) === 'remote_transport');
var_dump(($remoteTransport['snapshot']['error_classification']['retry_disposition'] ?? null) === 'caller_managed_retry');
var_dump(($remoteTransport['snapshot']['error_classification']['backend'] ?? null) === 'remote_peer');
var_dump(($remoteTransport['snapshot']['error_classification']['scope'] ?? null) === 'run');
var_dump(($remoteTransport['snapshot']['error_classification']['step_index'] ?? null) === null);
var_dump(($remoteTransport['snapshot']['step_statuses'][0] ?? null) === 'indeterminate');
var_dump(($remoteTransport['snapshot']['step_statuses'][1] ?? null) === 'indeterminate');
var_dump(($remoteTransportCapture['events'][0]['failed_step_index'] ?? null) === 1);

@unlink($remoteScript);
?>
--EXPECT--
bool(true)
string(21) "King\RuntimeException"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(21) "King\TimeoutException"
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
