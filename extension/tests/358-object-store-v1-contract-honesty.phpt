--TEST--
King object-store exposes an honest local_fs-only v1 backend contract
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$root = sys_get_temp_dir() . '/king_object_store_contract_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'memory_cache',
]));

var_dump(king_object_store_put('doc-1', 'payload'));
var_dump(king_object_store_get('doc-1'));

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'memory_cache',
    'backup_backend' => 'cloud_s3',
]));

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_backend']);
var_dump($stats['runtime_primary_backend_contract']);
var_dump($stats['runtime_storage_contract']);
var_dump($stats['runtime_legacy_backend_alias']);
var_dump($stats['runtime_simulated_backends']);
var_dump($stats['runtime_backup_backend']);
var_dump($stats['runtime_backup_adapter_contract']);
var_dump($stats['runtime_backup_adapter_status']);

$component = king_system_get_component_info('object_store');
var_dump($component['configuration']['storage_contract']);
var_dump($component['configuration']['legacy_backend_alias']);
var_dump($component['configuration']['simulated_backends']);

foreach (scandir($root) as $file) {
    if ($file !== '.' && $file !== '..') {
        @unlink($root . '/' . $file);
    }
}
@rmdir($root);
?>
--EXPECT--
bool(true)
bool(true)
string(7) "payload"
bool(true)
string(8) "local_fs"
string(5) "local"
string(13) "local_fs_only"
string(22) "memory_cache->local_fs"
string(42) "distributed,cloud_s3,cloud_gcs,cloud_azure"
string(8) "cloud_s3"
string(9) "simulated"
string(9) "simulated"
string(13) "local_fs_only"
string(22) "memory_cache->local_fs"
string(42) "distributed,cloud_s3,cloud_gcs,cloud_azure"
