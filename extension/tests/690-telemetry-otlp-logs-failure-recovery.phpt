--TEST--
King telemetry retries log exports across timeout, response-size, and outage failures
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

$timeoutCollector = king_telemetry_test_start_collector([
    ['status' => 200, 'delay_ms' => 200, 'body' => 'ok'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $timeoutCollector['port'],
        'exporter_timeout_ms' => 50,
    ]);

    king_telemetry_log('error', 'timeout-log', ['phase' => 'timeout']);
    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);
} finally {
    $timeoutCapture = king_telemetry_test_stop_collector($timeoutCollector);
}

$timeoutRecovery = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $timeoutRecovery['port'],
        'exporter_timeout_ms' => 500,
    ]);

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);
} finally {
    $timeoutRecoveryCapture = king_telemetry_test_stop_collector($timeoutRecovery);
}

$sizeCollector = king_telemetry_test_start_collector([
    ['status' => 200, 'body_bytes' => (1024 * 1024) + 128],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $sizeCollector['port'],
        'exporter_timeout_ms' => 500,
    ]);

    king_telemetry_log('warn', 'oversized-log', ['phase' => 'size']);
    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);
} finally {
    $sizeCapture = king_telemetry_test_stop_collector($sizeCollector);
}

$sizeRecovery = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $sizeRecovery['port'],
        'exporter_timeout_ms' => 500,
    ]);

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);
} finally {
    $sizeRecoveryCapture = king_telemetry_test_stop_collector($sizeRecovery);
}

$outagePort = king_telemetry_test_pick_unused_port();
king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $outagePort,
    'exporter_timeout_ms' => 50,
]);

king_telemetry_log('debug', 'outage-log', ['phase' => 'outage']);
var_dump(king_telemetry_flush());
$status = king_telemetry_get_status();
var_dump($status['queue_size']);
var_dump($status['export_failure_count']);
var_dump($status['export_success_count']);
var_dump($status['flush_count']);

$outageRecovery = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $outageRecovery['port'],
        'exporter_timeout_ms' => 500,
    ]);

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);
} finally {
    $outageRecoveryCapture = king_telemetry_test_stop_collector($outageRecovery);
}

var_dump(count($timeoutCapture));
var_dump($timeoutCapture[0]['path']);
var_dump(str_contains($timeoutCapture[0]['body'], '"timeout-log"'));
var_dump(count($timeoutRecoveryCapture));
var_dump(str_contains($timeoutRecoveryCapture[0]['body'], '"timeout-log"'));
var_dump(count($sizeCapture));
var_dump(str_contains($sizeCapture[0]['body'], '"oversized-log"'));
var_dump(count($sizeRecoveryCapture));
var_dump(str_contains($sizeRecoveryCapture[0]['body'], '"oversized-log"'));
var_dump(count($outageRecoveryCapture));
var_dump(str_contains($outageRecoveryCapture[0]['body'], '"outage-log"'));
?>
--EXPECT--
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
bool(true)
int(1)
int(2)
int(1)
int(2)
bool(true)
int(0)
int(2)
int(2)
int(2)
bool(true)
int(1)
int(3)
int(2)
int(3)
bool(true)
int(0)
int(3)
int(3)
int(3)
int(1)
string(8) "/v1/logs"
bool(true)
int(1)
bool(true)
int(1)
bool(true)
int(1)
bool(true)
int(1)
bool(true)
