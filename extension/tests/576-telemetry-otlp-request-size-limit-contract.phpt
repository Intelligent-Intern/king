--TEST--
King telemetry rejects oversized OTLP request bodies before dispatch and does not poison later exports
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

$oversizedCollector = king_telemetry_test_start_collector([]);
$oversizedCapture = [];

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $oversizedCollector['port'],
        'exporter_timeout_ms' => 500,
    ]);

    king_telemetry_log('error', 'oversized-export', [
        'payload' => str_repeat('L', 1024 * 1024),
    ]);

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);
} finally {
    $oversizedCapture = king_telemetry_test_stop_collector($oversizedCollector);
}

$recoveryCollector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
]);
$recoveryCapture = [];

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $recoveryCollector['port'],
        'exporter_timeout_ms' => 500,
    ]);

    king_telemetry_log('error', 'small-export', ['phase' => 'recovery']);
    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);
} finally {
    $recoveryCapture = king_telemetry_test_stop_collector($recoveryCollector);
}

var_dump(count($oversizedCapture));
var_dump(count($recoveryCapture));
var_dump($recoveryCapture[0]['path']);
var_dump(str_contains($recoveryCapture[0]['body'], '"small-export"'));
var_dump(str_contains($recoveryCapture[0]['body'], '"oversized-export"'));
?>
--EXPECT--
bool(true)
int(0)
int(1)
int(0)
int(1)
bool(true)
int(0)
int(1)
int(1)
int(2)
int(0)
int(1)
string(8) "/v1/logs"
bool(true)
bool(false)
