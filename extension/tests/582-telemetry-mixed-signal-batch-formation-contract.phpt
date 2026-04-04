--TEST--
King telemetry forms mixed signal flushes into bounded FIFO batches under span metric and log pressure
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

$collector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
        'batch_processor_max_queue_size' => 8,
        'logs_exporter_batch_size' => 2,
        'exporter_timeout_ms' => 500,
    ]);

    foreach ([
        'batch.metric.one' => 1,
        'batch.metric.two' => 2,
        'batch.metric.three' => 3,
    ] as $metricName => $value) {
        king_telemetry_record_metric($metricName, $value, ['metric_name' => $metricName]);
    }

    foreach (['batch-span-one', 'batch-span-two', 'batch-span-three'] as $name) {
        $spanId = king_telemetry_start_span($name, ['span_name' => $name]);
        var_dump(king_telemetry_end_span($spanId, ['span_name' => $name]));
    }

    foreach (['batch-log-one', 'batch-log-two', 'batch-log-three'] as $message) {
        king_telemetry_log('warn', $message, ['log_name' => $message]);
    }

    var_dump(king_telemetry_flush());
    $afterFirstFlush = king_telemetry_get_status();
    var_dump($afterFirstFlush['active_metrics']);
    var_dump($afterFirstFlush['pending_span_count']);
    var_dump($afterFirstFlush['pending_log_count']);
    var_dump($afterFirstFlush['queue_size']);
    var_dump($afterFirstFlush['export_success_count']);
    var_dump($afterFirstFlush['flush_count']);

    var_dump(king_telemetry_flush());
    $afterSecondFlush = king_telemetry_get_status();
    var_dump($afterSecondFlush['active_metrics']);
    var_dump($afterSecondFlush['pending_span_count']);
    var_dump($afterSecondFlush['pending_log_count']);
    var_dump($afterSecondFlush['queue_size']);
    var_dump($afterSecondFlush['export_success_count']);
    var_dump($afterSecondFlush['flush_count']);
} finally {
    $capture = king_telemetry_test_stop_collector($collector);
}

$firstBatchId = $capture[0]['headers']['x-king-telemetry-batch-id'] ?? '';
$secondBatchId = $capture[3]['headers']['x-king-telemetry-batch-id'] ?? '';

var_dump(count($capture));
var_dump($capture[0]['path']);
var_dump($capture[1]['path']);
var_dump($capture[2]['path']);
var_dump($capture[3]['path']);
var_dump($capture[4]['path']);
var_dump($capture[5]['path']);
var_dump($firstBatchId !== '');
var_dump($secondBatchId !== '');
var_dump($firstBatchId !== $secondBatchId);
var_dump(($capture[1]['headers']['x-king-telemetry-batch-id'] ?? '') === $firstBatchId);
var_dump(($capture[2]['headers']['x-king-telemetry-batch-id'] ?? '') === $firstBatchId);
var_dump(($capture[4]['headers']['x-king-telemetry-batch-id'] ?? '') === $secondBatchId);
var_dump(($capture[5]['headers']['x-king-telemetry-batch-id'] ?? '') === $secondBatchId);

var_dump(str_contains($capture[0]['body'], '"batch.metric.one"'));
var_dump(str_contains($capture[0]['body'], '"batch.metric.two"'));
var_dump(str_contains($capture[0]['body'], '"batch.metric.three"'));
var_dump(str_contains($capture[3]['body'], '"batch.metric.three"'));

var_dump(str_contains($capture[1]['body'], '"batch-span-one"'));
var_dump(str_contains($capture[1]['body'], '"batch-span-two"'));
var_dump(str_contains($capture[1]['body'], '"batch-span-three"'));
var_dump(str_contains($capture[4]['body'], '"batch-span-three"'));

var_dump(str_contains($capture[2]['body'], '"batch-log-one"'));
var_dump(str_contains($capture[2]['body'], '"batch-log-two"'));
var_dump(str_contains($capture[2]['body'], '"batch-log-three"'));
var_dump(str_contains($capture[5]['body'], '"batch-log-three"'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
int(0)
int(0)
int(1)
int(1)
int(1)
bool(true)
int(0)
int(0)
int(0)
int(0)
int(2)
int(1)
int(6)
string(11) "/v1/metrics"
string(10) "/v1/traces"
string(8) "/v1/logs"
string(11) "/v1/metrics"
string(10) "/v1/traces"
string(8) "/v1/logs"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
