--TEST--
King object-store get-stats tracks live local registry counts through put and delete
--FILE--
<?php
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
var_dump($stats['object_store']['latest_object_at']);
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
NULL
