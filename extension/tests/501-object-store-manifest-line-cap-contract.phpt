--TEST--
King object-store restore rejects oversized snapshot manifest lines without mutating live state
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function king_object_store_501_remove_tree(string $path): void
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
            king_object_store_501_remove_tree($child);
            @rmdir($child);
            continue;
        }

        @unlink($child);
    }

    @rmdir($path);
}

$source = sys_get_temp_dir() . '/king_object_store_manifest_cap_source_501_' . getmypid();
$target = sys_get_temp_dir() . '/king_object_store_manifest_cap_target_501_' . getmypid();
$snapshot = $source . '/snapshots/full';
$import = $target . '/import';
$manifest = $import . '/.king_snapshot_manifest';

foreach ([$source, $target] as $path) {
    king_object_store_501_remove_tree($path);
    @mkdir($path, 0700, true);
}

king_object_store_init([
    'storage_root_path' => $source,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_put('asset-1', 'alpha-payload'));
var_dump(king_object_store_backup_all_objects($snapshot));

@mkdir($import, 0700, true);
foreach (scandir($snapshot) as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    copy($snapshot . '/' . $entry, $import . '/' . $entry);
}

file_put_contents(
    $manifest,
    (string) file_get_contents($manifest)
    . 'object_id=' . str_repeat('a', 5000) . "\n"
);

king_object_store_init([
    'storage_root_path' => $target,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_put('preexisting', 'keep-me'));
var_dump(king_object_store_restore_all_objects($import));
var_dump(king_object_store_get('asset-1'));
var_dump(king_object_store_get('preexisting'));

$all = array_map(
    static fn(array $entry): string => $entry['object_id'],
    king_object_store_list()
);
sort($all);
var_dump($all);

foreach ([$source, $target] as $path) {
    king_object_store_501_remove_tree($path);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
string(7) "keep-me"
array(1) {
  [0]=>
  string(11) "preexisting"
}
