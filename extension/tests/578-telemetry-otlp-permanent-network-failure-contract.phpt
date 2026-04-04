--TEST--
King telemetry drops permanently unreachable OTLP endpoints without poisoning later exports
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

function king_telemetry_run_permanent_network_case(
    string $expectedPath,
    string $failedNeedle,
    string $healthyNeedle,
    callable $emitFailedSignal,
    callable $emitHealthySignal
): array {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://telemetry.invalid:4318',
        'exporter_timeout_ms' => 250,
    ]);

    $beforeFailure = king_telemetry_get_status();
    $emitFailedSignal();
    $failureFlushResult = king_telemetry_flush();
    $afterFailure = king_telemetry_get_status();

    $collector = king_telemetry_test_start_collector([
        ['status' => 200, 'body' => 'ok'],
    ]);

    try {
        king_telemetry_init([
            'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
            'exporter_timeout_ms' => 500,
        ]);

        $beforeRecovery = king_telemetry_get_status();
        $emitHealthySignal();
        $recoveryFlushResult = king_telemetry_flush();
        $afterRecovery = king_telemetry_get_status();
    } finally {
        $capture = king_telemetry_test_stop_collector($collector);
    }

    return [
        $failureFlushResult === true,
        (int) ($afterFailure['queue_size'] ?? -1) === 0,
        (int) ($afterFailure['export_failure_count'] ?? -1)
            === (int) ($beforeFailure['export_failure_count'] ?? -2) + 1,
        (int) ($afterFailure['export_success_count'] ?? -1)
            === (int) ($beforeFailure['export_success_count'] ?? -2),
        (int) ($afterFailure['flush_count'] ?? -1)
            === (int) ($beforeFailure['flush_count'] ?? -2) + 1,
        (int) ($beforeRecovery['queue_size'] ?? -1) === 0,
        $recoveryFlushResult === true,
        (int) ($afterRecovery['queue_size'] ?? -1) === 0,
        (int) ($afterRecovery['export_failure_count'] ?? -1)
            === (int) ($beforeRecovery['export_failure_count'] ?? -2),
        (int) ($afterRecovery['export_success_count'] ?? -1)
            === (int) ($beforeRecovery['export_success_count'] ?? -2) + 1,
        (int) ($afterRecovery['flush_count'] ?? -1)
            === (int) ($beforeRecovery['flush_count'] ?? -2) + 1,
        count($capture) === 1,
        ($capture[0]['path'] ?? '') === $expectedPath,
        str_contains((string) ($capture[0]['body'] ?? ''), $healthyNeedle),
        !str_contains((string) ($capture[0]['body'] ?? ''), $failedNeedle),
    ];
}

$metricResults = king_telemetry_run_permanent_network_case(
    '/v1/metrics',
    '"name":"permanent-network-metric"',
    '"name":"healthy-network-metric"',
    static function (): void {
        king_telemetry_record_metric('permanent-network-metric', 1.0, null, 'counter');
    },
    static function (): void {
        king_telemetry_record_metric('healthy-network-metric', 2.0, null, 'counter');
    }
);

$traceResults = king_telemetry_run_permanent_network_case(
    '/v1/traces',
    '"name":"permanent-network-span"',
    '"name":"healthy-network-span"',
    static function (): void {
        $spanId = king_telemetry_start_span('permanent-network-span');
        if (!is_string($spanId) || $spanId === '') {
            throw new RuntimeException('failed to create permanent failure trace span');
        }
        if (!king_telemetry_end_span($spanId, ['phase' => 'permanent-network'])) {
            throw new RuntimeException('failed to end permanent failure trace span');
        }
    },
    static function (): void {
        $spanId = king_telemetry_start_span('healthy-network-span');
        if (!is_string($spanId) || $spanId === '') {
            throw new RuntimeException('failed to create healthy recovery trace span');
        }
        if (!king_telemetry_end_span($spanId, ['phase' => 'healthy-network'])) {
            throw new RuntimeException('failed to end healthy recovery trace span');
        }
    }
);

$logResults = king_telemetry_run_permanent_network_case(
    '/v1/logs',
    '"permanent-network-log"',
    '"healthy-network-log"',
    static function (): void {
        king_telemetry_log('warn', 'permanent-network-log', ['phase' => 'permanent-network']);
    },
    static function (): void {
        king_telemetry_log('warn', 'healthy-network-log', ['phase' => 'healthy-network']);
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

