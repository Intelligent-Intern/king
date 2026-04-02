--TEST--
King telemetry drops stale worker-local span and log residue before the next file-worker run under load
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

function king_telemetry_run_json_child(string $command): array
{
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('child telemetry worker script failed with exit code ' . $exitCode);
    }

    $decoded = json_decode(implode("\n", $output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('child telemetry worker script did not return JSON');
    }

    return $decoded;
}

$iterations = 4;
$collector = king_telemetry_test_start_collector(array_fill(0, $iterations, [
    'status' => 200,
    'body' => 'ok',
]));
$statePath = tempnam(sys_get_temp_dir(), 'king-telemetry-worker-cleanup-state-');
$queuePath = sys_get_temp_dir() . '/king-telemetry-worker-cleanup-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-worker-controller-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-worker-runner-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($controllerScript, <<<'PHP'
<?php
$iterations = (int) $argv[1];
$result = [
    'register' => king_pipeline_orchestrator_register_tool('summarizer', [
        'model' => 'gpt-sim',
        'max_tokens' => 32,
    ]),
    'dispatches' => [],
];

for ($i = 0; $i < $iterations; $i++) {
    $dispatch = king_pipeline_orchestrator_dispatch(
        ['text' => 'worker-' . $i],
        [['tool' => 'summarizer']],
        ['trace_id' => 'telemetry-worker-' . $i]
    );
    $result['dispatches'][] = [
        'backend' => $dispatch['backend'] ?? null,
        'status' => $dispatch['status'] ?? null,
    ];
}

echo json_encode($result, JSON_UNESCAPED_SLASHES);
PHP);

file_put_contents($workerScript, <<<'PHP'
<?php
$collectorPort = (int) $argv[1];
$iterations = (int) $argv[2];

king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collectorPort,
    'exporter_timeout_ms' => 500,
]);

$result = [
    'iterations' => [],
];

for ($i = 0; $i < $iterations; $i++) {
    king_telemetry_start_span('worker-stale-' . $i);
    king_telemetry_log('warn', 'worker-stale-' . $i, [
        'phase' => 'stale',
        'iteration' => $i,
    ]);

    $work = king_pipeline_orchestrator_worker_run_next();
    $afterRun = king_telemetry_get_status();

    king_telemetry_log('warn', 'worker-fresh-' . $i, [
        'phase' => 'fresh',
        'iteration' => $i,
    ]);
    king_telemetry_flush();
    $afterFlush = king_telemetry_get_status();

    $result['iterations'][] = [
        'run_status' => $work['status'] ?? null,
        'run_text' => $work['result']['text'] ?? null,
        'after_run_pending_log_count' => $afterRun['pending_log_count'],
        'after_run_pending_span_count' => $afterRun['pending_span_count'],
        'after_flush_pending_log_count' => $afterFlush['pending_log_count'],
        'after_flush_pending_span_count' => $afterFlush['pending_span_count'],
    ];
}

$result['empty'] = king_pipeline_orchestrator_worker_run_next();

echo json_encode($result, JSON_UNESCAPED_SLASHES);
PHP);

try {
    $baseCommand = sprintf(
        '%s -n -d %s -d %s -d %s -d %s -d %s %%s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg('king.orchestrator_execution_backend=file_worker'),
        escapeshellarg('king.orchestrator_worker_queue_path=' . $queuePath),
        escapeshellarg('king.orchestrator_state_path=' . $statePath)
    );

    $controller = king_telemetry_run_json_child(sprintf(
        $baseCommand,
        escapeshellarg($controllerScript) . ' ' . $iterations
    ));
    $worker = king_telemetry_run_json_child(sprintf(
        $baseCommand,
        escapeshellarg($workerScript) . ' ' . $collector['port'] . ' ' . $iterations
    ));
} finally {
    $collectorCapture = king_telemetry_test_stop_collector($collector);
    @unlink($controllerScript);
    @unlink($workerScript);
    @unlink($statePath);
    if (is_dir($queuePath)) {
        foreach (scandir($queuePath) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($queuePath . '/' . $entry);
            }
        }
        @rmdir($queuePath);
    }
}

if (($controller['register'] ?? false) !== true) {
    throw new RuntimeException('telemetry worker cleanup controller failed to register the tool.');
}
if (count($controller['dispatches'] ?? []) !== $iterations) {
    throw new RuntimeException('telemetry worker cleanup controller did not queue the expected runs.');
}
foreach ($controller['dispatches'] as $dispatch) {
    if (($dispatch['backend'] ?? null) !== 'file_worker' || ($dispatch['status'] ?? null) !== 'queued') {
        throw new RuntimeException('telemetry worker cleanup controller observed a dispatch drift.');
    }
}

if (count($worker['iterations'] ?? []) !== $iterations) {
    throw new RuntimeException('telemetry worker cleanup runner did not process the expected runs.');
}
foreach ($worker['iterations'] as $index => $iteration) {
    if (($iteration['run_status'] ?? null) !== 'completed') {
        throw new RuntimeException('telemetry worker cleanup runner did not complete one queued run.');
    }
    if (($iteration['run_text'] ?? null) !== 'worker-' . $index) {
        throw new RuntimeException('telemetry worker cleanup runner returned an unexpected payload.');
    }
    if (($iteration['after_run_pending_log_count'] ?? -1) !== 0
        || ($iteration['after_run_pending_span_count'] ?? -1) !== 0) {
        throw new RuntimeException('worker-boundary cleanup left stale telemetry pending before the next flush.');
    }
    if (($iteration['after_flush_pending_log_count'] ?? -1) !== 0
        || ($iteration['after_flush_pending_span_count'] ?? -1) !== 0) {
        throw new RuntimeException('telemetry worker cleanup flush left unexpected pending residue.');
    }
}
if (($worker['empty'] ?? true) !== false) {
    throw new RuntimeException('telemetry worker cleanup runner expected the queue to be empty at the end.');
}

$allBodies = '';
if (count($collectorCapture) !== $iterations) {
    throw new RuntimeException('telemetry worker cleanup collector did not observe the expected number of fresh flushes.');
}
foreach ($collectorCapture as $entry) {
    if (($entry['path'] ?? null) !== '/v1/logs') {
        throw new RuntimeException('telemetry worker cleanup collector observed an unexpected OTLP path.');
    }
    $allBodies .= $entry['body'] ?? '';
}

for ($i = 0; $i < $iterations; $i++) {
    if (!str_contains($allBodies, 'worker-fresh-' . $i)) {
        throw new RuntimeException('fresh worker log was not exported after boundary cleanup.');
    }
    if (str_contains($allBodies, 'worker-stale-' . $i)) {
        throw new RuntimeException('stale worker log leaked across a worker boundary.');
    }
}
if (str_contains($allBodies, '"traceId"') || str_contains($allBodies, '"spanId"')) {
    throw new RuntimeException('fresh worker logs inherited a stale trace/span context.');
}

echo "OK\n";
?>
--EXPECT--
OK
