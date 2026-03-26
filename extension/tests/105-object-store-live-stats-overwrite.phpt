--TEST--
King object-store get-stats keeps live accounting stable across overwrites
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$storagePath = sys_get_temp_dir() . '/king-object-store-stats-overwrite-' . bin2hex(random_bytes(6));
mkdir($storagePath);
king_object_store_init(['storage_root_path' => $storagePath]);

var_dump(king_object_store_put('obj-1', 'alpha'));

$stats = king_object_store_get_stats();
var_dump($stats['object_store']['object_count']);
var_dump($stats['object_store']['stored_bytes']);
var_dump(is_int($stats['object_store']['latest_object_at']));

var_dump(king_object_store_put('obj-1', 'beta'));

$stats = king_object_store_get_stats();
var_dump($stats['object_store']['object_count']);
var_dump($stats['object_store']['stored_bytes']);
var_dump(is_int($stats['object_store']['latest_object_at']));

var_dump(king_object_store_delete('obj-1'));

@unlink($storagePath . '/obj-1.meta');
@rmdir($storagePath);
?>
--EXPECT--
bool(true)
int(1)
int(5)
bool(true)
bool(true)
int(1)
int(4)
bool(true)
bool(true)
