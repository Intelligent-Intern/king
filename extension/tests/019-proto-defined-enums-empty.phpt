--TEST--
King proto enum inventory returns a stable empty list in the skeleton build
--FILE--
<?php
$enums = king_proto_get_defined_enums();
var_dump($enums);
var_dump(array_is_list($enums));
var_dump(count($enums));
?>
--EXPECT--
array(0) {
}
bool(true)
int(0)
