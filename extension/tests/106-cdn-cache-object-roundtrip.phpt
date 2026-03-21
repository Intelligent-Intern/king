--TEST--
King CDN cache-object writes into the local CDN cache registry and invalidate removes it again
--FILE--
<?php
var_dump(king_object_store_put('obj-1', 'alpha'));
var_dump(king_cdn_cache_object('obj-1'));

$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count']);
var_dump($stats['cdn']['cached_bytes']);
var_dump(is_int($stats['cdn']['latest_cached_at']));

var_dump(king_cdn_cache_object('obj-1', ['ttl_sec' => 30]));

$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count']);
var_dump($stats['cdn']['cached_bytes']);
var_dump(is_int($stats['cdn']['latest_cached_at']));

var_dump(king_cdn_invalidate_cache('obj-1'));

$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count']);
var_dump($stats['cdn']['cached_bytes']);
var_dump($stats['cdn']['latest_cached_at']);

var_dump(king_object_store_delete('obj-1'));
?>
--EXPECT--
bool(true)
bool(true)
int(1)
int(5)
bool(true)
bool(true)
int(1)
int(5)
bool(true)
int(1)
int(0)
int(0)
NULL
bool(true)
