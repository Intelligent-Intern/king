--TEST--
King telemetry classifies OTLP HTTP 429 responses as explicit rate-limited retry diagnostics
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

$rateLimitedCollector = king_telemetry_test_start_collector([
    ['status' => 429, 'body' => 'retry-later'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $rateLimitedCollector['port'],
        'exporter_timeout_ms' => 500,
    ]);
    king_telemetry_record_metric('rate-limit-metric', 1.0, null, 'counter');

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    $diagnostic = $status['last_export_diagnostic'] ?? [];

    var_dump($status['queue_size']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump(($diagnostic['signal'] ?? '') === 'metrics');
    var_dump(($diagnostic['stage'] ?? '') === 'http');
    var_dump(($diagnostic['reason'] ?? '') === 'rate_limited');
    var_dump(($diagnostic['disposition'] ?? '') === 'retry');
    var_dump((int) ($diagnostic['http_status'] ?? 0) === 429);
    var_dump(
        is_string($diagnostic['message'] ?? null)
        && str_contains($diagnostic['message'], 'HTTP 429')
    );
} finally {
    $rateLimitedCapture = king_telemetry_test_stop_collector($rateLimitedCollector);
}

$recoveredCollector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $recoveredCollector['port'],
        'exporter_timeout_ms' => 500,
    ]);

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump((int) ($status['retry_requeue_count'] ?? 0) >= 1);
} finally {
    $recoveredCapture = king_telemetry_test_stop_collector($recoveredCollector);
}

var_dump(count($rateLimitedCapture));
var_dump($rateLimitedCapture[0]['path']);
var_dump(str_contains($rateLimitedCapture[0]['body'], '"name":"rate-limit-metric"'));
var_dump(count($recoveredCapture));
var_dump($recoveredCapture[0]['path']);
var_dump(str_contains($recoveredCapture[0]['body'], '"name":"rate-limit-metric"'));
?>
--EXPECT--
bool(true)
int(1)
int(1)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(0)
int(1)
int(1)
bool(true)
int(1)
string(11) "/v1/metrics"
bool(true)
int(1)
string(11) "/v1/metrics"
bool(true)
