--TEST--
King object-store HA: replication and backup failure semantics
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$dir = sys_get_temp_dir() . '/king_ha_' . getmypid();
if (!is_dir($dir)) mkdir($dir, 0755, true);

// replication + in-tree backup backend
king_object_store_init([
    'storage_root_path' => $dir,
    'replication_factor' => 2,
    'backup_backend' => 'memory_cache',
]);

king_object_store_put('high_avail_doc', 'safe in memory cache');

$meta = king_object_store_get_metadata('high_avail_doc');
var_dump($meta['object_id']);
var_dump($meta['is_backed_up']);
var_dump($meta['replication_status']);

// cloud backup backend should now fail with explicit adapter failure
king_object_store_init([
    'storage_root_path' => $dir,
    'backup_backend' => 'cloud_s3',
]);

try {
    king_object_store_put('cloud_backup_doc', 'will not be persisted');
    echo "unexpected_put\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
}

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_backup_adapter_contract']);
var_dump($stats['runtime_backup_adapter_status']);

// Cleanup
foreach (scandir($dir) as $f) { if ($f !== '.' && $f !== '..') @unlink("$dir/$f"); }
@rmdir($dir);
?>
--EXPECT--
string(14) "high_avail_doc"
int(0)
int(2)
string(20) "King\SystemException"
string(9) "simulated"
string(6) "failed"
