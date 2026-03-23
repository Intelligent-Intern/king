--TEST--
King object-store get-stats keeps live accounting stable across overwrites
--FILE--
<?php
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
?>
--EXPECTF--
Fatal error: Uncaught King\RuntimeException: Object-store registry is unavailable. in /home/jochen/projects/king.site/king/extension/tests/105-object-store-live-stats-overwrite.php:2
Stack trace:
#0 /home/jochen/projects/king.site/king/extension/tests/105-object-store-live-stats-overwrite.php(2): king_object_store_put('obj-1', 'alpha')
#1 {main}
  thrown in /home/jochen/projects/king.site/king/extension/tests/105-object-store-live-stats-overwrite.php on line 2