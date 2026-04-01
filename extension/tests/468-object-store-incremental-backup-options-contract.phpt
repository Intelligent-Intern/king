--TEST--
King object-store incremental backups require an explicit base snapshot path
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$root = sys_get_temp_dir() . '/king_object_store_incremental_options_468_' . getmypid();
@mkdir($root, 0700, true);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
]);

try {
    king_object_store_backup_all_objects($root . '/snapshots/incremental', [
        'mode' => 'incremental',
    ]);
    var_dump('no-throw');
} catch (King\ValidationException $e) {
    var_dump($e->getMessage());
}

try {
    king_object_store_backup_all_objects($root . '/snapshots/full', [
        'mode' => 'weird',
    ]);
    var_dump('no-throw');
} catch (King\ValidationException $e) {
    var_dump($e->getMessage());
}
?>
--EXPECT--
string(93) "king_object_store_backup_all_objects() incremental mode requires option 'base_snapshot_path'."
string(92) "king_object_store_backup_all_objects() option 'mode' must be either 'full' or 'incremental'."
