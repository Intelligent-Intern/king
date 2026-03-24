--TEST--
King telemetry inject-context leaves headers unchanged in the current runtime
--FILE--
<?php
var_dump(king_telemetry_inject_context());

$headers = ['x-test' => '1'];
var_dump(king_telemetry_inject_context($headers));
?>
--EXPECT--
array(0) {
}
array(1) {
  ["x-test"]=>
  string(1) "1"
}
