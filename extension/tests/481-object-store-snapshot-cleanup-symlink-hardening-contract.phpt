--TEST--
King object-store snapshot cleanup does not traverse directory symlinks outside the snapshot tree
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function king_object_store_481_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        king_object_store_481_remove_tree($path . '/' . $entry);
    }

    @rmdir($path);
}

$primary = sys_get_temp_dir() . '/king_object_store_snapshot_primary_481_' . getmypid();
$snapshot = $primary . '/snapshots/full';
$outside = sys_get_temp_dir() . '/king_object_store_snapshot_outside_481_' . getmypid();
$outside_nested = $outside . '/nested';
$outside_secret = $outside_nested . '/secret.txt';
$snapshot_link = $snapshot . '/outside-link';

foreach ([$primary, $outside] as $path) {
    king_object_store_481_remove_tree($path);
    @mkdir($path, 0700, true);
}
@mkdir($outside_nested, 0700, true);
file_put_contents($outside_secret, 'keep-me');

king_object_store_init([
    'storage_root_path' => $primary,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_put('asset-1', 'alpha-payload'));
var_dump(king_object_store_backup_all_objects($snapshot));
var_dump(symlink($outside_nested, $snapshot_link));
var_dump(is_link($snapshot_link));
var_dump(file_exists($outside_secret));
var_dump(king_object_store_put('asset-2', 'beta-payload'));
var_dump(king_object_store_backup_all_objects($snapshot));
var_dump(file_exists($outside_secret));
var_dump(king_object_store_get('asset-1'));
var_dump(king_object_store_get('asset-2'));

king_object_store_481_remove_tree($primary);
king_object_store_481_remove_tree($outside);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(13) "alpha-payload"
string(12) "beta-payload"
