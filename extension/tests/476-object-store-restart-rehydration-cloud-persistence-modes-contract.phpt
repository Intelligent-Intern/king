--TEST--
King object-store export and restore paths rehydrate cleanly after restart across real cloud persistence modes
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/object_store_s3_mock_helper.inc';

function king_object_store_476_cleanup_tree(string $path): void
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
                king_object_store_476_cleanup_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_476_sorted_object_ids(): array
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
    'cloud_s3' => [
        'provider' => 's3',
        'presence_key' => 'cloud_s3_present',
        'bucket_or_container_key' => 'bucket',
        'bucket_or_container_value' => 'restart-s3-bucket',
        'credentials' => [
            'access_key' => 'access',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
            'path_style' => true,
            'verify_tls' => false,
        ],
        'mock_options' => [],
    ],
    'cloud_gcs' => [
        'provider' => 'gcs',
        'presence_key' => 'cloud_gcs_present',
        'bucket_or_container_key' => 'bucket',
        'bucket_or_container_value' => 'restart-gcs-bucket',
        'credentials' => [
            'access_token' => 'gcs-token',
            'path_style' => true,
            'verify_tls' => false,
        ],
        'mock_options' => [
            'provider' => 'gcs',
            'expected_access_token' => 'gcs-token',
        ],
    ],
    'cloud_azure' => [
        'provider' => 'azure',
        'presence_key' => 'cloud_azure_present',
        'bucket_or_container_key' => 'container',
        'bucket_or_container_value' => 'restart-azure-container',
        'credentials' => [
            'access_token' => 'azure-token',
            'verify_tls' => false,
        ],
        'mock_options' => [
            'provider' => 'azure',
            'expected_access_token' => 'azure-token',
        ],
    ],
];

foreach ($cases as $backend => $case) {
    $root = sys_get_temp_dir() . '/king_object_store_restart_476_' . $backend . '_' . getmypid();
    $exportDir = $root . '/exports/object';
    $snapshotDir = $root . '/snapshots/full';
    $mock = king_object_store_s3_mock_start_server(
        null,
        '127.0.0.1',
        $case['mock_options']
    );

    $config = [
        'storage_root_path' => $root,
        'primary_backend' => $backend,
        'backup_backend' => $backend,
        'cloud_credentials' => array_merge(
            [
                'api_endpoint' => $mock['endpoint'],
            ],
            [
                $case['bucket_or_container_key'] => $case['bucket_or_container_value'],
            ],
            $case['credentials']
        ),
    ];

    king_object_store_476_cleanup_tree($root);
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
    var_dump($stats['runtime_primary_adapter_status']);
    var_dump($stats['object_count']);
    var_dump($stats['stored_bytes']);

    var_dump(king_object_store_get('doc-export'));
    var_dump(king_object_store_get('doc-snapshot'));
    var_dump(king_object_store_476_sorted_object_ids());

    $exportMeta = king_object_store_get_metadata('doc-export');
    $snapshotMeta = king_object_store_get_metadata('doc-snapshot');
    var_dump($exportMeta['content_length']);
    var_dump($snapshotMeta['content_length']);
    var_dump($exportMeta[$case['presence_key']]);
    var_dump($snapshotMeta[$case['presence_key']]);

    $capture = king_object_store_s3_mock_stop_server($mock);
    var_dump(count($capture['events']) > 0);

    king_object_store_476_cleanup_tree($root);
    king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
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
string(2) "ok"
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
bool(true)
string(2) "ok"
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
bool(true)
string(2) "ok"
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
