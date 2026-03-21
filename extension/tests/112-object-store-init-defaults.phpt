--TEST--
King object-store init applies stable local runtime defaults in the skeleton build
--FILE--
<?php
$stats = king_object_store_get_stats();
var_dump($stats['object_store']['runtime_initialized']);
var_dump($stats['cdn']['runtime_initialized']);

var_dump(king_object_store_init([]));

$stats = king_object_store_get_stats();
var_dump($stats['object_store']['runtime_initialized']);
var_dump($stats['object_store']['runtime_primary_backend']);
var_dump($stats['object_store']['runtime_storage_root_path']);
var_dump($stats['object_store']['runtime_max_storage_size_bytes']);
var_dump($stats['object_store']['runtime_replication_factor']);
var_dump($stats['object_store']['runtime_chunk_size_kb']);
var_dump($stats['cdn']['runtime_initialized']);
var_dump($stats['cdn']['runtime_enabled']);
var_dump($stats['cdn']['runtime_cache_size_mb']);
var_dump($stats['cdn']['runtime_default_ttl_sec']);
?>
--EXPECT--
bool(false)
bool(false)
bool(true)
bool(true)
string(8) "local_fs"
string(22) "/tmp/king_object_store"
int(10737418240)
int(3)
int(65536)
bool(true)
bool(false)
int(512)
int(86400)
