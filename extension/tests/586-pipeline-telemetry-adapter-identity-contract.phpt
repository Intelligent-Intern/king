--TEST--
King pipeline orchestrator maps run partition batch retry and failure identity into snapshots metrics spans and logs
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/orchestrator_failover_harness.inc';
require __DIR__ . '/telemetry_otlp_test_helper.inc';

function king_pipeline_adapter_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_pipeline_adapter_decode_json(array $result, string $label): array
{
    king_pipeline_adapter_assert(
        $result['status'] === 0,
        $label . ' exited with status ' . json_encode($result['status']) . ' and stderr ' . json_encode($result['stderr'])
    );
    king_pipeline_adapter_assert(
        trim($result['stderr']) === '',
        $label . ' wrote unexpected stderr: ' . json_encode($result['stderr'])
    );

    $decoded = json_decode(trim($result['stdout']), true);
    king_pipeline_adapter_assert(
        is_array($decoded),
        $label . ' did not return valid JSON: ' . json_encode($result['stdout'])
    );

    return $decoded;
}

function king_pipeline_adapter_read_run_state(
    array $harness,
    string $backend,
    string $observerScript,
    string $runId,
    bool $strict = true
): array {
    $result = king_orchestrator_failover_harness_exec($harness, $backend, $observerScript, [$runId]);

    if ($strict) {
        return king_pipeline_adapter_decode_json($result, 'observer/' . $backend);
    }

    if ($result['status'] !== 0 || trim($result['stderr']) !== '') {
        return ['exists' => false];
    }

    $decoded = json_decode(trim($result['stdout']), true);
    return is_array($decoded) ? $decoded : ['exists' => false];
}

function king_pipeline_adapter_attributes(array $attributes): array
{
    $map = [];

    foreach ($attributes as $attribute) {
        if (!is_array($attribute) || !isset($attribute['key']) || !is_array($attribute['value'] ?? null)) {
            continue;
        }

        $value = $attribute['value'];
        if (array_key_exists('stringValue', $value)) {
            $map[$attribute['key']] = $value['stringValue'];
            continue;
        }
        if (array_key_exists('intValue', $value)) {
            $map[$attribute['key']] = (string) $value['intValue'];
            continue;
        }
        if (array_key_exists('doubleValue', $value)) {
            $map[$attribute['key']] = (string) $value['doubleValue'];
            continue;
        }
        if (array_key_exists('boolValue', $value)) {
            $map[$attribute['key']] = $value['boolValue'] ? 'true' : 'false';
        }
    }

    return $map;
}

function king_pipeline_adapter_collect_spans(array $capture): array
{
    $spans = [];

    foreach ($capture as $entry) {
        if (($entry['path'] ?? null) !== '/v1/traces') {
            continue;
        }

        $payload = json_decode((string) ($entry['body'] ?? ''), true);
        king_pipeline_adapter_assert(is_array($payload), 'collector emitted malformed OTLP trace JSON.');

        $bodySpans = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? null;
        king_pipeline_adapter_assert(is_array($bodySpans), 'collector emitted malformed OTLP span payload.');

        foreach ($bodySpans as $span) {
            if (is_array($span)) {
                $spans[] = $span;
            }
        }
    }

    return $spans;
}

function king_pipeline_adapter_collect_logs(array $capture): array
{
    $logs = [];

    foreach ($capture as $entry) {
        if (($entry['path'] ?? null) !== '/v1/logs') {
            continue;
        }

        $payload = json_decode((string) ($entry['body'] ?? ''), true);
        king_pipeline_adapter_assert(is_array($payload), 'collector emitted malformed OTLP log JSON.');

        $records = $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'] ?? null;
        king_pipeline_adapter_assert(is_array($records), 'collector emitted malformed OTLP log payload.');

        foreach ($records as $record) {
            if (is_array($record)) {
                $logs[] = $record;
            }
        }
    }

    return $logs;
}

function king_pipeline_adapter_collect_metric_values(array $capture): array
{
    $metrics = [];

    foreach ($capture as $entry) {
        if (($entry['path'] ?? null) !== '/v1/metrics') {
            continue;
        }

        $payload = json_decode((string) ($entry['body'] ?? ''), true);
        king_pipeline_adapter_assert(is_array($payload), 'collector emitted malformed OTLP metric JSON.');

        $bodyMetrics = $payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? null;
        king_pipeline_adapter_assert(is_array($bodyMetrics), 'collector emitted malformed OTLP metric payload.');

        foreach ($bodyMetrics as $metric) {
            if (!is_array($metric) || !isset($metric['name'])) {
                continue;
            }

            $dataPoint = $metric['sum']['dataPoints'][0]
                ?? $metric['gauge']['dataPoints'][0]
                ?? $metric['histogram']['dataPoints'][0]
                ?? $metric['summary']['dataPoints'][0]
                ?? null;
            if (!is_array($dataPoint)) {
                continue;
            }

            if (array_key_exists('asInt', $dataPoint)) {
                $metrics[$metric['name']] = (int) $dataPoint['asInt'];
                continue;
            }
            if (array_key_exists('asDouble', $dataPoint)) {
                $metrics[$metric['name']] = (float) $dataPoint['asDouble'];
                continue;
            }
            if (array_key_exists('count', $dataPoint)) {
                $metrics[$metric['name']] = (int) $dataPoint['count'];
            }
        }
    }

    return $metrics;
}

function king_pipeline_adapter_find_span(
    array $spans,
    string $name,
    string $identityScope,
    string $attemptIdentity
): array {
    foreach ($spans as $span) {
        if (($span['name'] ?? null) !== $name) {
            continue;
        }

        $attributes = king_pipeline_adapter_attributes($span['attributes'] ?? []);
        if (
            ($attributes['component'] ?? null) === 'pipeline_orchestrator'
            && ($attributes['telemetry_adapter_contract'] ?? null) === 'run_partition_batch_retry_failure_identity'
            && ($attributes['identity_scope'] ?? null) === $identityScope
            && ($attributes['attempt_identity'] ?? null) === $attemptIdentity
        ) {
            return [
                'span' => $span,
                'attributes' => $attributes,
            ];
        }
    }

    throw new RuntimeException('collector did not emit the expected pipeline adapter span for ' . $identityScope);
}

function king_pipeline_adapter_find_failure_log(array $logs, string $failureIdentity): array
{
    foreach ($logs as $record) {
        $attributes = king_pipeline_adapter_attributes($record['attributes'] ?? []);
        if (
            ($attributes['component'] ?? null) === 'pipeline_orchestrator'
            && ($attributes['failure_identity'] ?? null) === $failureIdentity
        ) {
            return [
                'record' => $record,
                'attributes' => $attributes,
            ];
        }
    }

    throw new RuntimeException('collector did not emit the expected pipeline adapter failure log.');
}

function king_pipeline_adapter_recovery_scenario(): array
{
    $harness = king_orchestrator_failover_harness_create();
    $capture = [];
    $collector = null;

    try {
        $dispatchScript = king_orchestrator_failover_harness_write_script($harness, 'pipeline-telemetry-adapter-dispatch', <<<'PHP'
<?php
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'pipeline-telemetry-recovery'],
    [[
        'tool' => 'summarizer',
        'delay_ms' => 5000,
        'partition_id' => 'partition-eu-west-1',
        'batch_id' => 'batch-recovery-001',
    ]],
    ['trace_id' => 'pipeline-telemetry-recovery']
);
echo json_encode($dispatch, JSON_UNESCAPED_SLASHES), "\n";
PHP);
        $observerScript = king_orchestrator_failover_harness_write_script($harness, 'pipeline-telemetry-adapter-observer', <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1]);
if ($run === false) {
    echo json_encode(['exists' => false]), "\n";
    return;
}
echo json_encode($run, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP);
        $crashWorkerScript = king_orchestrator_failover_harness_write_script($harness, 'pipeline-telemetry-adapter-crash-worker', <<<'PHP'
<?php
king_pipeline_orchestrator_worker_run_next();
PHP);
        $workerScript = king_orchestrator_failover_harness_write_script($harness, 'pipeline-telemetry-adapter-worker', <<<'PHP'
<?php
$collectorPort = (int) $argv[1];

king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collectorPort,
    'exporter_timeout_ms' => 500,
]);
$work = king_pipeline_orchestrator_worker_run_next();
$run = king_pipeline_orchestrator_get_run($work['run_id'] ?? '');
$component = king_system_get_component_info('pipeline_orchestrator');
$liveMetrics = king_telemetry_get_metrics();
$flushResult = king_telemetry_flush();
$status = king_telemetry_get_status();

echo json_encode([
    'work' => $work,
    'run' => $run,
    'component' => $component['configuration'] ?? null,
    'live_metrics' => $liveMetrics,
    'flush_result' => $flushResult,
    'status' => $status,
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP);

        $dispatch = king_pipeline_adapter_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'file_worker', $dispatchScript),
            'dispatch/file_worker'
        );
        king_pipeline_adapter_assert(
            ($dispatch['run_id'] ?? null) === 'run-1'
            && ($dispatch['backend'] ?? null) === 'file_worker'
            && ($dispatch['status'] ?? null) === 'queued',
            'pipeline telemetry adapter dispatch did not queue the expected run.'
        );

        $worker = king_orchestrator_failover_harness_spawn(
            $harness,
            'file_worker',
            $crashWorkerScript
        );

        $claimedObserved = king_orchestrator_failover_harness_wait_for(
            static function () use ($harness, $observerScript): bool {
                $run = king_pipeline_adapter_read_run_state(
                    $harness,
                    'file_worker',
                    $observerScript,
                    'run-1',
                    false
                );

                return ($run['status'] ?? null) === 'running'
                    && ($run['distributed_observability']['queue_phase'] ?? null) === 'claimed'
                    && ($run['distributed_observability']['claim_count'] ?? null) === 1;
            }
        );
        king_pipeline_adapter_assert(
            $claimedObserved,
            'pipeline telemetry adapter scenario never observed the first claimed worker attempt.'
        );

        $firstCrash = king_orchestrator_failover_harness_crash_process($worker);
        king_pipeline_adapter_assert(
            $firstCrash['status'] !== 0,
            'pipeline telemetry adapter first worker exited cleanly after forced crash.'
        );

        $collector = king_telemetry_test_start_collector([
            ['status' => 200, 'body' => 'ok'],
            ['status' => 200, 'body' => 'ok'],
        ]);
        $recovered = king_pipeline_adapter_decode_json(
            king_orchestrator_failover_harness_exec(
                $harness,
                'file_worker',
                $workerScript,
                [$collector['port']]
            ),
            'worker/file_worker-recovery'
        );
    } finally {
        king_orchestrator_failover_harness_destroy($harness);
        if ($collector !== null) {
            $capture = king_telemetry_test_stop_collector($collector);
        }
    }

    return [
        'dispatch' => $dispatch,
        'recovered' => $recovered,
        'capture' => $capture,
    ];
}

function king_pipeline_adapter_failure_scenario(): array
{
    $collector = king_telemetry_test_start_collector([
        ['status' => 200, 'body' => 'ok'],
        ['status' => 200, 'body' => 'ok'],
        ['status' => 200, 'body' => 'ok'],
    ]);
    $harness = king_orchestrator_failover_harness_create();
    $capture = [];

    try {
        $runnerScript = king_orchestrator_failover_harness_write_script($harness, 'pipeline-telemetry-adapter-failure', <<<'PHP'
<?php
$collectorPort = (int) $argv[1];

king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collectorPort,
    'exporter_timeout_ms' => 500,
]);
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);

$exceptionClass = null;
$exceptionMessage = null;
try {
    king_pipeline_orchestrator_run(
        ['text' => 'pipeline-telemetry-failure'],
        [[
            'tool' => 'summarizer',
            'delay_ms' => 100,
            'partition_id' => 'partition-us-east-1',
            'batch_id' => 'batch-failure-001',
        ]],
        [
            'trace_id' => 'pipeline-telemetry-failure',
            'timeout_ms' => 20,
        ]
    );
} catch (Throwable $e) {
    $exceptionClass = get_class($e);
    $exceptionMessage = $e->getMessage();
}

$run = king_pipeline_orchestrator_get_run('run-1');
$liveMetrics = king_telemetry_get_metrics();
$flushResult = king_telemetry_flush();
$status = king_telemetry_get_status();

echo json_encode([
    'exception_class' => $exceptionClass,
    'exception_message' => $exceptionMessage,
    'run' => $run,
    'live_metrics' => $liveMetrics,
    'flush_result' => $flushResult,
    'status' => $status,
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
PHP);

        $result = king_pipeline_adapter_decode_json(
            king_orchestrator_failover_harness_exec($harness, 'local', $runnerScript, [$collector['port']]),
            'run/local-timeout'
        );
    } finally {
        king_orchestrator_failover_harness_destroy($harness);
        $capture = king_telemetry_test_stop_collector($collector);
    }

    return [
        'result' => $result,
        'capture' => $capture,
    ];
}

$recoveryScenario = king_pipeline_adapter_recovery_scenario();
$failureScenario = king_pipeline_adapter_failure_scenario();

$recovery = $recoveryScenario['recovered'];
$recoveryRun = $recovery['run'] ?? null;
$recoveryComponent = $recovery['component'] ?? null;
$recoveryMetrics = $recovery['live_metrics'] ?? null;
king_pipeline_adapter_assert(
    ($recovery['work']['status'] ?? null) === 'completed'
    && ($recovery['work']['result']['text'] ?? null) === 'pipeline-telemetry-recovery',
    'recovered worker did not complete the queued pipeline run.'
);
king_pipeline_adapter_assert(
    ($recovery['flush_result'] ?? false) === true
    && (int) (($recovery['status']['queue_size'] ?? -1)) === 0,
    'recovered worker did not flush telemetry cleanly.'
);
king_pipeline_adapter_assert(
    is_array($recoveryRun)
    && ($recoveryRun['telemetry_adapter']['contract'] ?? null) === 'run_partition_batch_retry_failure_identity'
    && ($recoveryRun['telemetry_adapter']['identity_surface'] ?? null) === 'run_snapshots+otlp_spans+otlp_logs+telemetry_metrics'
    && ($recoveryRun['telemetry_adapter']['attempt_identity'] ?? null) === 'run-1:attempt-2'
    && ($recoveryRun['telemetry_adapter']['retry_identity'] ?? null) === 'run-1:attempt-2'
    && ($recoveryRun['telemetry_adapter']['partition_count'] ?? null) === 1
    && ($recoveryRun['telemetry_adapter']['batch_count'] ?? null) === 1
    && ($recoveryRun['telemetry_adapter']['failure_identity'] ?? null) === null,
    'recovered run snapshot did not expose the expected pipeline telemetry adapter contract.'
);
king_pipeline_adapter_assert(
    ($recoveryRun['steps'][0]['telemetry_adapter']['attempt_identity'] ?? null) === 'run-1:attempt-2'
    && ($recoveryRun['steps'][0]['telemetry_adapter']['retry_identity'] ?? null) === 'run-1:attempt-2'
    && ($recoveryRun['steps'][0]['telemetry_adapter']['partition_id'] ?? null) === 'partition-eu-west-1'
    && ($recoveryRun['steps'][0]['telemetry_adapter']['batch_id'] ?? null) === 'batch-recovery-001'
    && ($recoveryRun['steps'][0]['telemetry_adapter']['failure_identity'] ?? null) === null,
    'recovered step snapshot did not preserve the expected retry and batch identity.'
);
king_pipeline_adapter_assert(
    is_array($recoveryComponent)
    && ($recoveryComponent['telemetry_adapter_contract'] ?? null) === 'run_partition_batch_retry_failure_identity'
    && ($recoveryComponent['telemetry_identity_surface'] ?? null) === 'king_pipeline_orchestrator_get_run+otlp_spans+otlp_logs+telemetry_metrics',
    'pipeline orchestrator component info did not claim the telemetry adapter contract.'
);
king_pipeline_adapter_assert(
    is_array($recoveryMetrics)
    && (int) (($recoveryMetrics['pipeline.run.count']['value'] ?? 0)) === 1
    && (int) (($recoveryMetrics['pipeline.retry.count']['value'] ?? 0)) === 1
    && (int) (($recoveryMetrics['pipeline.partition.count']['value'] ?? 0)) === 1
    && (int) (($recoveryMetrics['pipeline.batch.count']['value'] ?? 0)) === 1,
    'recovered worker did not expose the expected pipeline telemetry adapter metrics before flush.'
);

$recoverySpans = king_pipeline_adapter_collect_spans($recoveryScenario['capture']);
$recoveryMetricsCapture = king_pipeline_adapter_collect_metric_values($recoveryScenario['capture']);
$recoveryRunSpan = king_pipeline_adapter_find_span(
    $recoverySpans,
    'pipeline-orchestrator-run',
    'run',
    'run-1:attempt-2'
);
$recoveryStepSpan = king_pipeline_adapter_find_span(
    $recoverySpans,
    'pipeline-orchestrator-step',
    'step',
    'run-1:attempt-2'
);
king_pipeline_adapter_assert(
    ($recoveryRunSpan['attributes']['run_id'] ?? null) === 'run-1'
    && ($recoveryRunSpan['attributes']['retry_identity'] ?? null) === 'run-1:attempt-2'
    && ($recoveryRunSpan['attributes']['execution_backend'] ?? null) === 'file_worker'
    && ($recoveryRunSpan['attributes']['topology_scope'] ?? null) === 'same_host_file_worker'
    && ($recoveryRunSpan['attributes']['outcome'] ?? null) === 'completed',
    'collector did not observe the expected recovered run span identity.'
);
king_pipeline_adapter_assert(
    ($recoveryStepSpan['attributes']['partition_id'] ?? null) === 'partition-eu-west-1'
    && ($recoveryStepSpan['attributes']['batch_id'] ?? null) === 'batch-recovery-001'
    && ($recoveryStepSpan['attributes']['step_index'] ?? null) === '0'
    && ($recoveryStepSpan['attributes']['step_tool'] ?? null) === 'summarizer'
    && ($recoveryStepSpan['attributes']['outcome'] ?? null) === 'completed',
    'collector did not observe the expected recovered step span identity.'
);
king_pipeline_adapter_assert(
    ($recoveryMetricsCapture['pipeline.run.count'] ?? null) === 1
    && ($recoveryMetricsCapture['pipeline.retry.count'] ?? null) === 1
    && ($recoveryMetricsCapture['pipeline.partition.count'] ?? null) === 1
    && ($recoveryMetricsCapture['pipeline.batch.count'] ?? null) === 1,
    'collector did not observe the expected recovered pipeline metrics.'
);
king_pipeline_adapter_assert(
    king_pipeline_adapter_collect_logs($recoveryScenario['capture']) === [],
    'successful recovery unexpectedly emitted pipeline failure logs.'
);

$failure = $failureScenario['result'];
$failureRun = $failure['run'] ?? null;
$failureMetrics = $failure['live_metrics'] ?? null;
king_pipeline_adapter_assert(
    ($failure['exception_class'] ?? null) === 'King\\TimeoutException'
    && is_string($failure['exception_message'] ?? null)
    && $failure['exception_message'] !== '',
    'local timeout scenario did not surface the expected timeout exception.'
);
king_pipeline_adapter_assert(
    ($failure['flush_result'] ?? false) === true
    && (int) (($failure['status']['queue_size'] ?? -1)) === 0,
    'local timeout scenario did not flush telemetry cleanly.'
);
king_pipeline_adapter_assert(
    is_array($failureRun)
    && ($failureRun['status'] ?? null) === 'failed'
    && ($failureRun['error_classification']['category'] ?? null) === 'timeout'
    && ($failureRun['telemetry_adapter']['attempt_identity'] ?? null) === 'run-1:attempt-1'
    && ($failureRun['telemetry_adapter']['retry_identity'] ?? null) === null
    && ($failureRun['telemetry_adapter']['failure_identity'] ?? null) === 'run-1:attempt-1:failed:timeout:0'
    && ($failureRun['telemetry_adapter']['failed_partition_id'] ?? null) === 'partition-us-east-1'
    && ($failureRun['telemetry_adapter']['failed_batch_id'] ?? null) === 'batch-failure-001',
    'failed run snapshot did not expose the expected failure identity contract.'
);
king_pipeline_adapter_assert(
    ($failureRun['steps'][0]['telemetry_adapter']['attempt_identity'] ?? null) === 'run-1:attempt-1'
    && ($failureRun['steps'][0]['telemetry_adapter']['partition_id'] ?? null) === 'partition-us-east-1'
    && ($failureRun['steps'][0]['telemetry_adapter']['batch_id'] ?? null) === 'batch-failure-001'
    && ($failureRun['steps'][0]['telemetry_adapter']['failure_identity'] ?? null) === 'run-1:attempt-1:failed:timeout:0',
    'failed step snapshot did not expose the expected failure adapter identity.'
);
king_pipeline_adapter_assert(
    is_array($failureMetrics)
    && (int) (($failureMetrics['pipeline.run.count']['value'] ?? 0)) === 1
    && (int) (($failureMetrics['pipeline.partition.count']['value'] ?? 0)) === 1
    && (int) (($failureMetrics['pipeline.batch.count']['value'] ?? 0)) === 1
    && (int) (($failureMetrics['pipeline.failure.count']['value'] ?? 0)) === 1,
    'local timeout scenario did not expose the expected failure metrics before flush.'
);

$failureSpans = king_pipeline_adapter_collect_spans($failureScenario['capture']);
$failureLogs = king_pipeline_adapter_collect_logs($failureScenario['capture']);
$failureMetricsCapture = king_pipeline_adapter_collect_metric_values($failureScenario['capture']);
$failureRunSpan = king_pipeline_adapter_find_span(
    $failureSpans,
    'pipeline-orchestrator-run',
    'run',
    'run-1:attempt-1'
);
$failureStepSpan = king_pipeline_adapter_find_span(
    $failureSpans,
    'pipeline-orchestrator-step',
    'step',
    'run-1:attempt-1'
);
$failureLog = king_pipeline_adapter_find_failure_log(
    $failureLogs,
    'run-1:attempt-1:failed:timeout:0'
);
king_pipeline_adapter_assert(
    ($failureRunSpan['attributes']['failure_identity'] ?? null) === 'run-1:attempt-1:failed:timeout:0'
    && ($failureRunSpan['attributes']['failure_category'] ?? null) === 'timeout'
    && ($failureRunSpan['attributes']['retry_disposition'] ?? null) === 'caller_managed_retry'
    && ($failureRunSpan['attributes']['outcome'] ?? null) === 'failed',
    'collector did not observe the expected failed run span identity.'
);
king_pipeline_adapter_assert(
    ($failureStepSpan['attributes']['partition_id'] ?? null) === 'partition-us-east-1'
    && ($failureStepSpan['attributes']['batch_id'] ?? null) === 'batch-failure-001'
    && ($failureStepSpan['attributes']['failure_identity'] ?? null) === 'run-1:attempt-1:failed:timeout:0'
    && ($failureStepSpan['attributes']['failure_category'] ?? null) === 'timeout'
    && ($failureStepSpan['attributes']['outcome'] ?? null) === 'failed',
    'collector did not observe the expected failed step span identity.'
);
king_pipeline_adapter_assert(
    ($failureLog['attributes']['run_id'] ?? null) === 'run-1'
    && ($failureLog['attributes']['attempt_identity'] ?? null) === 'run-1:attempt-1'
    && ($failureLog['attributes']['failure_identity'] ?? null) === 'run-1:attempt-1:failed:timeout:0'
    && ($failureLog['attributes']['failure_category'] ?? null) === 'timeout'
    && ($failureLog['attributes']['retry_disposition'] ?? null) === 'caller_managed_retry'
    && ($failureLog['attributes']['partition_id'] ?? null) === 'partition-us-east-1'
    && ($failureLog['attributes']['batch_id'] ?? null) === 'batch-failure-001',
    'collector did not observe the expected pipeline failure log identity.'
);
king_pipeline_adapter_assert(
    ($failureMetricsCapture['pipeline.run.count'] ?? null) === 1
    && ($failureMetricsCapture['pipeline.partition.count'] ?? null) === 1
    && ($failureMetricsCapture['pipeline.batch.count'] ?? null) === 1
    && ($failureMetricsCapture['pipeline.failure.count'] ?? null) === 1,
    'collector did not observe the expected failed pipeline metrics.'
);

echo "OK\n";
?>
--EXPECT--
OK
