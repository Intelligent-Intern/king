--TEST--
King object-store put uses last-write-wins for the same object id
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$storagePath = sys_get_temp_dir() . '/king-object-store-last-write-' . bin2hex(random_bytes(6));
mkdir($storagePath);
king_object_store_init(['storage_root_path' => $storagePath]);

var_dump(king_object_store_put('obj-1', 'alpha'));
var_dump(king_object_store_put('obj-1', 'beta'));
var_dump(king_object_store_get('obj-1'));

$objects = king_object_store_list();
var_dump(count($objects));
var_dump($objects[0]['object_id']);
var_dump($objects[0]['size_bytes']);

var_dump(king_object_store_delete('obj-1'));
var_dump(king_object_store_list());

@unlink($storagePath . '/obj-1.meta');
@rmdir($storagePath);
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
