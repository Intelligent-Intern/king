--TEST--
King telemetry keeps queued export batches in oldest-first order across retry and recovery
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

$collector = king_telemetry_test_start_collector([
    ['status' => 503, 'body' => 'retry-later'],
    ['status' => 503, 'body' => 'retry-later'],
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
        'exporter_timeout_ms' => 500,
    ]);

    $component = king_system_get_component_info('telemetry');

    king_telemetry_log('warn', 'ordered-first', ['sequence' => 1]);
    var_dump(king_telemetry_flush());
    $afterFirst = king_telemetry_get_status();

    king_telemetry_log('warn', 'ordered-second', ['sequence' => 2]);
    var_dump(king_telemetry_flush());
    $afterSecond = king_telemetry_get_status();

    var_dump(king_telemetry_flush());
    $afterThird = king_telemetry_get_status();

    var_dump(king_telemetry_flush());
    $afterFourth = king_telemetry_get_status();
} finally {
    $capture = king_telemetry_test_stop_collector($collector);
}

var_dump($component['configuration']['ordering_guarantee']);
var_dump($afterFirst['queue_size']);
var_dump($afterFirst['export_failure_count']);
var_dump($afterSecond['queue_size']);
var_dump($afterSecond['export_failure_count']);
var_dump($afterThird['queue_size']);
var_dump($afterThird['export_success_count']);
var_dump($afterFourth['queue_size']);
var_dump($afterFourth['export_success_count']);

var_dump(count($capture));
var_dump($capture[0]['path']);
var_dump($capture[1]['path']);
var_dump($capture[2]['path']);
var_dump($capture[3]['path']);

var_dump(str_contains($capture[0]['body'], 'ordered-first'));
var_dump(!str_contains($capture[0]['body'], 'ordered-second'));
var_dump(str_contains($capture[1]['body'], 'ordered-first'));
var_dump(!str_contains($capture[1]['body'], 'ordered-second'));
var_dump(str_contains($capture[2]['body'], 'ordered-first'));
var_dump(!str_contains($capture[2]['body'], 'ordered-second'));
var_dump(str_contains($capture[3]['body'], 'ordered-second'));
var_dump(!str_contains($capture[3]['body'], 'ordered-first'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
string(18) "head_of_queue_fifo"
int(1)
int(1)
int(2)
int(2)
int(1)
int(1)
int(0)
int(2)
int(4)
string(8) "/v1/logs"
string(8) "/v1/logs"
string(8) "/v1/logs"
string(8) "/v1/logs"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
