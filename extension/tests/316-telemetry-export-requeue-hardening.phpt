--TEST--
King telemetry export retries keep queued batches acyclic across repeated failed flushes
--FILE--
<?php
king_telemetry_init([]);

king_telemetry_record_metric('first', 1, null, 'counter');
var_dump(king_telemetry_flush());
$status = king_telemetry_get_status();
var_dump($status['queue_size']);
var_dump($status['export_failure_count']);
var_dump($status['flush_count']);
var_dump($status['active_metrics']);

king_telemetry_record_metric('second', 1, null, 'counter');
var_dump(king_telemetry_flush());
$status = king_telemetry_get_status();
var_dump($status['queue_size']);
var_dump($status['export_failure_count']);
var_dump($status['flush_count']);
var_dump($status['active_metrics']);

/* Retry one pending batch without adding new metrics. */
var_dump(king_telemetry_flush());
$status = king_telemetry_get_status();
var_dump($status['queue_size']);
var_dump($status['export_failure_count']);
var_dump($status['flush_count']);
var_dump($status['active_metrics']);

echo "shutdown-safe\n";
?>
--EXPECT--
bool(true)
int(1)
int(1)
int(1)
int(0)
bool(true)
int(2)
int(2)
int(2)
int(0)
bool(true)
int(2)
int(3)
int(2)
int(0)
shutdown-safe
