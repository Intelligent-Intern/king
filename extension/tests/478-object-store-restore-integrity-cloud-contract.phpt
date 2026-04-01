--TEST--
King object-store restore rejects corrupted archives before they become live across real cloud backends
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

function king_object_store_478_cleanup_tree(string $path): void
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
                king_object_store_478_cleanup_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

$snapshotPayload = 'snapshot-payload';
$tamperedPayload = 'tampered-payload';
$secondSnapshot = 'snapshot-second';
$livePayload = 'live-current';
$liveSecond = 'live-second';

$cases = [
    'cloud_s3' => [
        'provider' => 's3',
        'bucket_or_container_key' => 'bucket',
        'bucket_or_container_value' => 'restore-integrity-s3',
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
        'bucket_or_container_key' => 'bucket',
        'bucket_or_container_value' => 'restore-integrity-gcs',
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
        'bucket_or_container_key' => 'container',
        'bucket_or_container_value' => 'restore-integrity-azure',
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
    $root = sys_get_temp_dir() . '/king_object_store_restore_integrity_478_' . $backend . '_' . getmypid();
    $exportDir = $root . '/export';
    $snapshotDir = $root . '/snapshot';
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

    king_object_store_478_cleanup_tree($root);
    mkdir($root, 0700, true);

    var_dump(king_object_store_init($config));
    var_dump(king_object_store_put('doc-restore', $snapshotPayload));
    var_dump(king_object_store_put('doc-second', $secondSnapshot));
    var_dump(king_object_store_backup_object('doc-restore', $exportDir));
    var_dump(king_object_store_backup_all_objects($snapshotDir));
    var_dump(king_object_store_put('doc-restore', $livePayload));
    var_dump(king_object_store_put('doc-second', $liveSecond));

    file_put_contents($exportDir . '/doc-restore', $tamperedPayload);

    var_dump(king_object_store_restore_object('doc-restore', $exportDir));
    var_dump(king_object_store_get('doc-restore'));

    file_put_contents($snapshotDir . '/doc-restore', $tamperedPayload);

    var_dump(king_object_store_restore_all_objects($snapshotDir));
    var_dump(king_object_store_get('doc-restore'));
    var_dump(king_object_store_get('doc-second'));

    $capture = king_object_store_s3_mock_stop_server($mock);
    var_dump(count($capture['events']) > 0);

    king_object_store_478_cleanup_tree($root);
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
bool(false)
string(12) "live-current"
bool(false)
string(12) "live-current"
string(11) "live-second"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
string(12) "live-current"
bool(false)
string(12) "live-current"
string(11) "live-second"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
string(12) "live-current"
bool(false)
string(12) "live-current"
string(11) "live-second"
bool(true)
