--TEST--
King object-store restart rehydration preserves restore contracts across all persistence modes in one matrix
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

function king_object_store_642_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_object_store_642_cleanup_tree(string $path): void
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
                king_object_store_642_cleanup_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_642_sorted_object_ids(): array
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
        'mode' => 'local',
        'presence_key' => 'local_fs_present',
        'expect_distributed_recovery' => false,
    ],
    [
        'backend' => 'memory_cache',
        'mode' => 'local',
        'presence_key' => 'local_fs_present',
        'expect_distributed_recovery' => false,
    ],
    [
        'backend' => 'distributed',
        'mode' => 'local',
        'presence_key' => 'distributed_present',
        'expect_distributed_recovery' => true,
    ],
    [
        'backend' => 'cloud_s3',
        'mode' => 'cloud',
        'presence_key' => 'cloud_s3_present',
        'provider' => 's3',
        'bucket_or_container_key' => 'bucket',
        'bucket_or_container_value' => 'matrix-s3-bucket',
        'credentials' => [
            'access_key' => 'access',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
            'path_style' => true,
            'verify_tls' => false,
        ],
        'mock_options' => [],
    ],
    [
        'backend' => 'cloud_gcs',
        'mode' => 'cloud',
        'presence_key' => 'cloud_gcs_present',
        'provider' => 'gcs',
        'bucket_or_container_key' => 'bucket',
        'bucket_or_container_value' => 'matrix-gcs-bucket',
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
    [
        'backend' => 'cloud_azure',
        'mode' => 'cloud',
        'presence_key' => 'cloud_azure_present',
        'provider' => 'azure',
        'bucket_or_container_key' => 'container',
        'bucket_or_container_value' => 'matrix-azure-container',
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

foreach ($cases as $case) {
    $backend = $case['backend'];
    $root = sys_get_temp_dir() . '/king_object_store_restart_matrix_642_' . $backend . '_' . getmypid();
    $exportDir = $root . '/exports/object';
    $snapshotDir = $root . '/snapshots/full';
    $config = [
        'storage_root_path' => $root,
        'primary_backend' => $backend,
        'backup_backend' => $backend,
    ];

    $mock = null;
    $mockStateDirectory = null;
    try {
        if (($case['mode'] ?? 'local') === 'cloud') {
            $mock = king_object_store_s3_mock_start_server(
                null,
                '127.0.0.1',
                $case['mock_options'] ?? []
            );
            $mockStateDirectory = $mock['state_directory'] ?? null;

            $config['cloud_credentials'] = array_merge(
                [
                    'api_endpoint' => $mock['endpoint'],
                ],
                [
                    $case['bucket_or_container_key'] => $case['bucket_or_container_value'],
                ],
                $case['credentials'] ?? []
            );
        }

        king_object_store_642_cleanup_tree($root);
        @mkdir($root, 0700, true);

        king_object_store_642_assert(king_object_store_init($config) === true, $backend . ' init failed.');
        king_object_store_642_assert(
            king_object_store_put('doc-export', $payloadExport) === true,
            $backend . ' failed to write export object.'
        );
        king_object_store_642_assert(
            king_object_store_put('doc-snapshot', $payloadSnapshot) === true,
            $backend . ' failed to write snapshot object.'
        );
        king_object_store_642_assert(
            king_object_store_backup_object('doc-export', $exportDir) === true,
            $backend . ' backup_object failed.'
        );
        king_object_store_642_assert(
            king_object_store_backup_all_objects($snapshotDir) === true,
            $backend . ' backup_all_objects failed.'
        );
        king_object_store_642_assert(
            king_object_store_delete('doc-export') === true,
            $backend . ' delete doc-export failed.'
        );
        king_object_store_642_assert(
            king_object_store_delete('doc-snapshot') === true,
            $backend . ' delete doc-snapshot failed.'
        );
        king_object_store_642_assert(
            king_object_store_restore_object('doc-export', $exportDir) === true,
            $backend . ' restore_object failed.'
        );
        king_object_store_642_assert(
            king_object_store_restore_all_objects($snapshotDir) === true,
            $backend . ' restore_all_objects failed.'
        );

        king_object_store_642_assert(
            king_object_store_init($config) === true,
            $backend . ' re-init failed after restore.'
        );

        $stats = king_object_store_get_stats()['object_store'] ?? [];
        king_object_store_642_assert(
            (int) ($stats['object_count'] ?? -1) === 2,
            $backend . ' object_count did not rehydrate to 2.'
        );
        king_object_store_642_assert(
            (int) ($stats['stored_bytes'] ?? -1) === $expectedStoredBytes,
            $backend . ' stored_bytes did not rehydrate correctly.'
        );
        if (($case['expect_distributed_recovery'] ?? false) === true) {
            king_object_store_642_assert(
                ($stats['runtime_distributed_coordinator_state_recovered'] ?? false) === true,
                $backend . ' distributed coordinator recovery flag was not true.'
            );
        }
        if (($case['mode'] ?? 'local') === 'cloud') {
            king_object_store_642_assert(
                ($stats['runtime_primary_adapter_status'] ?? '') === 'ok',
                $backend . ' cloud adapter status was not ok after restart.'
            );
        }

        king_object_store_642_assert(
            king_object_store_get('doc-export') === $payloadExport,
            $backend . ' export payload mismatch after restart.'
        );
        king_object_store_642_assert(
            king_object_store_get('doc-snapshot') === $payloadSnapshot,
            $backend . ' snapshot payload mismatch after restart.'
        );
        king_object_store_642_assert(
            king_object_store_642_sorted_object_ids() === ['doc-export', 'doc-snapshot'],
            $backend . ' object-id inventory mismatch after restart.'
        );

        $exportMeta = king_object_store_get_metadata('doc-export');
        $snapshotMeta = king_object_store_get_metadata('doc-snapshot');
        king_object_store_642_assert(
            is_array($exportMeta) && is_array($snapshotMeta),
            $backend . ' metadata lookup failed.'
        );
        king_object_store_642_assert(
            (int) ($exportMeta['content_length'] ?? -1) === strlen($payloadExport),
            $backend . ' export metadata content_length mismatch.'
        );
        king_object_store_642_assert(
            (int) ($snapshotMeta['content_length'] ?? -1) === strlen($payloadSnapshot),
            $backend . ' snapshot metadata content_length mismatch.'
        );
        $presenceKey = $case['presence_key'];
        king_object_store_642_assert(
            (int) ($exportMeta[$presenceKey] ?? 0) === 1 && (int) ($snapshotMeta[$presenceKey] ?? 0) === 1,
            $backend . ' persistence presence metadata mismatch.'
        );

        if ($mock !== null) {
            $capture = king_object_store_s3_mock_stop_server($mock);
            $mock = null;
            king_object_store_642_assert(
                count($capture['events'] ?? []) > 0,
                $backend . ' mock capture did not observe cloud events.'
            );
        }
    } finally {
        if ($mock !== null) {
            king_object_store_s3_mock_stop_server($mock);
        }

        king_object_store_642_cleanup_tree($root);
        if (is_string($mockStateDirectory) && $mockStateDirectory !== '') {
            king_object_store_s3_mock_cleanup_state_directory($mockStateDirectory);
        }
    }
}

echo "OK\n";
?>
--EXPECT--
OK
