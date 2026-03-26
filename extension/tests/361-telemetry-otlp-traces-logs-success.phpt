--TEST--
King telemetry exports traces and logs to real OTLP collectors
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

$collector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
    ]);

    $spanId = king_telemetry_start_span('checkout');
    king_telemetry_log('warn', 'inventory low', ['sku' => 'A-123', 'remaining' => 2]);
    var_dump(king_telemetry_end_span($spanId, ['cart_id' => 123, 'status' => 'ok']));

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_success_count']);
    var_dump($status['export_failure_count']);
    var_dump($status['flush_count']);
} finally {
    $capture = king_telemetry_test_stop_collector($collector);
}

var_dump(count($capture));
var_dump($capture[0]['path']);
var_dump($capture[1]['path']);
var_dump(str_contains($capture[0]['body'], '"name":"checkout"'));
var_dump(str_contains($capture[0]['body'], '"cart_id"'));
var_dump(str_contains($capture[1]['body'], '"severityText":"warn"'));
var_dump(str_contains($capture[1]['body'], '"inventory low"'));
var_dump(str_contains($capture[1]['body'], '"remaining"'));
?>
--EXPECT--
bool(true)
bool(true)
int(0)
int(1)
int(0)
int(1)
int(2)
string(10) "/v1/traces"
string(8) "/v1/logs"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
