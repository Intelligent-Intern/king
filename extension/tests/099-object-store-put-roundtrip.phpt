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
--EXPECTF--
Fatal error: Uncaught King\RuntimeException: Object-store registry is unavailable. in /home/jochen/projects/king.site/king/extension/tests/099-object-store-put-roundtrip.php:2
Stack trace:
#0 /home/jochen/projects/king.site/king/extension/tests/099-object-store-put-roundtrip.php(2): king_object_store_put('obj-1', 'alpha')
#1 {main}
  thrown in /home/jochen/projects/king.site/king/extension/tests/099-object-store-put-roundtrip.php on line 2