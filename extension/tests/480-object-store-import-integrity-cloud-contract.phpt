--TEST--
King object-store import rejects metadata-tampered archives before they become live across real cloud backends
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

function king_object_store_480_cleanup_tree(string $path): void
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
                king_object_store_480_cleanup_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_480_replace_meta_value(string $path, string $key, string $value): void
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

$cases = [
    'cloud_s3' => [
        'provider' => 's3',
        'bucket_or_container_key' => 'bucket',
        'bucket_or_container_value' => 'import-integrity-s3',
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
        'bucket_or_container_value' => 'import-integrity-gcs',
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
        'bucket_or_container_value' => 'import-integrity-azure',
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
    $root = sys_get_temp_dir() . '/king_object_store_import_integrity_480_' . $backend . '_' . getmypid();
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

    king_object_store_480_cleanup_tree($root);
    mkdir($root, 0700, true);

    var_dump($backend);
    var_dump(king_object_store_init($config));
    var_dump(king_object_store_put('doc-import', 'snapshot-import'));
    var_dump(king_object_store_put('doc-batch', 'snapshot-batch'));
    var_dump(king_object_store_put('doc-batch-2', 'snapshot-batch-second'));
    var_dump(king_object_store_backup_object('doc-import', $exportDir));
    var_dump(king_object_store_backup_all_objects($snapshotDir));
    var_dump(king_object_store_put('doc-import', 'live-import'));
    var_dump(king_object_store_put('doc-batch', 'live-batch'));
    var_dump(king_object_store_put('doc-batch-2', 'live-batch-second'));

    king_object_store_480_replace_meta_value(
        $exportDir . '/doc-import.meta',
        'integrity_sha256',
        str_repeat('1', 64)
    );
    king_object_store_480_replace_meta_value(
        $snapshotDir . '/doc-batch.meta',
        'content_length',
        (string) (strlen('snapshot-batch') + 9)
    );

    var_dump(king_object_store_restore_object('doc-import', $exportDir));
    var_dump(king_object_store_get('doc-import'));
    var_dump(king_object_store_restore_all_objects($snapshotDir));
    var_dump(king_object_store_get('doc-batch'));
    var_dump(king_object_store_get('doc-batch-2'));

    $capture = king_object_store_s3_mock_stop_server($mock);
    var_dump(count($capture['events']) > 0);

    king_object_store_480_cleanup_tree($root);
    king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
}
?>
--EXPECT--
string(8) "cloud_s3"
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
string(11) "live-import"
bool(false)
string(10) "live-batch"
string(17) "live-batch-second"
bool(true)
string(9) "cloud_gcs"
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
string(11) "live-import"
bool(false)
string(10) "live-batch"
string(17) "live-batch-second"
bool(true)
string(11) "cloud_azure"
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
string(11) "live-import"
bool(false)
string(10) "live-batch"
string(17) "live-batch-second"
bool(true)
