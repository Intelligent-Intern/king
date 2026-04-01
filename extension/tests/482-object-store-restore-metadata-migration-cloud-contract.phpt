--TEST--
King object-store committed restore_all replay preserves metadata semantics onto real cloud backends
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

function king_object_store_482_cleanup_tree(string $path): void
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
                king_object_store_482_cleanup_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_482_parse_meta(string $path): array
{
    $meta = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return $meta;
    }

    foreach ($lines as $line) {
        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }

        $meta[substr($line, 0, $separator)] = substr($line, $separator + 1);
    }

    return $meta;
}

function king_object_store_482_write_meta(string $path, array $meta): void
{
    $orderedKeys = [
        'object_id',
        'content_type',
        'content_encoding',
        'etag',
        'integrity_sha256',
        'content_length',
        'version',
        'created_at',
        'modified_at',
        'expires_at',
        'object_type',
        'cache_policy',
        'cache_ttl_seconds',
        'local_fs_present',
        'distributed_present',
        'cloud_s3_present',
        'cloud_gcs_present',
        'cloud_azure_present',
        'is_backed_up',
        'replication_status',
        'is_distributed',
        'distribution_peer_count',
    ];

    $serialized = '';
    foreach ($orderedKeys as $key) {
        $serialized .= $key . '=' . (string) ($meta[$key] ?? '') . "\n";
    }

    file_put_contents($path, $serialized);
}

function king_object_store_482_sidecar_matches(array $actual, array $expected): bool
{
    $keys = [
        'object_id',
        'content_type',
        'content_encoding',
        'etag',
        'integrity_sha256',
        'content_length',
        'version',
        'created_at',
        'expires_at',
        'object_type',
        'cache_policy',
        'cache_ttl_seconds',
        'local_fs_present',
        'distributed_present',
        'cloud_s3_present',
        'cloud_gcs_present',
        'cloud_azure_present',
        'is_backed_up',
        'replication_status',
        'is_distributed',
        'distribution_peer_count',
    ];

    foreach ($keys as $key) {
        if (($actual[$key] ?? null) !== (string) ($expected[$key] ?? '')) {
            return false;
        }
    }

    return true;
}

function king_object_store_482_public_matches(array $actual, array $expected): bool
{
    $keys = [
        'object_id',
        'content_type',
        'content_encoding',
        'etag',
        'integrity_sha256',
        'content_length',
        'version',
        'created_at',
        'expires_at',
        'object_type',
        'cache_policy',
        'cache_ttl_seconds',
        'local_fs_present',
        'distributed_present',
        'cloud_s3_present',
        'cloud_gcs_present',
        'cloud_azure_present',
        'is_backed_up',
        'replication_status',
        'is_distributed',
        'distribution_peer_count',
    ];

    foreach ($keys as $key) {
        $expectedValue = $expected[$key] ?? '';
        if (is_int($actual[$key] ?? null)) {
            if (($actual[$key] ?? null) !== (int) $expectedValue) {
                return false;
            }
            continue;
        }

        if (($actual[$key] ?? null) !== (string) $expectedValue) {
            return false;
        }
    }

    return true;
}

$cases = [
    'cloud_s3' => [
        'provider' => 's3',
        'presence_key' => 'cloud_s3_present',
        'bucket_or_container_key' => 'bucket',
        'bucket_or_container_value' => 'restore-meta-s3',
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
        'bucket_or_container_value' => 'restore-meta-gcs',
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
        'bucket_or_container_value' => 'restore-meta-azure',
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
    $root = sys_get_temp_dir() . '/king_object_store_restore_meta_482_' . $backend . '_' . getmypid();
    $snapshotDir = $root . '/snapshot';
    $objectId = 'doc-restore-meta-' . $case['provider'];
    $payload = "restore-meta\0" . $case['provider'];
    $integrity = hash('sha256', $payload);
    $mock = king_object_store_s3_mock_start_server(
        null,
        '127.0.0.1',
        $case['mock_options']
    );

    $expectedSource = [
        'object_id' => $objectId,
        'content_type' => 'application/x-king-restore-' . $case['provider'],
        'content_encoding' => 'gzip',
        'etag' => 'restore-meta-etag-' . $case['provider'],
        'integrity_sha256' => $integrity,
        'content_length' => (string) strlen($payload),
        'version' => '9',
        'created_at' => '1700001001',
        'modified_at' => '1700002001',
        'expires_at' => '2100003601',
        'object_type' => '5',
        'cache_policy' => '4',
        'cache_ttl_seconds' => '321',
        'local_fs_present' => '1',
        'distributed_present' => '0',
        'cloud_s3_present' => '0',
        'cloud_gcs_present' => '0',
        'cloud_azure_present' => '0',
        'is_backed_up' => '0',
        'replication_status' => '2',
        'is_distributed' => '1',
        'distribution_peer_count' => '6',
    ];
    $expectedMigrated = $expectedSource;
    $expectedMigrated[$case['presence_key']] = '1';

    $localConfig = [
        'storage_root_path' => $root,
        'primary_backend' => 'local_fs',
        'backup_backend' => 'local_fs',
    ];
    $targetConfig = [
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

    king_object_store_482_cleanup_tree($root);
    mkdir($root, 0700, true);

    var_dump($backend);
    var_dump(king_object_store_init($localConfig));
    var_dump(king_object_store_put($objectId, $payload));
    king_object_store_482_write_meta($root . '/' . $objectId . '.meta', $expectedSource);
    var_dump(king_object_store_backup_all_objects($snapshotDir));
    var_dump(file_exists($snapshotDir . '/.king_snapshot_manifest'));

    var_dump(king_object_store_init($targetConfig));
    var_dump(king_object_store_restore_all_objects($snapshotDir));
    var_dump(king_object_store_get($objectId) === $payload);
    var_dump(
        king_object_store_482_sidecar_matches(
            king_object_store_482_parse_meta($root . '/' . $objectId . '.meta'),
            $expectedMigrated
        )
    );
    var_dump(
        king_object_store_482_public_matches(
            king_object_store_get_metadata($objectId),
            $expectedMigrated
        )
    );

    $capture = king_object_store_s3_mock_stop_server($mock);
    var_dump(count(array_filter(
        $capture['events'],
        static fn(array $event): bool =>
            $event['method'] === 'PUT'
            && ($event['object_id'] ?? null) === $objectId
    )) >= 1);

    king_object_store_482_cleanup_tree($root);
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
bool(true)
