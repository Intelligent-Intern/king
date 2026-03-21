--TEST--
King telemetry metrics returns a stable empty list in the skeleton build
--FILE--
<?php
$metrics = king_telemetry_get_metrics();
var_dump($metrics);
var_dump(array_is_list($metrics));
var_dump(count($metrics));
?>
--EXPECT--
array(0) {
}
bool(true)
int(0)
