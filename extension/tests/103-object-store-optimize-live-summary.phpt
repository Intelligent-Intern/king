--TEST--
King object-store optimize summarizes the live local registry
--FILE--
<?php
var_dump(king_object_store_put('obj-1', 'alpha'));
var_dump(king_object_store_put('obj-2', 'beta12'));

$report = king_object_store_optimize();
var_dump($report['mode']);
var_dump($report['scanned_objects']);
var_dump($report['total_size_bytes']);
var_dump($report['orphaned_entries_removed']);
var_dump($report['bytes_reclaimed']);
var_dump(is_int($report['optimized_at']));

var_dump(king_object_store_delete('obj-1'));
var_dump(king_object_store_delete('obj-2'));
?>
--EXPECT--
bool(true)
bool(true)
string(19) "local_registry_noop"
int(2)
int(11)
int(0)
int(0)
bool(true)
bool(true)
bool(true)
