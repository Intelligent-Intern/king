--TEST--
King telemetry honors always-on, always-off, and parent-based probability sampling on local root spans
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

function king_telemetry_sampling_format_traceparent(array $context): string
{
    return sprintf(
        '00-%s-%s-%02x',
        $context['trace_id'],
        $context['span_id'],
        (int) $context['trace_flags']
    );
}

function king_telemetry_sampling_collect_names(array $collectorCapture): array
{
    $names = [];

    foreach ($collectorCapture as $entry) {
        if (($entry['path'] ?? null) !== '/v1/traces') {
            throw new RuntimeException('telemetry sampling collector observed an unexpected OTLP path.');
        }

        $payload = json_decode((string) ($entry['body'] ?? ''), true);
        if (!is_array($payload)) {
            throw new RuntimeException('telemetry sampling collector emitted malformed JSON.');
        }

        $spans = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? null;
        if (!is_array($spans)) {
            throw new RuntimeException('telemetry sampling collector emitted malformed OTLP span data.');
        }

        foreach ($spans as $span) {
            if (is_array($span) && isset($span['name'])) {
                $names[] = $span['name'];
            }
        }
    }

    return $names;
}

function king_telemetry_sampling_assert_sampled_root(
    string $name,
    array $initConfig,
    int $expectedPendingSpans,
    int $expectedTraceFlags
): void {
    if (!king_telemetry_init($initConfig)) {
        throw new RuntimeException('telemetry sampling fixture failed to initialize runtime.');
    }

    $rootId = king_telemetry_start_span($name, ['mode' => $name]);
    $context = king_telemetry_get_trace_context();
    if (!is_array($context)
        || ($context['span_id'] ?? null) !== $rootId
        || strlen((string) ($context['trace_id'] ?? '')) !== 32
        || (int) ($context['trace_flags'] ?? -1) !== $expectedTraceFlags) {
        throw new RuntimeException("telemetry sampling fixture produced an unexpected live root context for {$name}.");
    }

    $headers = king_telemetry_inject_context(['x-test' => $name]);
    if (($headers['x-test'] ?? null) !== $name
        || ($headers['traceparent'] ?? null) !== king_telemetry_sampling_format_traceparent($context)) {
        throw new RuntimeException("telemetry sampling fixture injected an unexpected traceparent for {$name}.");
    }

    if (!king_telemetry_end_span($rootId)) {
        throw new RuntimeException("telemetry sampling fixture failed to close {$name}.");
    }
    if (king_telemetry_get_trace_context() !== null) {
        throw new RuntimeException("telemetry sampling fixture leaked the closed root context for {$name}.");
    }

    $status = king_telemetry_get_status();
    if (!is_array($status) || (int) ($status['pending_span_count'] ?? -1) !== $expectedPendingSpans) {
        throw new RuntimeException("telemetry sampling fixture produced an unexpected pending span count for {$name}.");
    }

    if (!king_telemetry_flush()) {
        throw new RuntimeException("telemetry sampling fixture failed to flush {$name}.");
    }
}

$collector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
]);
$collectorCapture = [];

try {
    king_telemetry_sampling_assert_sampled_root(
        'sampling-always-on',
        [
            'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
            'exporter_timeout_ms' => 500,
            'traces_sampler_type' => 'always_on',
        ],
        1,
        1
    );

    king_telemetry_sampling_assert_sampled_root(
        'sampling-always-off',
        [
            'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
            'exporter_timeout_ms' => 500,
            'traces_sampler_type' => 'always_off',
        ],
        0,
        0
    );

    king_telemetry_sampling_assert_sampled_root(
        'sampling-ratio-one',
        [
            'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
            'exporter_timeout_ms' => 500,
            'traces_sampler_type' => 'parent_based_probability',
            'traces_sampler_ratio' => 1.0,
        ],
        1,
        1
    );

    king_telemetry_sampling_assert_sampled_root(
        'sampling-ratio-zero',
        [
            'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
            'exporter_timeout_ms' => 500,
            'traces_sampler_type' => 'parent_based_probability',
            'traces_sampler_ratio' => 0.0,
        ],
        0,
        0
    );
} finally {
    $collectorCapture = king_telemetry_test_stop_collector($collector);
}

$exportedNames = king_telemetry_sampling_collect_names($collectorCapture);
sort($exportedNames);
if ($exportedNames !== ['sampling-always-on', 'sampling-ratio-one']) {
    throw new RuntimeException('telemetry sampling fixture exported an unexpected set of spans.');
}

echo "OK\n";
?>
--EXPECT--
OK
