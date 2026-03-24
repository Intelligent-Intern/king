--TEST--
King autoscaling status exposes runtime lifecycle defaults
--FILE--
<?php
var_dump(king_autoscaling_init([]));
$status = king_autoscaling_get_status();
var_dump(array_keys($status));
var_dump($status['initialized']);
var_dump($status['monitoring_active']);
var_dump($status['current_instances']);
?>
--EXPECT--
bool(true)
array(3) {
  [0]=>
  string(11) "initialized"
  [1]=>
  string(17) "monitoring_active"
  [2]=>
  string(17) "current_instances"
}
bool(true)
bool(false)
int(1)
