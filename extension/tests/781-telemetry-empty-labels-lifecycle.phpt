--TEST--
King telemetry safely snapshots immutable empty labels and releases copied labels
--FILE--
<?php
king_telemetry_init([]);
for ($iteration = 0; $iteration < 32; $iteration++) {
    king_telemetry_record_metric('empty-labels', 1, [], 'counter');
    king_telemetry_record_metric('default-labels', 2, null, 'gauge');
    $labels = ['host' => 'example'];
    king_telemetry_record_metric('copied-labels', 3, $labels, 'histogram');
    $labels['host'] = 'changed';
    if (!king_telemetry_flush()) {
        throw new RuntimeException('flush failed');
    }
    if (king_telemetry_get_status()['active_metrics'] !== 0) {
        throw new RuntimeException('metric registry was not drained');
    }
}
echo "32 empty, default and copied label snapshots completed\n";
?>
--EXPECT--
32 empty, default and copied label snapshots completed
