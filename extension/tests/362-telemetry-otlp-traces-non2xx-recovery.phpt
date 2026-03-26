--TEST--
King telemetry retries trace exports after collector non-2xx responses
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

$failedCollector = king_telemetry_test_start_collector([
    ['status' => 503, 'body' => 'nope'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $failedCollector['port'],
    ]);

    $spanId = king_telemetry_start_span('retry-span');
    var_dump(king_telemetry_end_span($spanId, ['phase' => 'initial']));
    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);
} finally {
    $failedCapture = king_telemetry_test_stop_collector($failedCollector);
}

$recoveredCollector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $recoveredCollector['port'],
    ]);

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);
} finally {
    $recoveredCapture = king_telemetry_test_stop_collector($recoveredCollector);
}

var_dump(count($failedCapture));
var_dump($failedCapture[0]['path']);
var_dump(str_contains($failedCapture[0]['body'], '"name":"retry-span"'));
var_dump(count($recoveredCapture));
var_dump($recoveredCapture[0]['path']);
var_dump(str_contains($recoveredCapture[0]['body'], '"name":"retry-span"'));
?>
--EXPECT--
bool(true)
bool(true)
int(1)
int(1)
int(0)
int(1)
bool(true)
int(0)
int(1)
int(1)
int(1)
int(1)
string(10) "/v1/traces"
bool(true)
int(1)
string(10) "/v1/traces"
bool(true)
