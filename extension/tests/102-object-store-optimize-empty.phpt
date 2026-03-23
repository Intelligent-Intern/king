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
--EXPECTF--
Fatal error: Uncaught King\RuntimeException: Object-store registry is unavailable. in /home/jochen/projects/king.site/king/extension/tests/102-object-store-optimize-empty.php:2
Stack trace:
#0 /home/jochen/projects/king.site/king/extension/tests/102-object-store-optimize-empty.php(2): king_object_store_optimize()
#1 {main}
  thrown in /home/jochen/projects/king.site/king/extension/tests/102-object-store-optimize-empty.php on line 2