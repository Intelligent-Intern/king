--TEST--
King object-store restore rejects corrupted archives before they become live on local_fs
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$root = sys_get_temp_dir() . '/king_object_store_restore_integrity_477_' . getmypid();
$exportDir = $root . '/export';
$snapshotDir = $root . '/snapshot';

function king_object_store_477_cleanup_tree(string $path): void
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
                king_object_store_477_cleanup_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

king_object_store_477_cleanup_tree($root);
mkdir($root, 0700, true);

$snapshotPayload = 'snapshot-payload';
$tamperedPayload = 'tampered-payload';
$secondSnapshot = 'snapshot-second';
$livePayload = 'live-current';
$liveSecond = 'live-second';

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
]));
var_dump(king_object_store_put('doc-restore', $snapshotPayload));
var_dump(king_object_store_put('doc-second', $secondSnapshot));
var_dump(king_object_store_backup_object('doc-restore', $exportDir));
var_dump(king_object_store_backup_all_objects($snapshotDir));
var_dump(king_object_store_put('doc-restore', $livePayload));
var_dump(king_object_store_put('doc-second', $liveSecond));

file_put_contents($exportDir . '/doc-restore', $tamperedPayload);

var_dump(king_object_store_restore_object('doc-restore', $exportDir));
var_dump(king_object_store_get('doc-restore'));
$metadata = king_object_store_get_metadata('doc-restore');
var_dump($metadata['content_length']);

file_put_contents($snapshotDir . '/doc-restore', $tamperedPayload);

var_dump(king_object_store_restore_all_objects($snapshotDir));
var_dump(king_object_store_get('doc-restore'));
var_dump(king_object_store_get('doc-second'));

$objectIds = array_map(
    static fn(array $entry): string => $entry['object_id'],
    king_object_store_list()
);
sort($objectIds);
var_dump($objectIds);

king_object_store_477_cleanup_tree($root);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
string(12) "live-current"
int(12)
bool(false)
string(12) "live-current"
string(11) "live-second"
array(2) {
  [0]=>
  string(11) "doc-restore"
  [1]=>
  string(10) "doc-second"
}
