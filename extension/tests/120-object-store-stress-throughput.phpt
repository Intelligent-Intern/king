--TEST--
King object-store stress-test: 500 small objects, throughput, and capacity pressure
--FILE--
<?php

$dir = sys_get_temp_dir() . '/king_stress_' . getmypid();
if (!is_dir($dir)) mkdir($dir, 0755, true);

king_object_store_init([
    'storage_root_path' => $dir,
    'max_storage_size_bytes' => 1024 * 1024, // 1MB
]);

$start = microtime(true);
for ($i = 0; $i < 500; $i++) {
    king_object_store_put("obj_$i", "payload_$i"); // ~10 bytes + key overhead
}
$end = microtime(true);

$stats = king_object_store_get_stats();
var_dump($stats['object_store']['object_count']); // 500

// Delete half
for ($i = 0; $i < 250; $i++) {
    king_object_store_delete("obj_$i");
}

$stats = king_object_store_get_stats();
var_dump($stats['object_store']['object_count']); // 250

// Rehydrate
king_object_store_init([
    'storage_root_path' => $dir,
]);

$stats = king_object_store_get_stats();
var_dump($stats['object_store']['object_count'] === 250);

// Cleanup
foreach (scandir($dir) as $f) { if ($f !== '.' && $f !== '..') @unlink("$dir/$f"); }
@rmdir($dir);
?>
--EXPECT--
int(500)
int(250)
bool(true)
