--TEST--
King object-store end-to-end: capacity, rehydration, CDN consistency, and metadata
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$dir = sys_get_temp_dir() . '/king_os_e2e_' . getmypid();
if (!is_dir($dir)) mkdir($dir, 0777, true);

function cleanup($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f !== '.' && $f !== '..') @unlink("$dir/$f");
    }
    @rmdir($dir);
}

// 1. Initialise with small capacity (100 bytes)
king_object_store_init([
    'storage_root_path' => $dir,
    'max_storage_size_bytes' => 100,
    'cdn_config' => [
        'enabled' => true,
        'default_ttl_seconds' => 3600
    ]
]);

// 2. Fill up near limit
king_object_store_put('obj1', str_repeat('A', 50)); // 50 bytes
king_object_store_put('obj2', str_repeat('B', 40)); // 40 bytes (total 90)

$stats = king_object_store_get_stats();
var_dump($stats['object_store']['stored_bytes']); // 90

// 3. Exceed limit
try {
    king_object_store_put('obj3', str_repeat('C', 20)); // would be 110
} catch (King\ValidationException $e) {
    echo "Limit caught: " . $e->getMessage() . "\n";
}

// 4. Verification: Stats should still be 90
$stats = king_object_store_get_stats();
var_dump($stats['object_store']['stored_bytes']);

// 5. Overwrite with smaller object (stable accounting)
king_object_store_put('obj1', str_repeat('X', 10)); // 50 -> 10. Total 90 - 50 + 10 = 50
$stats = king_object_store_get_stats();
var_dump($stats['object_store']['stored_bytes']); // 50

// 6. CDN consistency: Cache an object, then delete it from store -> must disappear from CDN
king_cdn_cache_object('obj1');
$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count']); // 1

king_object_store_delete('obj1');
$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count']); // 0 (auto-invalidated)

// 7. Rehydration: Restart runtime
king_object_store_init([
    'storage_root_path' => $dir,
    'max_storage_size_bytes' => 100
]);

$stats = king_object_store_get_stats();
var_dump($stats['object_store']['object_count']); // Should be 1 (only obj2 remains)
var_dump($stats['object_store']['stored_bytes']); // Should be 40 (obj2 size)

cleanup($dir);
?>
--EXPECT--
int(90)
Limit caught: king_object_store_put() would exceed the configured object-store runtime capacity.
int(90)
int(50)
int(1)
int(0)
int(1)
int(40)
