--TEST--
King system metrics exposes a stable skeleton snapshot shape
--FILE--
<?php
$metrics = king_system_get_metrics();
var_dump(array_keys($metrics));
var_dump(array_keys($metrics['resource_metrics']));
var_dump(is_int($metrics['resource_metrics']['memory_usage_bytes']));
var_dump(is_int($metrics['resource_metrics']['memory_peak_bytes']));
var_dump($metrics['resource_metrics']['memory_peak_bytes'] >= $metrics['resource_metrics']['memory_usage_bytes']);
var_dump(is_int($metrics['metrics_collected_at']));
?>
--EXPECT--
array(2) {
  [0]=>
  string(16) "resource_metrics"
  [1]=>
  string(20) "metrics_collected_at"
}
array(2) {
  [0]=>
  string(18) "memory_usage_bytes"
  [1]=>
  string(17) "memory_peak_bytes"
}
bool(true)
bool(true)
bool(true)
bool(true)
