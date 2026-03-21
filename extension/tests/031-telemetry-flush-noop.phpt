--TEST--
King telemetry flush exposes a stable no-op report in the skeleton build
--FILE--
<?php
$flush = king_telemetry_flush();
var_dump(array_keys($flush));
var_dump($flush['spans_exported']);
var_dump($flush['metrics_exported']);
var_dump($flush['logs_exported']);
var_dump(is_int($flush['export_timestamp']));
?>
--EXPECT--
array(4) {
  [0]=>
  string(14) "spans_exported"
  [1]=>
  string(16) "metrics_exported"
  [2]=>
  string(13) "logs_exported"
  [3]=>
  string(16) "export_timestamp"
}
int(0)
int(0)
int(0)
bool(true)
