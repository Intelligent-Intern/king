--TEST--
King CDN edge-state, TTL=0 no-expiry, auto-invalidation on delete, and cleanup sweep
--FILE--
<?php

$dir = sys_get_temp_dir() . '/king_cdn_es_' . getmypid();

king_object_store_init([
    'storage_root_path' => $dir,
    'cdn_config' => [
        'enabled'             => true,
        'cache_size_mb'       => 64,
        'default_ttl_seconds' => 300,
    ],
]);

// Seed objects
king_object_store_put('img1', 'imgdata');
king_object_store_put('img2', 'more-data');

// TTL=0 means no expiry
$r = king_cdn_cache_object('img1', ['ttl_sec' => 0]);
var_dump($r);

// Should count as 1 cached (no expiry, never evicted by clock)
$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count'] === 1);

// Cache img2 with real TTL
king_cdn_cache_object('img2', ['ttl_sec' => 300]);
$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count'] === 2);

// Deleting img2 from the object store must auto-invalidate its CDN entry
king_object_store_delete('img2');
$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count'] === 1);

// img1 (ttl=0 = immortal) must still be there
var_dump($stats['cdn']['cached_object_count'] >= 1);

// Cleanup sweep: no expired entries to purge (img1 is immortal, img2 already gone)
// Function must be callable without crash
king_object_store_cleanup_expired_objects(); // should not throw

// Final flush
king_cdn_invalidate_cache();
$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count'] === 0);

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
