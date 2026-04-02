--TEST--
King telemetry keeps exporter-backlog memory bounded and exposes self-metrics under degraded delivery
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

$failurePort = king_telemetry_test_pick_unused_port();
$payload = str_repeat('x', 70000);

king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $failurePort,
    'batch_processor_max_queue_size' => 3,
    'exporter_timeout_ms' => 50,
]);

$statuses = [];
foreach ([1, 2, 3] as $index) {
    king_telemetry_log('warn', 'bounded-' . $index, [
        'sequence' => $index,
        'payload' => $payload,
    ]);
    var_dump(king_telemetry_flush());
    $statuses[$index] = king_telemetry_get_status();
}

var_dump($statuses[1]['queue_size']);
var_dump($statuses[2]['queue_size']);
var_dump($statuses[2]['queue_bytes'] > $statuses[1]['queue_bytes']);
var_dump($statuses[3]['queue_size']);
var_dump($statuses[3]['queue_drop_count']);
var_dump($statuses[3]['pending_drop_count']);
var_dump($statuses[3]['queue_size'] < $statuses[3]['pending_entry_limit']);
var_dump($statuses[3]['queue_bytes'] > 0);
var_dump($statuses[3]['queue_bytes'] <= $statuses[3]['memory_byte_limit']);
var_dump($statuses[3]['memory_bytes'] === $statuses[3]['queue_bytes']);
var_dump($statuses[3]['memory_bytes'] <= $statuses[3]['memory_byte_limit']);
var_dump($statuses[3]['queue_high_watermark']);
var_dump($statuses[3]['queue_high_water_bytes'] > 0);
var_dump($statuses[3]['queue_high_water_bytes'] <= $statuses[3]['memory_byte_limit']);
var_dump($statuses[3]['memory_high_water_bytes'] > 0);
var_dump($statuses[3]['memory_high_water_bytes'] <= $statuses[3]['memory_byte_limit']);
var_dump($statuses[3]['retry_requeue_count']);
var_dump($statuses[3]['export_failure_count']);

$collector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
]);

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
        'batch_processor_max_queue_size' => 3,
        'exporter_timeout_ms' => 500,
    ]);

    var_dump(king_telemetry_flush());
    var_dump(king_telemetry_flush());
    $recovered = king_telemetry_get_status();
} finally {
    $capture = king_telemetry_test_stop_collector($collector);
}

var_dump($recovered['queue_size']);
var_dump($recovered['queue_bytes']);
var_dump($recovered['pending_bytes']);
var_dump($recovered['memory_bytes']);
var_dump($recovered['queue_drop_count']);
var_dump($recovered['retry_requeue_count']);
var_dump($recovered['export_failure_count']);
var_dump($recovered['export_success_count']);

$joinedBodies = '';
foreach ($capture as $request) {
    $joinedBodies .= $request['body'];
}

var_dump(count($capture));
var_dump($capture[0]['path']);
var_dump($capture[1]['path']);
var_dump(str_contains($joinedBodies, 'bounded-1'));
var_dump(str_contains($joinedBodies, 'bounded-2'));
var_dump(str_contains($joinedBodies, 'bounded-3'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
int(1)
int(2)
bool(true)
int(2)
int(0)
int(1)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(2)
bool(true)
bool(true)
bool(true)
bool(true)
int(3)
int(3)
bool(true)
bool(true)
int(0)
int(0)
int(0)
int(0)
int(0)
int(3)
int(3)
int(2)
int(2)
string(8) "/v1/logs"
string(8) "/v1/logs"
bool(true)
bool(true)
bool(false)
