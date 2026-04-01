--TEST--
King object-store incremental backups emit manifest deltas and restore as patch snapshots
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function king_object_store_467_remove_tree(string $path): void
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
            king_object_store_467_remove_tree($child);
            @rmdir($child);
            continue;
        }

        @unlink($child);
    }

    @rmdir($path);
}

$primary = sys_get_temp_dir() . '/king_object_store_incremental_primary_467_' . getmypid();
$secondary = sys_get_temp_dir() . '/king_object_store_incremental_secondary_467_' . getmypid();
$fullSnapshot = $primary . '/snapshots/full';
$incrementalSnapshot = $primary . '/snapshots/incremental';
$importFullSnapshot = $secondary . '/imports/full';
$importIncrementalSnapshot = $secondary . '/imports/incremental';
$fullManifest = $fullSnapshot . '/.king_snapshot_manifest';
$incrementalManifest = $incrementalSnapshot . '/.king_snapshot_manifest';

foreach ([$primary, $secondary] as $path) {
    king_object_store_467_remove_tree($path);
    @mkdir($path, 0700, true);
}

king_object_store_init([
    'storage_root_path' => $primary,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_put('asset-1', 'alpha-v1'));
var_dump(king_object_store_put('asset-2', 'beta-v1'));
var_dump(king_object_store_backup_all_objects($fullSnapshot));
var_dump(file_exists($fullManifest));
var_dump(str_contains((string) file_get_contents($fullManifest), 'kind=full_backup'));

var_dump(king_object_store_put('asset-1', 'alpha-v2'));
var_dump(king_object_store_delete('asset-2'));
var_dump(king_object_store_put('asset-3', 'gamma-v1'));
var_dump(king_object_store_backup_all_objects($incrementalSnapshot, [
    'mode' => 'incremental',
    'base_snapshot_path' => $fullSnapshot,
]));
var_dump(file_exists($incrementalManifest));

$incrementalManifestText = (string) file_get_contents($incrementalManifest);
var_dump(str_contains($incrementalManifestText, 'kind=incremental_backup'));
var_dump(str_contains($incrementalManifestText, 'upsert_object_id=asset-1'));
var_dump(str_contains($incrementalManifestText, 'upsert_object_id=asset-3'));
var_dump(str_contains($incrementalManifestText, 'delete_object_id=asset-2'));
var_dump(file_exists($incrementalSnapshot . '/asset-1'));
var_dump(file_exists($incrementalSnapshot . '/asset-3'));
var_dump(file_exists($incrementalSnapshot . '/asset-2'));

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

king_object_store_init([
    'storage_root_path' => $secondary,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_restore_all_objects($importFullSnapshot));
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

foreach ([$primary, $secondary] as $path) {
    king_object_store_467_remove_tree($path);
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
string(8) "alpha-v2"
bool(false)
string(8) "gamma-v1"
array(2) {
  [0]=>
  string(7) "asset-1"
  [1]=>
  string(7) "asset-3"
}
