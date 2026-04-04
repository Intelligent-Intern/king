--TEST--
King telemetry bounds live metric cardinality and exposes flush CPU self-metrics under load
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

$collector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
]);
$capture = [];

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
        'batch_processor_max_queue_size' => 2,
        'exporter_timeout_ms' => 500,
    ]);

    king_telemetry_record_metric('metric.alpha', 1, null, 'counter');
    king_telemetry_record_metric('metric.beta', 2, null, 'counter');
    king_telemetry_record_metric('metric.alpha', 5, null, 'counter');
    king_telemetry_record_metric('metric.gamma', 3, null, 'counter');
    king_telemetry_record_metric('metric.delta', 4, null, 'counter');

    $liveMetrics = king_telemetry_get_metrics();
    $beforeFlush = king_telemetry_get_status();

    var_dump($beforeFlush['active_metrics']);
    var_dump($beforeFlush['metric_registry_limit']);
    var_dump($beforeFlush['metric_drop_count']);
    var_dump($beforeFlush['metric_registry_high_watermark']);
    var_dump(array_keys($liveMetrics));
    var_dump($liveMetrics['metric.alpha']['value']);
    var_dump($liveMetrics['metric.beta']['value']);
    var_dump(array_key_exists('metric.gamma', $liveMetrics));
    var_dump(array_key_exists('metric.delta', $liveMetrics));

    var_dump(king_telemetry_flush());
    $afterFlush = king_telemetry_get_status();
} finally {
    $capture = king_telemetry_test_stop_collector($collector);
}

var_dump($afterFlush['active_metrics']);
var_dump($afterFlush['metric_drop_count']);
var_dump($afterFlush['queue_size']);
var_dump($afterFlush['export_success_count']);
var_dump($afterFlush['last_flush_cpu_ns'] > 0);
var_dump($afterFlush['flush_cpu_high_water_ns'] >= $afterFlush['last_flush_cpu_ns']);

var_dump(count($capture));
var_dump($capture[0]['path']);
var_dump(str_contains($capture[0]['body'], '"name":"metric.alpha"'));
var_dump(str_contains($capture[0]['body'], '"name":"metric.beta"'));
var_dump(str_contains($capture[0]['body'], '"name":"metric.gamma"'));
var_dump(str_contains($capture[0]['body'], '"name":"metric.delta"'));
?>
--EXPECT--
int(2)
int(2)
int(2)
int(2)
array(2) {
  [0]=>
  string(12) "metric.alpha"
  [1]=>
  string(11) "metric.beta"
}
float(6)
float(2)
bool(false)
bool(false)
bool(true)
int(0)
int(2)
int(0)
int(1)
bool(true)
bool(true)
int(1)
string(11) "/v1/metrics"
bool(true)
bool(true)
bool(false)
bool(false)
