--TEST--
King object-store optimize exposes a stable empty maintenance summary
--FILE--
<?php
$report = king_object_store_optimize();
var_dump($report['mode']);
var_dump($report['scanned_objects']);
var_dump($report['total_size_bytes']);
var_dump($report['orphaned_entries_removed']);
var_dump($report['bytes_reclaimed']);
var_dump(is_int($report['optimized_at']));
?>
--EXPECT--
string(19) "local_registry_noop"
int(0)
int(0)
int(0)
int(0)
bool(true)
