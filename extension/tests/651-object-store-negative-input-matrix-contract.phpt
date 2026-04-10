--TEST--
King object-store restore fails closed for traversal injection and corrupt-manifest inputs
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function king_object_store_651_remove_tree(string $path): void
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
            king_object_store_651_remove_tree($child);
            @rmdir($child);
            continue;
        }

        @unlink($child);
    }

    @rmdir($path);
}

function king_object_store_651_clone_snapshot(string $source, string $target): void
{
    @mkdir($target, 0700, true);
    foreach (scandir($source) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        copy($source . '/' . $entry, $target . '/' . $entry);
    }
}

$root = sys_get_temp_dir() . '/king_object_store_negative_matrix_651_' . getmypid();
$seedSnapshot = $root . '/snapshots/seed';
$traversalSnapshot = $root . '/imports/traversal';
$injectionSnapshot = $root . '/imports/injection';
$corruptSnapshot = $root . '/imports/corrupt';
$manifestName = '.king_snapshot_manifest';

king_object_store_651_remove_tree($root);
@mkdir($root . '/snapshots', 0700, true);
@mkdir($root . '/imports', 0700, true);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_put('asset-safe', 'v1'));
var_dump(king_object_store_backup_all_objects($seedSnapshot));
var_dump(king_object_store_delete('asset-safe'));

$seedManifestPath = $seedSnapshot . '/' . $manifestName;
$seedManifest = (string) file_get_contents($seedManifestPath);

king_object_store_651_clone_snapshot($seedSnapshot, $traversalSnapshot);
king_object_store_651_clone_snapshot($seedSnapshot, $injectionSnapshot);
king_object_store_651_clone_snapshot($seedSnapshot, $corruptSnapshot);

$traversalManifest = str_replace(
    'upsert_object_id=asset-safe',
    'upsert_object_id=../escape',
    $seedManifest
);
if ($traversalManifest === $seedManifest) {
    throw new RuntimeException('failed to prepare traversal manifest');
}
file_put_contents($traversalSnapshot . '/' . $manifestName, $traversalManifest);

$injectionManifest = str_replace(
    'upsert_object_id=asset-safe',
    'upsert_object_id=evil' . chr(31) . 'id',
    $seedManifest
);
if ($injectionManifest === $seedManifest) {
    throw new RuntimeException('failed to prepare injection manifest');
}
file_put_contents($injectionSnapshot . '/' . $manifestName, $injectionManifest);

$corruptManifest = str_replace(
    'upsert_count=1',
    'upsert_count=1x',
    $seedManifest
);
if ($corruptManifest === $seedManifest) {
    throw new RuntimeException('failed to prepare corrupt manifest');
}
file_put_contents($corruptSnapshot . '/' . $manifestName, $corruptManifest);

var_dump(king_object_store_restore_all_objects($traversalSnapshot));
var_dump(king_object_store_restore_all_objects($injectionSnapshot));
var_dump(king_object_store_restore_all_objects($corruptSnapshot));
var_dump(king_object_store_get('asset-safe'));

king_object_store_651_remove_tree($root);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
bool(false)
