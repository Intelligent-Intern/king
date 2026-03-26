--TEST--
King object-store init overrides local runtime settings and enforces runtime capacity on put
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$dir = sys_get_temp_dir() . '/king_runtime_113_' . getmypid();

var_dump(king_object_store_init([
    'primary_backend' => 'memory_cache',
    'storage_root_path' => $dir,
    'max_storage_size_bytes' => 4,
    'replication_factor' => 2,
    'chunk_size_kb' => 64,
    'cdn_config' => [
        'enabled' => true,
        'cache_size_mb' => 32,
        'default_ttl_seconds' => 7,
    ],
]));

$stats = king_object_store_get_stats();
var_dump($stats['object_store']['runtime_primary_backend']);
var_dump($stats['object_store']['runtime_storage_root_path']);
var_dump($stats['object_store']['runtime_max_storage_size_bytes']);
var_dump($stats['object_store']['runtime_replication_factor']);
var_dump($stats['object_store']['runtime_chunk_size_kb']);
var_dump($stats['cdn']['runtime_enabled']);
var_dump($stats['cdn']['runtime_cache_size_mb']);
var_dump($stats['cdn']['runtime_default_ttl_sec']);

var_dump(king_object_store_put('obj-1', 'ab'));
var_dump(king_object_store_put('obj-1', 'abcd'));

try {
    king_object_store_put('obj-2', 'e');
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

var_dump(king_object_store_delete('obj-1'));
foreach (scandir($dir) as $f) { if ($f !== '.' && $f !== '..') @unlink("$dir/$f"); }
@rmdir($dir);
?>
--EXPECTF--
bool(true)
string(8) "local_fs"
string(%d) "%s"
int(4)
int(2)
int(64)
bool(true)
int(32)
int(7)
bool(true)
bool(true)
string(24) "King\ValidationException"
string(39) "Object-store runtime capacity exceeded."
bool(true)
