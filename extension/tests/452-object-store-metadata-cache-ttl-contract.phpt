--TEST--
King object-store metadata cache evicts expired entries and rehydrates from durable metadata
--INI--
king.security_allow_config_override=1
king.storage_metadata_cache_max_entries=16
king.storage_metadata_cache_ttl_sec=1
--FILE--
<?php
$dir = sys_get_temp_dir() . '/king_object_store_meta_cache_452_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}

var_dump(king_object_store_init([
    'storage_root_path' => $dir,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 1024 * 1024,
]));

var_dump(king_object_store_put('ttl-doc', 'payload'));

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_metadata_cache_entries']);

sleep(2);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_metadata_cache_entries']);
var_dump($stats['runtime_metadata_cache_eviction_count'] >= 1);

$meta = king_object_store_get_metadata('ttl-doc');
var_dump(is_array($meta));
var_dump($meta['object_id']);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_metadata_cache_entries']);
var_dump($stats['runtime_metadata_cache_eviction_count'] >= 1);

foreach (scandir($dir) as $entry) {
    if ($entry !== '.' && $entry !== '..') {
        @unlink($dir . '/' . $entry);
    }
}
@rmdir($dir);
?>
--EXPECT--
bool(true)
bool(true)
int(1)
int(0)
bool(true)
bool(true)
string(7) "ttl-doc"
int(1)
bool(true)
