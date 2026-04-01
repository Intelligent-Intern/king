--TEST--
King object-store export and restore paths rehydrate cleanly after restart across local persistence modes
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function king_object_store_475_cleanup_tree(string $path): void
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
                king_object_store_475_cleanup_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_475_sorted_object_ids(): array
{
    $objectIds = array_map(
        static fn(array $entry): string => $entry['object_id'],
        king_object_store_list()
    );
    sort($objectIds);
    return $objectIds;
}

$payloadExport = 'alpha-export';
$payloadSnapshot = 'beta-snapshot';
$expectedStoredBytes = strlen($payloadExport) + strlen($payloadSnapshot);

$cases = [
    [
        'backend' => 'local_fs',
        'presence_key' => 'local_fs_present',
        'check_distributed_recovery' => false,
    ],
    [
        'backend' => 'memory_cache',
        'presence_key' => 'local_fs_present',
        'check_distributed_recovery' => false,
    ],
    [
        'backend' => 'distributed',
        'presence_key' => 'distributed_present',
        'check_distributed_recovery' => true,
    ],
];

foreach ($cases as $case) {
    $backend = $case['backend'];
    $root = sys_get_temp_dir() . '/king_object_store_restart_475_' . $backend . '_' . getmypid();
    $exportDir = $root . '/exports/object';
    $snapshotDir = $root . '/snapshots/full';
    $config = [
        'storage_root_path' => $root,
        'primary_backend' => $backend,
        'backup_backend' => $backend,
    ];

    king_object_store_475_cleanup_tree($root);
    mkdir($root, 0700, true);

    var_dump(king_object_store_init($config));
    var_dump(king_object_store_put('doc-export', $payloadExport));
    var_dump(king_object_store_put('doc-snapshot', $payloadSnapshot));
    var_dump(king_object_store_backup_object('doc-export', $exportDir));
    var_dump(king_object_store_backup_all_objects($snapshotDir));
    var_dump(king_object_store_delete('doc-export'));
    var_dump(king_object_store_delete('doc-snapshot'));
    var_dump(king_object_store_restore_object('doc-export', $exportDir));
    var_dump(king_object_store_restore_all_objects($snapshotDir));

    var_dump(king_object_store_init($config));
    $stats = king_object_store_get_stats()['object_store'];
    var_dump($stats['object_count']);
    var_dump($stats['stored_bytes']);
    if ($case['check_distributed_recovery']) {
        var_dump($stats['runtime_distributed_coordinator_state_recovered']);
    }

    var_dump(king_object_store_get('doc-export'));
    var_dump(king_object_store_get('doc-snapshot'));
    var_dump(king_object_store_475_sorted_object_ids());

    $exportMeta = king_object_store_get_metadata('doc-export');
    $snapshotMeta = king_object_store_get_metadata('doc-snapshot');
    var_dump($exportMeta['content_length']);
    var_dump($snapshotMeta['content_length']);
    var_dump($exportMeta[$case['presence_key']]);
    var_dump($snapshotMeta[$case['presence_key']]);

    king_object_store_475_cleanup_tree($root);
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
int(2)
int(25)
string(12) "alpha-export"
string(13) "beta-snapshot"
array(2) {
  [0]=>
  string(10) "doc-export"
  [1]=>
  string(12) "doc-snapshot"
}
int(12)
int(13)
int(1)
int(1)
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
int(2)
int(25)
string(12) "alpha-export"
string(13) "beta-snapshot"
array(2) {
  [0]=>
  string(10) "doc-export"
  [1]=>
  string(12) "doc-snapshot"
}
int(12)
int(13)
int(1)
int(1)
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
int(2)
int(25)
bool(true)
string(12) "alpha-export"
string(13) "beta-snapshot"
array(2) {
  [0]=>
  string(10) "doc-export"
  [1]=>
  string(12) "doc-snapshot"
}
int(12)
int(13)
int(1)
int(1)
