--TEST--
King telemetry enforces OTLP response-size limits across metrics traces and logs
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

function king_telemetry_run_response_size_case(
    string $expectedPath,
    string $needle,
    callable $emitSignal
): array {
    $oversizedCollector = king_telemetry_test_start_collector([
        ['status' => 200, 'body_bytes' => (1024 * 1024) + 128],
    ]);

    try {
        king_telemetry_init([
            'otel_exporter_endpoint' => 'http://127.0.0.1:' . $oversizedCollector['port'],
            'exporter_timeout_ms' => 500,
        ]);

        $beforeFailure = king_telemetry_get_status();
        $emitSignal();
        $failureFlushResult = king_telemetry_flush();
        $afterFailure = king_telemetry_get_status();
    } finally {
        $oversizedCapture = king_telemetry_test_stop_collector($oversizedCollector);
    }

    $recoveryCollector = king_telemetry_test_start_collector([
        ['status' => 200, 'body' => 'ok'],
    ]);

    try {
        king_telemetry_init([
            'otel_exporter_endpoint' => 'http://127.0.0.1:' . $recoveryCollector['port'],
            'exporter_timeout_ms' => 500,
        ]);

        $beforeRecovery = king_telemetry_get_status();
        $recoveryFlushResult = king_telemetry_flush();
        $afterRecovery = king_telemetry_get_status();
    } finally {
        $recoveryCapture = king_telemetry_test_stop_collector($recoveryCollector);
    }

    return [
        $failureFlushResult === true,
        (int) ($afterFailure['queue_size'] ?? -1) === 1,
        (int) ($afterFailure['export_failure_count'] ?? -1)
            === (int) ($beforeFailure['export_failure_count'] ?? -2) + 1,
        (int) ($afterFailure['export_success_count'] ?? -1)
            === (int) ($beforeFailure['export_success_count'] ?? -2),
        (int) ($afterFailure['flush_count'] ?? -1)
            === (int) ($beforeFailure['flush_count'] ?? -2) + 1,
        count($oversizedCapture) === 1,
        ($oversizedCapture[0]['path'] ?? '') === $expectedPath,
        str_contains((string) ($oversizedCapture[0]['body'] ?? ''), $needle),
        $recoveryFlushResult === true,
        (int) ($beforeRecovery['queue_size'] ?? -1) === 1,
        (int) ($afterRecovery['queue_size'] ?? -1) === 0,
        (int) ($afterRecovery['export_failure_count'] ?? -1)
            === (int) ($beforeRecovery['export_failure_count'] ?? -2),
        (int) ($afterRecovery['export_success_count'] ?? -1)
            === (int) ($beforeRecovery['export_success_count'] ?? -2) + 1,
        count($recoveryCapture) === 1,
        ($recoveryCapture[0]['path'] ?? '') === $expectedPath,
        str_contains((string) ($recoveryCapture[0]['body'] ?? ''), $needle),
    ];
}

$metricResults = king_telemetry_run_response_size_case(
    '/v1/metrics',
    '"name":"response-size-metric"',
    static function (): void {
        king_telemetry_record_metric('response-size-metric', 7.0, null, 'counter');
    }
);

$traceResults = king_telemetry_run_response_size_case(
    '/v1/traces',
    '"name":"response-size-span"',
    static function (): void {
        $spanId = king_telemetry_start_span('response-size-span');
        if (!is_string($spanId) || $spanId === '') {
            throw new RuntimeException('failed to create trace export test span');
        }
        if (!king_telemetry_end_span($spanId, ['phase' => 'response-limit'])) {
            throw new RuntimeException('failed to end trace export test span');
        }
    }
);

$logResults = king_telemetry_run_response_size_case(
    '/v1/logs',
    '"response-size-log"',
    static function (): void {
        king_telemetry_log('warn', 'response-size-log', ['phase' => 'response-limit']);
    }
);

foreach ([$metricResults, $traceResults, $logResults] as $resultSet) {
    foreach ($resultSet as $result) {
        var_dump($result);
    }
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
