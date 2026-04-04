--TEST--
King telemetry preserves orchestrator span hierarchies across process resume and file-worker boundaries
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

function king_orchestrator_telemetry_boundary_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_orchestrator_telemetry_boundary_decode_json(array $result, string $label): array
{
    king_orchestrator_telemetry_boundary_assert(
        $result['status'] === 0,
        $label . ' exited with status ' . json_encode($result['status']) . ' and stderr ' . json_encode($result['stderr'])
    );
    king_orchestrator_telemetry_boundary_assert(
        trim($result['stderr']) === '',
        $label . ' wrote unexpected stderr: ' . json_encode($result['stderr'])
    );

    $decoded = json_decode(trim($result['stdout']), true);
    king_orchestrator_telemetry_boundary_assert(
        is_array($decoded),
        $label . ' did not return valid JSON: ' . json_encode($result['stdout'])
    );

    return $decoded;
}

function king_orchestrator_telemetry_boundary_read_json_file(string $path, string $label): array
{
    king_orchestrator_telemetry_boundary_assert(
        is_file($path),
        $label . ' did not materialize a capture file.'
    );

    $decoded = json_decode((string) file_get_contents($path), true);
    king_orchestrator_telemetry_boundary_assert(
        is_array($decoded),
        $label . ' capture file did not contain valid JSON.'
    );

    return $decoded;
}

function king_orchestrator_telemetry_boundary_read_run_snapshot(
    array $harness,
    string $backend,
    string $observerScript,
    string $runId
): array {
    $result = king_orchestrator_failover_harness_exec($harness, $backend, $observerScript, [$runId]);

    if ($result['status'] !== 0 || trim($result['stderr']) !== '') {
        return ['exists' => false];
    }

    $decoded = json_decode(trim($result['stdout']), true);
    if (!is_array($decoded)) {
        return ['exists' => false];
    }

    return $decoded;
}

function king_orchestrator_telemetry_boundary_collect_exported_spans(array $collectorCapture): array
{
    $spans = [];

    foreach ($collectorCapture as $entry) {
        if (($entry['path'] ?? null) !== '/v1/traces') {
            continue;
        }

        $payload = json_decode((string) ($entry['body'] ?? ''), true);
        king_orchestrator_telemetry_boundary_assert(
            is_array($payload),
            'collector emitted malformed OTLP JSON while checking orchestrator boundary spans.'
        );

        $bodySpans = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? null;
        king_orchestrator_telemetry_boundary_assert(
            is_array($bodySpans),
            'collector emitted malformed OTLP span data while checking orchestrator boundary spans.'
        );

        foreach ($bodySpans as $span) {
            if (is_array($span) && ($span['name'] ?? null) === 'pipeline-orchestrator-boundary') {
                $spans[] = $span;
            }
        }
    }

    return $spans;
}

function king_orchestrator_telemetry_boundary_span_attributes(array $span): array
{
    $attributes = [];

    foreach (($span['attributes'] ?? []) as $attribute) {
        if (!is_array($attribute) || !isset($attribute['key'])) {
            continue;
        }

        $value = $attribute['value'] ?? [];
        if (is_array($value) && array_key_exists('stringValue', $value)) {
            $attributes[$attribute['key']] = $value['stringValue'];
            continue;
        }
        if (is_array($value) && array_key_exists('intValue', $value)) {
            $attributes[$attribute['key']] = (string) $value['intValue'];
            continue;
        }

        $attributes[$attribute['key']] = null;
    }

    return $attributes;
}

function king_orchestrator_telemetry_boundary_find_span(
    array $exportedSpans,
    string $origin,
    string $backend
): array {
    foreach ($exportedSpans as $span) {
        if (($span['name'] ?? null) !== 'pipeline-orchestrator-boundary') {
            continue;
        }

        $attributes = king_orchestrator_telemetry_boundary_span_attributes($span);
        if (
            ($attributes['boundary_origin'] ?? null) === $origin
            && ($attributes['execution_backend'] ?? null) === $backend
            && ($attributes['component'] ?? null) === 'pipeline_orchestrator'
            && ($attributes['hierarchy_source'] ?? null) === 'distributed_parent_context'
            && ($attributes['run_id'] ?? null) === 'run-1'
        ) {
            return $span;
        }
    }

    throw new RuntimeException(
        'collector did not emit the expected orchestrator boundary span for '
        . $origin . '/' . $backend
    );
}

function king_orchestrator_telemetry_boundary_assert_worker_result(array $result, string $text): void
{
    $hasBeforeContext = array_key_exists('before_context', $result);
    $beforeContext = $hasBeforeContext ? $result['before_context'] : 'missing';
    $hasAfterRunContext = array_key_exists('after_run_context', $result);
    $afterRunContext = $hasAfterRunContext ? $result['after_run_context'] : 'missing';

    king_orchestrator_telemetry_boundary_assert(
        $hasBeforeContext && $beforeContext === null,
        'boundary runner started with a stale active span.'
    );
    king_orchestrator_telemetry_boundary_assert(
        $hasAfterRunContext && $afterRunContext === null,
        'boundary runner leaked the closed boundary span after completion.'
    );
    king_orchestrator_telemetry_boundary_assert(
        ($result['flush_result'] ?? false) === true,
        'boundary runner failed to flush the boundary span.'
    );

    $status = $result['after_flush_status'] ?? null;
    king_orchestrator_telemetry_boundary_assert(
        is_array($status)
        && (int) ($status['pending_span_count'] ?? -1) === 0
        && (int) ($status['pending_log_count'] ?? -1) === 0
        && (int) ($status['queue_size'] ?? -1) === 0,
        'boundary runner left telemetry residue behind after flushing: '
        . json_encode($status, JSON_UNESCAPED_SLASHES)
    );

    $work = $result['work'] ?? null;
    king_orchestrator_telemetry_boundary_assert(
        is_array($work)
        && ($work['status'] ?? null) === 'completed'
        && ($work['result']['text'] ?? null) === $text,
        'boundary runner did not complete the queued orchestrator work as expected.'
    );
}

function king_orchestrator_telemetry_process_resume_scenario(int $collectorPort): array
{
    $harness = king_orchestrator_failover_harness_create();

    try {
        $parentCapturePath = $harness['root'] . '/process-parent-context.json';
        $controllerScript = king_orchestrator_failover_harness_write_script($harness, 'process-boundary-controller', <<<'PHP'
<?php
function summarizer_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected handler input');
    }

    return $input;
}

$collectorPort = (int) $argv[1];
$capturePath = $argv[2];

king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collectorPort,
    'exporter_timeout_ms' => 500,
]);
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_pipeline_orchestrator_register_handler('summarizer', 'summarizer_handler');
king_telemetry_start_span('orchestrator-process-parent', [
    'boundary' => 'process_resume',
    'role' => 'controller_parent',
]);
if (file_put_contents($capturePath, json_encode(king_telemetry_get_trace_context(), JSON_UNESCAPED_SLASHES)) === false) {
    fwrite(STDERR, "failed to persist process parent context\n");
    exit(2);
}
king_pipeline_orchestrator_run(
    ['text' => 'process-boundary'],
    [['tool' => 'summarizer', 'delay_ms' => 5000]],
    ['trace_id' => 'orchestrator-process-boundary']
);
PHP);
        $observerScript = king_orchestrator_failover_harness_write_script($harness, 'process-boundary-observer', <<<'PHP'
<?php
$run = king_pipeline_orchestrator_get_run($argv[1]);
if ($run === false) {
    echo json_encode(['exists' => false]), "\n";
    return;
}
echo json_encode($run, JSON_UNESCAPED_SLASHES), "\n";
PHP);
$resumeScript = king_orchestrator_failover_harness_write_script($harness, 'process-boundary-resume', <<<'PHP'
<?php
function summarizer_handler(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input)) {
        throw new RuntimeException('unexpected handler input');
    }

    return $input;
}

$collectorPort = (int) $argv[1];
$runId = $argv[2];

king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collectorPort,
    'exporter_timeout_ms' => 500,
]);
king_pipeline_orchestrator_register_handler('summarizer', 'summarizer_handler');
$beforeContext = king_telemetry_get_trace_context();
$result = king_pipeline_orchestrator_resume_run($runId);
$afterRunContext = king_telemetry_get_trace_context();
$flushResult = king_telemetry_flush();
$afterFlushStatus = king_telemetry_get_status();

echo json_encode([
    'before_context' => $beforeContext,
    'work' => [
        'status' => 'completed',
        'result' => $result,
    ],
    'after_run_context' => $afterRunContext,
    'flush_result' => $flushResult,
    'after_flush_status' => $afterFlushStatus,
], JSON_UNESCAPED_SLASHES), "\n";
PHP);

        $controller = king_orchestrator_failover_harness_spawn(
            $harness,
            'local',
            $controllerScript,
            [$collectorPort, $parentCapturePath],
            ['king.orchestrator_enable_distributed_tracing' => '1']
        );

        $runningObserved = king_orchestrator_failover_harness_wait_for(
            static function () use ($harness, $observerScript, $parentCapturePath): bool {
                if (!is_file($parentCapturePath)) {
                    return false;
                }

                $snapshot = king_orchestrator_telemetry_boundary_read_run_snapshot(
                    $harness,
                    'local',
                    $observerScript,
                    'run-1'
                );

                return ($snapshot['run_id'] ?? null) === 'run-1'
                    && ($snapshot['status'] ?? null) === 'running'
                    && ($snapshot['finished_at'] ?? null) === 0;
            }
        );
        king_orchestrator_telemetry_boundary_assert(
            $runningObserved,
            'process-resume scenario never observed a persisted running run with a captured parent context.'
        );

        $controllerCrash = king_orchestrator_failover_harness_crash_process($controller);
        king_orchestrator_telemetry_boundary_assert(
            $controllerCrash['status'] !== 0,
            'process-resume controller exited cleanly after forced crash.'
        );

        return [
            'parent_context' => king_orchestrator_telemetry_boundary_read_json_file(
                $parentCapturePath,
                'process-resume parent context'
            ),
            'resume' => king_orchestrator_telemetry_boundary_decode_json(
                king_orchestrator_failover_harness_exec(
                    $harness,
                    'local',
                    $resumeScript,
                    [$collectorPort, 'run-1'],
                    ['king.orchestrator_enable_distributed_tracing' => '1']
                ),
                'process-resume'
            ),
        ];
    } finally {
        king_orchestrator_failover_harness_destroy($harness);
    }
}

function king_orchestrator_telemetry_file_worker_scenario(int $collectorPort): array
{
    $harness = king_orchestrator_failover_harness_create();

    try {
        $parentCapturePath = $harness['root'] . '/file-worker-parent-context.json';
        $dispatchScript = king_orchestrator_failover_harness_write_script($harness, 'file-worker-boundary-dispatch', <<<'PHP'
<?php
$collectorPort = (int) $argv[1];
$capturePath = $argv[2];

king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collectorPort,
    'exporter_timeout_ms' => 500,
]);
king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]);
king_telemetry_start_span('orchestrator-file-worker-parent', [
    'boundary' => 'file_worker',
    'role' => 'controller_parent',
]);
if (file_put_contents($capturePath, json_encode(king_telemetry_get_trace_context(), JSON_UNESCAPED_SLASHES)) === false) {
    fwrite(STDERR, "failed to persist file-worker parent context\n");
    exit(2);
}
$dispatch = king_pipeline_orchestrator_dispatch(
    ['text' => 'file-worker-boundary'],
    [['tool' => 'summarizer']],
    ['trace_id' => 'orchestrator-file-worker-boundary']
);
echo json_encode($dispatch, JSON_UNESCAPED_SLASHES), "\n";
PHP);
$workerScript = king_orchestrator_failover_harness_write_script($harness, 'file-worker-boundary-worker', <<<'PHP'
<?php
$collectorPort = (int) $argv[1];

king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collectorPort,
    'exporter_timeout_ms' => 500,
]);
$beforeContext = king_telemetry_get_trace_context();
$work = king_pipeline_orchestrator_worker_run_next();
$afterRunContext = king_telemetry_get_trace_context();
$flushResult = king_telemetry_flush();
$afterFlushStatus = king_telemetry_get_status();

echo json_encode([
    'before_context' => $beforeContext,
    'work' => $work,
    'after_run_context' => $afterRunContext,
    'flush_result' => $flushResult,
    'after_flush_status' => $afterFlushStatus,
], JSON_UNESCAPED_SLASHES), "\n";
PHP);

        $dispatch = king_orchestrator_telemetry_boundary_decode_json(
            king_orchestrator_failover_harness_exec(
                $harness,
                'file_worker',
                $dispatchScript,
                [$collectorPort, $parentCapturePath],
                ['king.orchestrator_enable_distributed_tracing' => '1']
            ),
            'file-worker-dispatch'
        );
        king_orchestrator_telemetry_boundary_assert(
            ($dispatch['backend'] ?? null) === 'file_worker'
            && ($dispatch['status'] ?? null) === 'queued'
            && ($dispatch['run_id'] ?? null) === 'run-1',
            'file-worker controller did not queue the expected run.'
        );

        return [
            'parent_context' => king_orchestrator_telemetry_boundary_read_json_file(
                $parentCapturePath,
                'file-worker parent context'
            ),
            'worker' => king_orchestrator_telemetry_boundary_decode_json(
                king_orchestrator_failover_harness_exec(
                    $harness,
                    'file_worker',
                    $workerScript,
                    [$collectorPort],
                    ['king.orchestrator_enable_distributed_tracing' => '1']
                ),
                'file-worker-runner'
            ),
        ];
    } finally {
        king_orchestrator_failover_harness_destroy($harness);
    }
}

$collector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
]);
$processScenario = [];
$workerScenario = [];
$collectorCapture = [];

try {
    $processScenario = king_orchestrator_telemetry_process_resume_scenario($collector['port']);
    $workerScenario = king_orchestrator_telemetry_file_worker_scenario($collector['port']);
} finally {
    $collectorCapture = king_telemetry_test_stop_collector($collector);
}

king_orchestrator_telemetry_boundary_assert_worker_result(
    $processScenario['resume'] ?? [],
    'process-boundary'
);
king_orchestrator_telemetry_boundary_assert_worker_result(
    $workerScenario['worker'] ?? [],
    'file-worker-boundary'
);

$processParent = $processScenario['parent_context'] ?? null;
$workerParent = $workerScenario['parent_context'] ?? null;
king_orchestrator_telemetry_boundary_assert(
    is_array($processParent)
    && strlen((string) ($processParent['trace_id'] ?? '')) === 32
    && strlen((string) ($processParent['span_id'] ?? '')) === 16,
    'process-resume parent span context did not materialize as a real trace context.'
);
king_orchestrator_telemetry_boundary_assert(
    is_array($workerParent)
    && strlen((string) ($workerParent['trace_id'] ?? '')) === 32
    && strlen((string) ($workerParent['span_id'] ?? '')) === 16,
    'file-worker parent span context did not materialize as a real trace context.'
);

$exportedSpans = king_orchestrator_telemetry_boundary_collect_exported_spans($collectorCapture);
king_orchestrator_telemetry_boundary_assert(
    count($exportedSpans) === 2,
    'collector did not observe exactly the two orchestrator boundary span flushes.'
);

$processBoundary = king_orchestrator_telemetry_boundary_find_span(
    $exportedSpans,
    'process_resume',
    'local'
);
$workerBoundary = king_orchestrator_telemetry_boundary_find_span(
    $exportedSpans,
    'file_worker',
    'file_worker'
);

king_orchestrator_telemetry_boundary_assert(
    ($processBoundary['traceId'] ?? null) === ($processParent['trace_id'] ?? null)
    && ($processBoundary['parentSpanId'] ?? null) === ($processParent['span_id'] ?? null)
    && ($processBoundary['spanId'] ?? null) !== ($processParent['span_id'] ?? null),
    'process-resume boundary span did not stay attached to the original controller parent span.'
);
king_orchestrator_telemetry_boundary_assert(
    ($workerBoundary['traceId'] ?? null) === ($workerParent['trace_id'] ?? null)
    && ($workerBoundary['parentSpanId'] ?? null) === ($workerParent['span_id'] ?? null)
    && ($workerBoundary['spanId'] ?? null) !== ($workerParent['span_id'] ?? null),
    'file-worker boundary span did not stay attached to the original controller parent span.'
);

echo "OK\n";
?>
--EXPECT--
OK
