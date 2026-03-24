--TEST--
King proto schema inventory returns a stable empty list in the current runtime
--FILE--
<?php
$schemas = king_proto_get_defined_schemas();
var_dump($schemas);
var_dump(array_is_list($schemas));
var_dump(count($schemas));
?>
--EXPECT--
array(0) {
}
bool(true)
int(0)
