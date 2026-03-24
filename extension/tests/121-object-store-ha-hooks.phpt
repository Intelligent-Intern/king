--TEST--
King object-store HA: replication and cloud backup hooks
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$dir = sys_get_temp_dir() . '/king_ha_' . getmypid();
if (!is_dir($dir)) mkdir($dir, 0755, true);

// Init with replication factor 2 and a backup backend
king_object_store_init([
    'storage_root_path' => $dir,
    'replication_factor' => 2,
    'backup_backend' => 2, // KING_STORAGE_BACKEND_CLOUD_S3
]);

king_object_store_put('high_avail_doc', 'safe in the cloud');

// Inspect metadata
$meta = king_object_store_get_metadata('high_avail_doc');
var_dump($meta['object_id']);
var_dump($meta['is_backed_up']); // Should be 1
var_dump($meta['replication_status']); // Should be 2 (Completed)

// Cleanup
foreach (scandir($dir) as $f) { if ($f !== '.' && $f !== '..') @unlink("$dir/$f"); }
@rmdir($dir);
?>
--EXPECT--
string(14) "high_avail_doc"
int(1)
int(2)
