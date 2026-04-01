--TEST--
King object-store import rejects metadata-tampered archives before they become live on local_fs
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$root = sys_get_temp_dir() . '/king_object_store_import_integrity_479_' . getmypid();
$exportIdDir = $root . '/export-id';
$exportLengthDir = $root . '/export-length';
$snapshotDir = $root . '/snapshot';

function king_object_store_479_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                king_object_store_479_cleanup_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_479_replace_meta_value(string $path, string $key, string $value): void
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('failed to read metadata fixture');
    }

    $updated = preg_replace(
        '/^' . preg_quote($key, '/') . '=.*$/m',
        $key . '=' . $value,
        $contents,
        1,
        $count
    );

    if (!is_string($updated) || $count !== 1) {
        throw new RuntimeException('failed to replace metadata key ' . $key);
    }

    if (file_put_contents($path, $updated) === false) {
        throw new RuntimeException('failed to write metadata fixture');
    }
}

king_object_store_479_cleanup_tree($root);
mkdir($root, 0700, true);

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
]));
var_dump(king_object_store_put('doc-id', 'snapshot-id'));
var_dump(king_object_store_put('doc-length', 'snapshot-length'));
var_dump(king_object_store_put('doc-batch', 'snapshot-batch'));
var_dump(king_object_store_put('doc-batch-2', 'snapshot-batch-second'));
var_dump(king_object_store_backup_object('doc-id', $exportIdDir));
var_dump(king_object_store_backup_object('doc-length', $exportLengthDir));
var_dump(king_object_store_backup_all_objects($snapshotDir));
var_dump(king_object_store_put('doc-id', 'live-current-id'));
var_dump(king_object_store_put('doc-length', 'live-current-length'));
var_dump(king_object_store_put('doc-batch', 'live-current-batch'));
var_dump(king_object_store_put('doc-batch-2', 'live-current-batch-second'));

king_object_store_479_replace_meta_value(
    $exportIdDir . '/doc-id.meta',
    'object_id',
    'other-id'
);
king_object_store_479_replace_meta_value(
    $exportLengthDir . '/doc-length.meta',
    'content_length',
    (string) (strlen('snapshot-length') + 5)
);
king_object_store_479_replace_meta_value(
    $snapshotDir . '/doc-batch.meta',
    'integrity_sha256',
    str_repeat('0', 64)
);

var_dump(king_object_store_restore_object('doc-id', $exportIdDir));
var_dump(king_object_store_get('doc-id'));
var_dump(king_object_store_restore_object('doc-length', $exportLengthDir));
var_dump(king_object_store_get('doc-length'));
var_dump(king_object_store_restore_all_objects($snapshotDir));
var_dump(king_object_store_get('doc-batch'));
var_dump(king_object_store_get('doc-batch-2'));

king_object_store_479_cleanup_tree($root);
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
bool(false)
string(15) "live-current-id"
bool(false)
string(19) "live-current-length"
bool(false)
string(18) "live-current-batch"
string(25) "live-current-batch-second"
