--TEST--
King object-store backup and restore with payload + `.meta` persistence
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$primary = sys_get_temp_dir() . '/king_object_store_backup_primary_' . getmypid();
$secondary = sys_get_temp_dir() . '/king_object_store_backup_secondary_' . getmypid();
$backup = $primary . '/backups';

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $full = $path . '/' . $file;
        if (is_dir($full)) {
            remove_tree($full);
            continue;
        }

        @unlink($full);
    }

    @rmdir($path);
}

foreach ([$primary, $secondary, $backup] as $path) {
    if (is_dir($path)) {
        remove_tree($path);
    }
    mkdir($path, 0700);
}

king_object_store_init(['storage_root_path' => $primary]);

var_dump(king_object_store_put('asset-1', 'alpha-payload'));
$metadata_before = king_object_store_get_metadata('asset-1');
var_dump($metadata_before['content_length']);
var_dump(king_object_store_backup_object('asset-1', $backup));
var_dump(file_exists($backup . '/asset-1'));
var_dump(file_exists($backup . '/asset-1.meta'));

var_dump(king_object_store_backup_object('missing-asset', $backup));

var_dump(king_object_store_delete('asset-1'));
var_dump(king_object_store_get('asset-1'));
var_dump(king_object_store_restore_object('asset-1', $backup));
var_dump(king_object_store_get('asset-1'));
$metadata_after = king_object_store_get_metadata('asset-1');
var_dump($metadata_after['content_length'] === $metadata_before['content_length']);

king_object_store_put('asset-2', 'beta-payload');
$backup_all = $backup . '/all';
mkdir($backup_all, 0700);

var_dump(king_object_store_backup_all_objects($backup_all));
var_dump(file_exists($backup_all . '/asset-1'));
var_dump(file_exists($backup_all . '/asset-2'));

// Restore into a fresh root and verify replay.
@unlink($secondary . '/asset-1');
@unlink($secondary . '/asset-2');
@unlink($secondary . '/asset-1.meta');
@unlink($secondary . '/asset-2.meta');
king_object_store_init(['storage_root_path' => $secondary]);
var_dump(king_object_store_get('asset-1'));
$secondary_import = $secondary . '/import-all';
mkdir($secondary_import, 0700);
foreach (scandir($backup_all) as $file) {
    if ($file !== '.' && $file !== '..') {
        copy($backup_all . '/' . $file, $secondary_import . '/' . $file);
    }
}
var_dump(king_object_store_restore_all_objects($secondary_import));
var_dump(king_object_store_get('asset-1'));
var_dump(king_object_store_get('asset-2'));

$all = king_object_store_list();
$all = array_map(fn($entry) => $entry['object_id'], $all);
sort($all);
var_dump($all);

foreach ([$primary, $secondary, $backup] as $path) {
    remove_tree($path);
}
?>
--EXPECT--
bool(true)
int(13)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
string(13) "alpha-payload"
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
string(13) "alpha-payload"
string(12) "beta-payload"
array(2) {
  [0]=>
  string(7) "asset-1"
  [1]=>
  string(7) "asset-2"
}
