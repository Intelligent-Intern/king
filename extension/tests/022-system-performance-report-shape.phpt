--TEST--
King system performance report exposes a stable skeleton snapshot shape
--FILE--
<?php
$report = king_system_get_performance_report();
var_dump(array_keys($report));
var_dump(array_keys($report['performance_overview']));
var_dump(is_int($report['performance_overview']['memory_usage_mb']));
var_dump(is_int($report['performance_overview']['memory_peak_mb']));
var_dump(count($report['component_performance']));
var_dump(array_is_list($report['recommendations']));
var_dump(is_int($report['report_generated_at']));
?>
--EXPECT--
array(4) {
  [0]=>
  string(20) "performance_overview"
  [1]=>
  string(21) "component_performance"
  [2]=>
  string(15) "recommendations"
  [3]=>
  string(19) "report_generated_at"
}
array(3) {
  [0]=>
  string(5) "build"
  [1]=>
  string(15) "memory_usage_mb"
  [2]=>
  string(14) "memory_peak_mb"
}
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
