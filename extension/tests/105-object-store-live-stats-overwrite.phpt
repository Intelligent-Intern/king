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
