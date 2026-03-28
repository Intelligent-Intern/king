--TEST--
King telemetry keeps pending span and log capture bounded before flush and drops disabled capture
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
        'batch_processor_max_queue_size' => 2,
        'exporter_timeout_ms' => 500,
    ]);

    foreach (['first-log', 'second-log', 'third-log'] as $message) {
        king_telemetry_log('warn', $message, ['message_id' => $message]);
    }

    foreach (['first-span', 'second-span', 'third-span'] as $name) {
        $spanId = king_telemetry_start_span($name);
        var_dump(king_telemetry_end_span($spanId, ['span_name' => $name]));
    }

    $status = king_telemetry_get_status();
    var_dump($status['pending_entry_limit']);
    var_dump($status['pending_log_count']);
    var_dump($status['pending_span_count']);
    var_dump($status['pending_drop_count']);

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['pending_log_count']);
    var_dump($status['pending_span_count']);
    var_dump($status['pending_drop_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);

    king_telemetry_init([
        'enabled' => false,
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
        'batch_processor_max_queue_size' => 2,
        'exporter_timeout_ms' => 500,
    ]);

    king_telemetry_log('warn', 'disabled-log', ['message_id' => 'disabled-log']);
    $disabledSpanId = king_telemetry_start_span('disabled-span');
    var_dump(king_telemetry_end_span($disabledSpanId, ['span_name' => 'disabled-span']));

    $status = king_telemetry_get_status();
    var_dump($status['pending_log_count']);
    var_dump($status['pending_span_count']);
    var_dump($status['pending_drop_count']);

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['flush_count']);
} finally {
    $capture = king_telemetry_test_stop_collector($collector);
}

$tracesBody = $capture[0]['body'];
$logsBody = $capture[1]['body'];

var_dump(count($capture));
var_dump($capture[0]['path']);
var_dump($capture[1]['path']);
var_dump(str_contains($logsBody, 'first-log'));
var_dump(str_contains($logsBody, 'second-log'));
var_dump(str_contains($logsBody, 'third-log'));
var_dump(str_contains($tracesBody, 'first-span'));
var_dump(str_contains($tracesBody, 'second-span'));
var_dump(str_contains($tracesBody, 'third-span'));
var_dump(str_contains($logsBody, 'disabled-log'));
var_dump(str_contains($tracesBody, 'disabled-span'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
int(2)
int(2)
int(2)
int(2)
bool(true)
int(0)
int(0)
int(0)
int(2)
int(1)
int(1)
bool(true)
int(0)
int(0)
int(2)
bool(true)
int(0)
int(1)
int(2)
string(10) "/v1/traces"
string(8) "/v1/logs"
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
