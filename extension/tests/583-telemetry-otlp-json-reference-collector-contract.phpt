--TEST--
King telemetry emits OTLP JSON payloads that pass the reference collector validator
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

$collector = king_telemetry_test_start_collector([
    ['validate' => 'otlp_reference'],
    ['validate' => 'otlp_reference'],
    ['validate' => 'otlp_reference'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
        'service_name' => 'reference-collector-proof',
    ]);

    king_telemetry_record_metric('requests_total', 5.0, ['route' => '/checkout'], 'counter');
    king_telemetry_record_metric('cpu_utilization', 12.5, ['host' => 'api-1'], 'gauge');
    king_telemetry_record_metric('response_time_ms', 42.0, ['route' => '/checkout'], 'histogram');
    king_telemetry_record_metric('queue_delay_ms', 17.0, ['route' => '/checkout'], 'summary');

    $spanId = king_telemetry_start_span('checkout', ['component' => 'reference-collector-proof']);
    king_telemetry_log('warn', 'inventory low', ['sku' => 'A-123', 'remaining' => 2]);
    var_dump(king_telemetry_end_span($spanId, ['cart_id' => 123, 'status' => 'ok']));

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_success_count']);
    var_dump($status['export_failure_count']);
} finally {
    $capture = king_telemetry_test_stop_collector($collector);
}

$metricsPayload = json_decode((string) $capture[0]['body'], true);
$metrics = $metricsPayload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];

var_dump(count($capture));
var_dump($capture[0]['path']);
var_dump($capture[0]['validation']['ok']);
var_dump($capture[0]['validation']['signal']);
var_dump(isset($metrics[0]['sum']['dataPoints'][0]['attributes']));
var_dump(isset($metrics[1]['gauge']['dataPoints'][0]['asDouble']));
var_dump(isset($metrics[2]['histogram']['dataPoints'][0]['bucketCounts']));
var_dump(isset($metrics[3]['summary']['dataPoints'][0]['quantileValues']));
var_dump($capture[1]['path']);
var_dump($capture[1]['validation']['ok']);
var_dump($capture[1]['validation']['signal']);
var_dump($capture[2]['path']);
var_dump($capture[2]['validation']['ok']);
var_dump($capture[2]['validation']['signal']);
?>
--EXPECT--
bool(true)
bool(true)
int(0)
int(1)
int(0)
int(3)
string(11) "/v1/metrics"
bool(true)
string(7) "metrics"
bool(true)
bool(true)
bool(true)
bool(true)
string(10) "/v1/traces"
bool(true)
string(6) "traces"
string(8) "/v1/logs"
bool(true)
string(4) "logs"
