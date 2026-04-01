--TEST--
King object-store legacy directory restore fails closed when a later archive entry is corrupted
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function king_object_store_470_remove_tree(string $path): void
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
            king_object_store_470_remove_tree($child);
            @rmdir($child);
            continue;
        }

        @unlink($child);
    }

    @rmdir($path);
}

$source = sys_get_temp_dir() . '/king_object_store_restore_legacy_source_470_' . getmypid();
$target = sys_get_temp_dir() . '/king_object_store_restore_legacy_target_470_' . getmypid();
$export = $source . '/export';
$import = $target . '/import';

foreach ([$source, $target] as $path) {
    king_object_store_470_remove_tree($path);
    @mkdir($path, 0700, true);
}

king_object_store_init([
    'storage_root_path' => $source,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_put('asset-1', 'from-archive'));
var_dump(king_object_store_put('asset-2', 'also-from-archive'));
var_dump(king_object_store_backup_object('asset-1', $export));
var_dump(king_object_store_backup_object('asset-2', $export));

@mkdir($import, 0700, true);
foreach (scandir($export) as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    copy($export . '/' . $entry, $import . '/' . $entry);
}

@unlink($import . '/asset-2.meta');

king_object_store_init([
    'storage_root_path' => $target,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_put('asset-1', 'preexisting'));
var_dump(king_object_store_restore_all_objects($import));
var_dump(king_object_store_get('asset-1'));
var_dump(king_object_store_get('asset-2'));

$all = array_map(
    static fn(array $entry): string => $entry['object_id'],
    king_object_store_list()
);
sort($all);
var_dump($all);

foreach ([$source, $target] as $path) {
    king_object_store_470_remove_tree($path);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
string(11) "preexisting"
bool(false)
array(1) {
  [0]=>
  string(7) "asset-1"
}
