--TEST--
King CDN cache object, TTL contract, invalidation, and honest edge-node exposure
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$dir = sys_get_temp_dir() . '/king_cdn_rt_' . getmypid();

king_object_store_init([
    'storage_root_path' => $dir,
    'cdn_config' => [
        'enabled'             => true,
        'cache_size_mb'       => 64,
        'default_ttl_seconds' => 300,
    ],
]);

// Seed an object in the backing store
king_object_store_put('asset1', 'hello cdn world');

// Cache it in the CDN layer with a custom TTL
$r = king_cdn_cache_object('asset1', ['ttl_sec' => 60]);
var_dump($r);

// stats should see 1 cached object
$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count'] >= 1);

// Invalidate a single object
$removed = king_cdn_invalidate_cache('asset1');
var_dump($removed === 1);

// After invalidation stats drop
$stats2 = king_object_store_get_stats();
var_dump($stats2['cdn']['cached_object_count'] === 0);

// Edge nodes stay empty until real descriptors are configured.
$nodes = king_cdn_get_edge_nodes();
var_dump(array_is_list($nodes));
var_dump(count($nodes) === 0);

// Non-existent object cache attempt returns false
$r2 = king_cdn_cache_object('nonexistent_object_xyz');
var_dump($r2);

// Flush all
king_object_store_put('a', 'x');
king_object_store_put('b', 'y');
king_cdn_cache_object('a');
king_cdn_cache_object('b');
$flushed = king_cdn_invalidate_cache();
var_dump($flushed >= 2);

// Cleanup
foreach (scandir($dir) as $f) {
    if ($f !== '.' && $f !== '..') @unlink("$dir/$f");
}
@rmdir($dir);

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
