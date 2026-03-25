--TEST--
King object-store restart crash-recovery for persisted backends
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function cleanup_object_store_root(string $root): void
{
    if (!is_dir($root)) {
        return;
    }

    foreach (scandir($root) as $file) {
        if ($file !== '.' && $file !== '..') {
            @unlink("$root/$file");
        }
    }
    @rmdir($root);
}

// Local file backend should rehydrate counters and persisted payloads after restart.
$local_root = sys_get_temp_dir() . '/king_object_store_restart_local_' . getmypid();
mkdir($local_root, 0777, true);

king_object_store_init([
    'storage_root_path' => $local_root,
    'primary_backend'  => 'local_fs',
    'max_storage_size_bytes' => 4096,
]);

var_dump(king_object_store_put('doc-local-a', 'local-payload-alpha'));
var_dump(king_object_store_put('doc-local-b', 'local-payload-beta'));
var_dump(king_object_store_get_metadata('doc-local-a')['content_length']);

$before_stats = king_object_store_get_stats()['object_store'];
var_dump($before_stats['object_count']);     // 2
var_dump($before_stats['stored_bytes']);     // 2 payloads

// Simulate a crash/restart by re-initializing with same storage root.
king_object_store_init([
    'storage_root_path' => $local_root,
    'primary_backend'  => 'local_fs',
    'max_storage_size_bytes' => 4096,
]);

$after_stats = king_object_store_get_stats()['object_store'];
var_dump($after_stats['object_count']);      // Should remain 2
var_dump($after_stats['stored_bytes']);      // Should remain same after re-hydration
var_dump(king_object_store_get('doc-local-a'));
var_dump(king_object_store_get_metadata('doc-local-a')['content_length']);
var_dump(king_object_store_get('doc-local-b'));

king_object_store_init([
    'storage_root_path' => $local_root,
    'primary_backend'  => 'local_fs',
    'max_storage_size_bytes' => 4096,
]);

king_object_store_delete('doc-local-b');
$stats_after_delete = king_object_store_get_stats()['object_store'];
var_dump($stats_after_delete['object_count']);

// Memory-cache-backed backend should also rehydrate from persisted objects.
$cache_root = sys_get_temp_dir() . '/king_object_store_restart_memory_' . getmypid();
mkdir($cache_root, 0777, true);

king_object_store_init([
    'storage_root_path' => $cache_root,
    'primary_backend'  => 'memory_cache',
    'max_storage_size_bytes' => 4096,
]);

var_dump(king_object_store_put('doc-cache-a', 'cache-payload-one'));
var_dump(king_object_store_get('doc-cache-a'));
var_dump(king_object_store_get_metadata('doc-cache-a')['content_length']);

// Restart under memory_cache path.
king_object_store_init([
    'storage_root_path' => $cache_root,
    'primary_backend'  => 'memory_cache',
    'max_storage_size_bytes' => 4096,
]);

$cache_after = king_object_store_get_stats()['object_store'];
var_dump($cache_after['object_count']);
var_dump(king_object_store_get('doc-cache-a'));
var_dump(king_object_store_get_metadata('doc-cache-a')['content_length']);

cleanup_object_store_root($local_root);
cleanup_object_store_root($cache_root);
?>
--EXPECT--
bool(true)
bool(true)
int(19)
int(2)
int(37)
int(2)
int(37)
string(19) "local-payload-alpha"
int(19)
string(18) "local-payload-beta"
int(1)
bool(true)
string(17) "cache-payload-one"
int(17)
int(1)
string(17) "cache-payload-one"
int(17)
