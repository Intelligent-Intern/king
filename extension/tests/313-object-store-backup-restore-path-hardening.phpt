--TEST--
King object-store backup and restore reject directories outside the active storage root
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$root = sys_get_temp_dir() . '/king_obj_backup_restore_root_' . getmypid();
$inside = $root . '/bundle';
$outside = sys_get_temp_dir() . '/king_obj_backup_restore_outside_' . getmypid();

foreach ([$inside, $outside, $root] as $path) {
    if (is_dir($path)) {
        foreach (scandir($path) as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($path . '/' . $file);
            }
        }
        @rmdir($path);
    }
}

mkdir($root, 0700);
mkdir($inside, 0700);
mkdir($outside, 0700);

king_object_store_init(['storage_root_path' => $root]);
var_dump(king_object_store_put('asset-1', 'alpha-payload'));

var_dump(king_object_store_backup_object('asset-1', $outside));
var_dump(file_exists($outside . '/asset-1'));

file_put_contents($outside . '/asset-1', 'outside-payload');
file_put_contents($outside . '/asset-1.meta', "object_id=asset-1\ncontent_length=15\n");

var_dump(king_object_store_delete('asset-1'));
var_dump(king_object_store_restore_object('asset-1', $outside));
var_dump(king_object_store_get('asset-1'));

var_dump(king_object_store_put('asset-1', 'alpha-payload'));
var_dump(king_object_store_backup_object('asset-1', $inside));
var_dump(file_exists($inside . '/asset-1'));

foreach ([$inside, $outside, $root] as $path) {
    if (!is_dir($path)) {
        continue;
    }
    foreach (scandir($path) as $file) {
        if ($file !== '.' && $file !== '..') {
            @unlink($path . '/' . $file);
        }
    }
    @rmdir($path);
}
?>
--EXPECT--
bool(true)
bool(false)
bool(false)
bool(true)
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
