--TEST--
King object-store init overrides local runtime settings and enforces runtime capacity on put
--FILE--
<?php
var_dump(king_object_store_init([
    'primary_backend' => 'memory_cache',
    'storage_root_path' => '/tmp/king-runtime',
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
?>
--EXPECTF--
bool(true)
string(8) "local_fs"
string(17) "/tmp/king-runtime"
int(4)
int(2)
int(64)
bool(true)
int(32)
int(7)
bool(true)

Fatal error: Uncaught King\ValidationException: Object-store runtime capacity exceeded. in /home/jochen/projects/king.site/king/extension/tests/113-object-store-init-overrides-and-capacity.php:26
Stack trace:
#0 /home/jochen/projects/king.site/king/extension/tests/113-object-store-init-overrides-and-capacity.php(26): king_object_store_put('obj-1', 'abcd')
#1 {main}
  thrown in /home/jochen/projects/king.site/king/extension/tests/113-object-store-init-overrides-and-capacity.php on line 26