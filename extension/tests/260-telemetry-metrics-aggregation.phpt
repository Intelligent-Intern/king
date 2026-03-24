--TEST--
King Telemetry: Metrics aggregation and flush path verification
--FILE--
<?php
king_telemetry_init([]);

// 1. Record counters (Aggregation)
king_telemetry_record_metric('requests', 1, null, 'counter');
king_telemetry_record_metric('requests', 1, null, 'counter');
king_telemetry_record_metric('requests', 3, null, 'counter');

$metrics = king_telemetry_get_metrics();
var_dump($metrics['requests']['value']);

// 2. Status check
$status = king_telemetry_get_status();
var_dump($status['active_metrics']);
var_dump($status['flush_count']);

// 3. Flush (Export)
var_dump(king_telemetry_flush());
$status = king_telemetry_get_status();
var_dump($status['active_metrics']);
var_dump($status['flush_count']);

?>
--EXPECT--
float(5)
int(1)
int(0)
bool(true)
int(0)
int(1)
