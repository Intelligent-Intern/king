--TEST--
King system status functions expose health and runtime lifecycle state
--FILE--
<?php
$health = king_system_health_check();
var_dump(array_keys($health));
var_dump($health['overall_healthy']);
var_dump($health['build']);
var_dump($health['version']);
var_dump($health['config_override_allowed']);

$status = king_system_get_status();
var_dump(array_keys($status));
var_dump($status['initialized']);
var_dump($status['component_count']);

var_dump(king_system_init([]));
$status = king_system_get_status();
var_dump(array_keys($status));
var_dump($status['initialized']);
var_dump($status['component_count'] > 0);

var_dump(king_system_shutdown());
$status = king_system_get_status();
var_dump($status['initialized']);
var_dump($status['component_count']);
?>
--EXPECT--
array(4) {
  [0]=>
  string(15) "overall_healthy"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(23) "config_override_allowed"
}
bool(true)
string(8) "skeleton"
string(5) "0.1.0"
bool(false)
array(2) {
  [0]=>
  string(11) "initialized"
  [1]=>
  string(15) "component_count"
}
bool(false)
int(0)
bool(true)
array(2) {
  [0]=>
  string(11) "initialized"
  [1]=>
  string(15) "component_count"
}
bool(true)
bool(true)
bool(true)
bool(false)
int(0)
