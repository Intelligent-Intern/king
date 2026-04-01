--TEST--
King object-store full backups commit a manifest-backed snapshot without stale object resurrection
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function king_object_store_465_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . '/' . $entry;
        if (is_dir($child)) {
            king_object_store_465_remove_tree($child);
            @rmdir($child);
            continue;
        }

        @unlink($child);
    }

    @rmdir($path);
}

$primary = sys_get_temp_dir() . '/king_object_store_snapshot_primary_465_' . getmypid();
$secondary = sys_get_temp_dir() . '/king_object_store_snapshot_secondary_465_' . getmypid();
$snapshot = $primary . '/snapshots/full';
$import = $secondary . '/import';
$manifest = $snapshot . '/.king_snapshot_manifest';

foreach ([$primary, $secondary] as $path) {
    king_object_store_465_remove_tree($path);
    @mkdir($path, 0700, true);
}

king_object_store_init([
    'storage_root_path' => $primary,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_put('asset-1', 'alpha-payload'));
var_dump(king_object_store_put('asset-2', 'beta-payload'));
var_dump(king_object_store_backup_all_objects($snapshot));
var_dump(file_exists($manifest));
var_dump(str_contains((string) file_get_contents($manifest), 'format=king_object_store_snapshot_v1'));
var_dump(str_contains((string) file_get_contents($manifest), 'consistency=per_object_locked_commit'));

var_dump(king_object_store_delete('asset-2'));
var_dump(king_object_store_backup_all_objects($snapshot));
var_dump(file_exists($snapshot . '/asset-1'));
var_dump(file_exists($snapshot . '/asset-2'));
var_dump(str_contains((string) file_get_contents($manifest), 'object_id=asset-2'));

file_put_contents($snapshot . '/ghost-object', 'ghost-payload');
file_put_contents($snapshot . '/ghost-object.meta', "object_id=ghost-object\ncontent_length=13\n");

@mkdir($import, 0700, true);
foreach (scandir($snapshot) as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    copy($snapshot . '/' . $entry, $import . '/' . $entry);
}

king_object_store_init([
    'storage_root_path' => $secondary,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_restore_all_objects($import));
var_dump(king_object_store_get('asset-1'));
var_dump(king_object_store_get('asset-2'));
var_dump(king_object_store_get('ghost-object'));

$all = array_map(
    static fn(array $entry): string => $entry['object_id'],
    king_object_store_list()
);
sort($all);
var_dump($all);

foreach ([$primary, $secondary] as $path) {
    king_object_store_465_remove_tree($path);
}
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
bool(true)
bool(false)
bool(false)
bool(true)
string(13) "alpha-payload"
bool(false)
bool(false)
array(1) {
  [0]=>
  string(7) "asset-1"
}
