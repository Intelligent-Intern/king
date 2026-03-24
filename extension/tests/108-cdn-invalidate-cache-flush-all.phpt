--TEST--
King CDN invalidate-cache flushes the full local cache registry when called without an object id
--FILE--
<?php
$dir = sys_get_temp_dir() . '/king_cdn_108_' . getmypid();
king_object_store_init(['storage_root_path' => $dir, 'cdn_config' => ['enabled' => true, 'default_ttl_seconds' => 300]]);

var_dump(king_object_store_put('obj-1', 'alpha'));
var_dump(king_object_store_put('obj-2', 'beta12'));
var_dump(king_cdn_cache_object('obj-1'));
var_dump(king_cdn_cache_object('obj-2'));

$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count']);
var_dump($stats['cdn']['cached_bytes']);
var_dump(is_int($stats['cdn']['latest_cached_at']));

var_dump(king_cdn_invalidate_cache());

$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count']);
var_dump($stats['cdn']['cached_bytes']);
var_dump($stats['cdn']['latest_cached_at']);

var_dump(king_cdn_invalidate_cache());
var_dump(king_object_store_delete('obj-1'));
var_dump(king_object_store_delete('obj-2'));

foreach (scandir($dir) as $f) { if ($f !== '.' && $f !== '..') @unlink("$dir/$f"); }
@rmdir($dir);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
int(2)
int(11)
bool(true)
int(2)
int(0)
int(0)
NULL
int(0)
bool(true)
bool(true)
