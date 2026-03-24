--TEST--
King telemetry flush exposes a runtime success signal
--FILE--
<?php
king_telemetry_init([]);
king_telemetry_record_metric('flush-test', 1, null, 'counter');
var_dump(king_telemetry_flush());
$status = king_telemetry_get_status();
var_dump($status['active_metrics']);
var_dump($status['flush_count']);
?>
--EXPECT--
bool(true)
int(0)
int(1)
