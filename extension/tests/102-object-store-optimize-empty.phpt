--TEST--
King object-store optimize exposes a stable empty maintenance summary
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$storagePath = sys_get_temp_dir() . '/king-object-store-optimize-empty-' . bin2hex(random_bytes(6));
mkdir($storagePath);
king_object_store_init(['storage_root_path' => $storagePath]);

$report = king_object_store_optimize();
var_dump($report['mode']);
var_dump($report['scanned_objects']);
var_dump($report['total_size_bytes']);
var_dump($report['orphaned_entries_removed']);
var_dump($report['bytes_reclaimed']);
var_dump(is_int($report['optimized_at']));

@rmdir($storagePath);
?>
--EXPECT--
string(14) "native_fs_noop"
int(0)
int(0)
int(0)
int(0)
bool(true)
