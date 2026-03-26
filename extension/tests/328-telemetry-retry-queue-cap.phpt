--TEST--
King telemetry retry queue stays bounded under repeated exporter failures
--INI--
king.otel_batch_processor_max_queue_size=2
--FILE--
<?php
king_telemetry_init([]);

foreach (['first', 'second', 'third'] as $metric) {
    king_telemetry_record_metric($metric, 1, null, 'counter');
    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['queue_drop_count']);
    var_dump($status['export_failure_count']);
    var_dump($status['flush_count']);
    var_dump($status['active_metrics']);
}
?>
--EXPECT--
bool(true)
int(1)
int(0)
int(1)
int(1)
int(0)
bool(true)
int(2)
int(0)
int(2)
int(2)
int(0)
bool(true)
int(2)
int(1)
int(3)
int(3)
int(0)
