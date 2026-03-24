--TEST--
King autoscaling metrics exposes a live monitoring snapshot
--FILE--
<?php
$metrics = king_autoscaling_get_metrics();
var_dump(array_keys($metrics));
var_dump(is_float($metrics['cpu_utilization']));
var_dump(is_float($metrics['memory_utilization']));
var_dump(is_int($metrics['active_connections']));
var_dump(is_int($metrics['requests_per_second']));
var_dump(is_int($metrics['response_time_ms']));
var_dump(is_int($metrics['queue_depth']));
var_dump(is_int($metrics['timestamp']));
?>
--EXPECT--
array(7) {
  [0]=>
  string(15) "cpu_utilization"
  [1]=>
  string(18) "memory_utilization"
  [2]=>
  string(18) "active_connections"
  [3]=>
  string(19) "requests_per_second"
  [4]=>
  string(16) "response_time_ms"
  [5]=>
  string(11) "queue_depth"
  [6]=>
  string(9) "timestamp"
}
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
