--TEST--
King object-store restore fails closed when snapshot manifest count metadata does not match payload entries
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function king_object_store_641_remove_tree(string $path): void
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
            king_object_store_641_remove_tree($child);
            @rmdir($child);
            continue;
        }

        @unlink($child);
    }

    @rmdir($path);
}

$source = sys_get_temp_dir() . '/king_object_store_manifest_count_source_641_' . getmypid();
$target = sys_get_temp_dir() . '/king_object_store_manifest_count_target_641_' . getmypid();
$fullSnapshot = $source . '/snapshots/full';
$incrementalSnapshot = $source . '/snapshots/incremental';
$importFullSnapshot = $target . '/imports/full';
$importIncrementalSnapshot = $target . '/imports/incremental';
$incrementalManifest = $importIncrementalSnapshot . '/.king_snapshot_manifest';

foreach ([$source, $target] as $path) {
    king_object_store_641_remove_tree($path);
    @mkdir($path, 0700, true);
}

king_object_store_init([
    'storage_root_path' => $source,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_put('asset-1', 'alpha-v1'));
var_dump(king_object_store_put('asset-2', 'beta-v1'));
var_dump(king_object_store_backup_all_objects($fullSnapshot));

var_dump(king_object_store_put('asset-1', 'alpha-v2'));
var_dump(king_object_store_delete('asset-2'));
var_dump(king_object_store_put('asset-3', 'gamma-v1'));
var_dump(king_object_store_backup_all_objects($incrementalSnapshot, [
    'mode' => 'incremental',
    'base_snapshot_path' => $fullSnapshot,
]));

@mkdir($importFullSnapshot, 0700, true);
@mkdir($importIncrementalSnapshot, 0700, true);
foreach (scandir($fullSnapshot) as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    copy($fullSnapshot . '/' . $entry, $importFullSnapshot . '/' . $entry);
}
foreach (scandir($incrementalSnapshot) as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    copy($incrementalSnapshot . '/' . $entry, $importIncrementalSnapshot . '/' . $entry);
}

$manifestText = (string) file_get_contents($incrementalManifest);
$tamperedManifest = preg_replace('/^upsert_count=\\d+$/m', 'upsert_count=999', $manifestText);
if (!is_string($tamperedManifest) || $tamperedManifest === $manifestText) {
    throw new RuntimeException('failed to tamper incremental snapshot manifest count metadata');
}
file_put_contents($incrementalManifest, $tamperedManifest);
var_dump(str_contains($tamperedManifest, 'upsert_count=999'));

king_object_store_init([
    'storage_root_path' => $target,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_restore_all_objects($importFullSnapshot));
var_dump(king_object_store_get('asset-1'));
var_dump(king_object_store_get('asset-2'));
var_dump(king_object_store_restore_all_objects($importFullSnapshot));
var_dump(king_object_store_get('asset-1'));
var_dump(king_object_store_get('asset-2'));

var_dump(king_object_store_restore_all_objects($importIncrementalSnapshot));
var_dump(king_object_store_get('asset-1'));
var_dump(king_object_store_get('asset-2'));
var_dump(king_object_store_get('asset-3'));

$all = array_map(
    static fn(array $entry): string => $entry['object_id'],
    king_object_store_list()
);
sort($all);
var_dump($all);

foreach ([$source, $target] as $path) {
    king_object_store_641_remove_tree($path);
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
string(8) "alpha-v1"
string(7) "beta-v1"
bool(true)
string(8) "alpha-v1"
string(7) "beta-v1"
bool(false)
string(8) "alpha-v1"
string(7) "beta-v1"
bool(false)
array(2) {
  [0]=>
  string(7) "asset-1"
  [1]=>
  string(7) "asset-2"
}
