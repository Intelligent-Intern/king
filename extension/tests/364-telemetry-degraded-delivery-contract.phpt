--TEST--
King telemetry keeps an explicit bounded in-process delivery contract under sustained exporter failure
--INI--
king.security_allow_config_override=1
king.otel_batch_processor_max_queue_size=2
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

$failurePort = king_telemetry_test_pick_unused_port();
king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $failurePort,
    'exporter_timeout_ms' => 50,
]);

foreach (['first-log', 'second-log', 'third-log', 'fourth-log'] as $message) {
    king_telemetry_log('warn', $message, ['message_id' => $message]);
    var_dump(king_telemetry_flush());
}

$status = king_telemetry_get_status();
var_dump($status['queue_size']);
var_dump($status['queue_drop_count']);
var_dump($status['export_failure_count']);
var_dump($status['export_success_count']);
var_dump($status['flush_count']);

$component = king_system_get_component_info('telemetry');
var_dump($component['configuration']['delivery_contract']);
var_dump($component['configuration']['queue_persistence']);
var_dump($component['configuration']['restart_replay']);
var_dump($component['configuration']['drain_behavior']);

$collector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
        'exporter_timeout_ms' => 500,
    ]);

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['queue_drop_count']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['queue_drop_count']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);
} finally {
    $capture = king_telemetry_test_stop_collector($collector);
}

$joinedBodies = '';
foreach ($capture as $request) {
    $joinedBodies .= $request['body'];
}

var_dump(count($capture));
var_dump($capture[0]['path']);
var_dump($capture[1]['path']);
var_dump(str_contains($joinedBodies, 'first-log'));
var_dump(str_contains($joinedBodies, 'second-log'));
var_dump(str_contains($joinedBodies, 'third-log'));
var_dump(str_contains($joinedBodies, 'fourth-log'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
int(2)
int(2)
int(4)
int(0)
int(4)
string(25) "best_effort_bounded_retry"
string(28) "process_local_non_persistent"
string(13) "not_supported"
string(22) "single_batch_per_flush"
bool(true)
int(1)
int(2)
int(4)
int(1)
int(4)
bool(true)
int(0)
int(2)
int(4)
int(2)
int(4)
int(2)
string(8) "/v1/logs"
string(8) "/v1/logs"
bool(true)
bool(true)
bool(false)
bool(false)
