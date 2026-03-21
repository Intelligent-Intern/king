--TEST--
King object-store put uses last-write-wins for the same object id
--FILE--
<?php
var_dump(king_object_store_put('obj-1', 'alpha'));
var_dump(king_object_store_put('obj-1', 'beta'));
var_dump(king_object_store_get('obj-1'));

$objects = king_object_store_list();
var_dump(count($objects));
var_dump($objects[0]['object_id']);
var_dump($objects[0]['size_bytes']);

var_dump(king_object_store_delete('obj-1'));
var_dump(king_object_store_list());
?>
--EXPECT--
bool(true)
bool(true)
string(4) "beta"
int(1)
string(5) "obj-1"
int(4)
bool(true)
array(0) {
}
