--TEST--
King system status functions expose a stable skeleton shape
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
var_dump(array_keys($status['system_info']));
var_dump($status['system_info']['status']);
var_dump($status['system_info']['build']);
var_dump($status['system_info']['version']);
var_dump($status['system_info']['php_version'] === PHP_VERSION);
var_dump(array_keys($status['configuration']));
var_dump($status['configuration']['config_override_allowed']);
var_dump(array_keys($status['autoscaling']));
var_dump($status['autoscaling']['provider']);
var_dump($status['autoscaling']['region']);
var_dump($status['autoscaling']['min_nodes']);
var_dump($status['autoscaling']['max_nodes']);
var_dump($status['autoscaling']['scale_up_policy']);
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
array(3) {
  [0]=>
  string(11) "system_info"
  [1]=>
  string(13) "configuration"
  [2]=>
  string(11) "autoscaling"
}
array(4) {
  [0]=>
  string(6) "status"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(11) "php_version"
}
string(2) "ok"
string(8) "skeleton"
string(5) "0.1.0"
bool(true)
array(1) {
  [0]=>
  string(23) "config_override_allowed"
}
bool(false)
array(5) {
  [0]=>
  string(8) "provider"
  [1]=>
  string(6) "region"
  [2]=>
  string(9) "min_nodes"
  [3]=>
  string(9) "max_nodes"
  [4]=>
  string(15) "scale_up_policy"
}
string(0) ""
string(0) ""
int(1)
int(1)
string(11) "add_nodes:1"
