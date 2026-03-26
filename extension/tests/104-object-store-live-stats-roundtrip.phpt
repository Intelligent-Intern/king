--TEST--
King object-store get-stats tracks live local registry counts through put and delete
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$storagePath = sys_get_temp_dir() . '/king-object-store-stats-roundtrip-' . bin2hex(random_bytes(6));
mkdir($storagePath);
king_object_store_init(['storage_root_path' => $storagePath]);

$stats = king_object_store_get_stats();
var_dump($stats['object_store']['object_count']);
var_dump($stats['object_store']['stored_bytes']);
var_dump($stats['object_store']['latest_object_at']);

var_dump(king_object_store_put('obj-1', 'alpha'));
var_dump(king_object_store_put('obj-2', 'beta12'));

$stats = king_object_store_get_stats();
var_dump($stats['object_store']['object_count']);
var_dump($stats['object_store']['stored_bytes']);
var_dump(is_int($stats['object_store']['latest_object_at']));

var_dump(king_object_store_delete('obj-1'));
var_dump(king_object_store_delete('obj-2'));

$stats = king_object_store_get_stats();
var_dump($stats['object_store']['object_count']);
var_dump($stats['object_store']['stored_bytes']);
var_dump(is_int($stats['object_store']['latest_object_at']));

@unlink($storagePath . '/obj-1.meta');
@unlink($storagePath . '/obj-2.meta');
@rmdir($storagePath);
?>
--EXPECT--
int(0)
int(0)
NULL
bool(true)
bool(true)
int(2)
int(11)
bool(true)
bool(true)
bool(true)
int(0)
int(0)
bool(true)
