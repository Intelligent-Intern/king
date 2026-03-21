--TEST--
King object-store put/get/list/delete roundtrip through the local skeleton registry
--FILE--
<?php
var_dump(king_object_store_put('obj-1', 'alpha'));
var_dump(king_object_store_get('obj-1'));

$objects = king_object_store_list();
var_dump(count($objects));
var_dump($objects[0]['object_id']);
var_dump($objects[0]['size_bytes']);
var_dump(is_int($objects[0]['stored_at']));

var_dump(king_object_store_delete('obj-1'));
var_dump(king_object_store_get('obj-1'));
var_dump(king_object_store_list());
?>
--EXPECT--
bool(true)
string(5) "alpha"
int(1)
string(5) "obj-1"
int(5)
bool(true)
bool(true)
bool(false)
array(0) {
}
