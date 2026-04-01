--TEST--
King object-store metadata cache stays bounded under many unique object ids
--INI--
king.security_allow_config_override=1
king.storage_metadata_cache_max_entries=4
--FILE--
<?php
$dir = sys_get_temp_dir() . '/king_object_store_meta_cache_451_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}

$allOkay = king_object_store_init([
    'storage_root_path' => $dir,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 1024 * 1024,
]);

for ($i = 0; $i < 10; $i++) {
    $allOkay = $allOkay && king_object_store_put('cache-bound-' . $i, 'payload-' . $i);
}

var_dump($allOkay);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['metadata_cache_max_entries']);
var_dump($stats['runtime_metadata_cache_entries'] <= 4);
var_dump($stats['runtime_metadata_cache_eviction_count'] >= 6);

$meta = king_object_store_get_metadata('cache-bound-0');
var_dump(is_array($meta));
var_dump($meta['object_id']);
var_dump($meta['content_length']);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_metadata_cache_entries'] <= 4);
var_dump($stats['runtime_metadata_cache_eviction_count'] >= 6);

foreach (scandir($dir) as $entry) {
    if ($entry !== '.' && $entry !== '..') {
        @unlink($dir . '/' . $entry);
    }
}
@rmdir($dir);
?>
--EXPECT--
bool(true)
int(4)
bool(true)
bool(true)
bool(true)
string(13) "cache-bound-0"
int(9)
bool(true)
bool(true)
