--TEST--
King health output exposes the expected shape and stable fields
--FILE--
<?php
$health = king_health();

var_dump(array_keys($health));
var_dump($health['status']);
var_dump($health['build']);
var_dump($health['version']);
var_dump($health['php_version'] === PHP_VERSION);
var_dump(is_int($health['pid']));
var_dump($health['pid'] > 0);
var_dump($health['config_override_allowed']);
var_dump($health['active_runtime_count']);
var_dump(is_array($health['active_runtimes']));
var_dump($health['stubbed_api_group_count']);
var_dump(is_array($health['stubbed_api_groups']));
?>
--EXPECT--
array(10) {
  [0]=>
  string(6) "status"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(11) "php_version"
  [4]=>
  string(3) "pid"
  [5]=>
  string(23) "config_override_allowed"
  [6]=>
  string(20) "active_runtime_count"
  [7]=>
  string(15) "active_runtimes"
  [8]=>
  string(23) "stubbed_api_group_count"
  [9]=>
  string(18) "stubbed_api_groups"
}
string(2) "ok"
string(2) "v1"
string(11) "0.2.1-alpha"
bool(true)
bool(true)
bool(true)
bool(false)
int(30)
bool(true)
int(0)
bool(true)
