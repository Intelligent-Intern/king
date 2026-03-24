--TEST--
King object-store list returns a stable empty list in the current runtime
--FILE--
<?php
$objects = king_object_store_list();
var_dump($objects);
var_dump(array_is_list($objects));
var_dump(count($objects));
?>
--EXPECT--
array(0) {
}
bool(true)
int(0)
