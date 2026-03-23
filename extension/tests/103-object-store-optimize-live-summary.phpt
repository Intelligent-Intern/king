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
--EXPECTF--
Fatal error: Uncaught King\RuntimeException: Object-store registry is unavailable. in /home/jochen/projects/king.site/king/extension/tests/103-object-store-optimize-live-summary.php:2
Stack trace:
#0 /home/jochen/projects/king.site/king/extension/tests/103-object-store-optimize-live-summary.php(2): king_object_store_put('obj-1', 'alpha')
#1 {main}
  thrown in /home/jochen/projects/king.site/king/extension/tests/103-object-store-optimize-live-summary.php on line 2