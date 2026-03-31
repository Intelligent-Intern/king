--TEST--
King object-store delete semantics stay consistent across real cloud backends
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

$cleanupTree = static function (string $path) use (&$cleanupTree): void {
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
                $cleanupTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
};

$providers = [
    's3' => [
        'backend' => 'cloud_s3',
        'container_key' => 'bucket',
        'container_name' => 'delete-consistency-s3',
        'mock_options' => [
            'provider' => 's3',
            'expected_access_key' => 'access',
        ],
        'credentials' => [
            'access_key' => 'access',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
            'path_style' => true,
            'verify_tls' => false,
        ],
    ],
    'gcs' => [
        'backend' => 'cloud_gcs',
        'container_key' => 'bucket',
        'container_name' => 'delete-consistency-gcs',
        'mock_options' => [
            'provider' => 'gcs',
            'expected_access_token' => 'gcs-token',
        ],
        'credentials' => [
            'access_token' => 'gcs-token',
            'path_style' => true,
            'verify_tls' => false,
        ],
    ],
    'azure' => [
        'backend' => 'cloud_azure',
        'container_key' => 'container',
        'container_name' => 'delete-consistency-azure',
        'mock_options' => [
            'provider' => 'azure',
            'expected_access_token' => 'azure-token',
        ],
        'credentials' => [
            'access_token' => 'azure-token',
            'verify_tls' => false,
        ],
    ],
];

$results = [];

foreach ($providers as $provider => $spec) {
    $root = sys_get_temp_dir() . '/king_object_store_delete_consistency_' . $provider . '_' . getmypid();
    $cleanupTree($root);
    mkdir($root, 0700, true);

    $forcedErrorCode = $provider === 'azure' ? 'ServerBusy' : 'SlowDown';
    $forcedErrorMessage = $provider === 'azure'
        ? 'Reduce your request rate.'
        : 'delete throttled';

    $forcedResponses = [
        [
            'method' => 'DELETE',
            'target' => '/' . $spec['container_name'] . '/doc-cloud-fail',
            'times' => 1,
            'status' => 503,
            'error_code' => $forcedErrorCode,
            'error_message' => $forcedErrorMessage,
        ],
        [
            'method' => 'DELETE',
            'target' => '/' . $spec['container_name'] . '/doc-backup-fail',
            'times' => 1,
            'status' => 503,
            'error_code' => $forcedErrorCode,
            'error_message' => $forcedErrorMessage,
        ],
    ];

    $mock = king_object_store_s3_mock_start_server(
        null,
        '127.0.0.1',
        $spec['mock_options'] + ['forced_responses' => $forcedResponses]
    );

    $credentials = $spec['credentials'];
    $credentials['api_endpoint'] = $mock['endpoint'];
    $credentials[$spec['container_key']] = $spec['container_name'];

    $localConfig = [
        'storage_root_path' => $root,
        'primary_backend' => 'local_fs',
        'backup_backend' => $spec['backend'],
        'replication_factor' => 2,
        'cloud_credentials' => $credentials,
    ];

    $cloudConfig = [
        'storage_root_path' => $root,
        'primary_backend' => $spec['backend'],
        'replication_factor' => 1,
        'cloud_credentials' => $credentials,
    ];

    $payloadPath = $root . '/doc-missing-local';
    $metaPath = $payloadPath . '.meta';

    $result = [];

    $result['cloud_missing_delete'] = (king_object_store_init($cloudConfig) === true)
        && (king_object_store_delete('doc-cloud-miss') === false);

    $result['cloud_fail_put'] = king_object_store_put('doc-cloud-fail', 'delta');
    try {
        king_object_store_delete('doc-cloud-fail');
        $result['cloud_fail_exception_class'] = 'none';
        $result['cloud_fail_message_contains_delete'] = false;
    } catch (Throwable $e) {
        $result['cloud_fail_exception_class'] = get_class($e);
        $result['cloud_fail_message_contains_delete'] = str_contains($e->getMessage(), 'delete');
    }
    $result['cloud_fail_object_still_readable'] = king_object_store_get('doc-cloud-fail');
    $result['cloud_fail_final_delete'] = king_object_store_delete('doc-cloud-fail');
    $result['cloud_fail_final_get'] = king_object_store_get('doc-cloud-fail');

    $result['local_ok_init'] = king_object_store_init($localConfig);
    $result['local_ok_put'] = king_object_store_put('doc-ok', 'alpha');
    $result['local_ok_delete'] = king_object_store_delete('doc-ok');
    $result['local_ok_get_after'] = king_object_store_get('doc-ok');
    $result['cloud_ok_get_after'] = king_object_store_init($cloudConfig)
        ? king_object_store_get('doc-ok')
        : 'init-failed';
    $result['cloud_ok_list_count'] = count(king_object_store_list());

    $result['local_missing_init'] = king_object_store_init($localConfig);
    $result['local_missing_put'] = king_object_store_put('doc-missing-local', 'bravo');
    clearstatcache(false, $payloadPath);
    clearstatcache(false, $metaPath);
    $result['local_missing_payload_exists_before'] = is_file($payloadPath);
    $result['local_missing_meta_exists_before'] = is_file($metaPath);
    $result['local_missing_unlink_payload'] = @unlink($payloadPath);
    clearstatcache(false, $payloadPath);
    $result['local_missing_payload_exists_after_unlink'] = is_file($payloadPath);
    $result['local_missing_delete'] = king_object_store_delete('doc-missing-local');
    $result['local_missing_get_after'] = king_object_store_get('doc-missing-local');
    clearstatcache(false, $metaPath);
    $result['local_missing_meta_exists_after_delete'] = is_file($metaPath);
    $result['cloud_missing_get_after'] = king_object_store_init($cloudConfig)
        ? king_object_store_get('doc-missing-local')
        : 'init-failed';
    $result['cloud_missing_list_count'] = count(king_object_store_list());

    $result['local_fail_init'] = king_object_store_init($localConfig);
    $result['local_fail_put'] = king_object_store_put('doc-backup-fail', 'charlie');
    try {
        king_object_store_delete('doc-backup-fail');
        $result['local_fail_exception_class'] = 'none';
        $result['local_fail_message_contains_backup'] = false;
    } catch (Throwable $e) {
        $result['local_fail_exception_class'] = get_class($e);
        $result['local_fail_message_contains_backup'] = str_contains($e->getMessage(), 'backup removal failed');
    }
    $stats = king_object_store_get_stats()['object_store'];
    $meta = king_object_store_get_metadata('doc-backup-fail');
    $result['local_fail_primary_status'] = $stats['runtime_primary_adapter_status'];
    $result['local_fail_primary_error_mentions_backup'] = str_contains($stats['runtime_primary_adapter_error'], 'backup removal failed');
    $result['local_fail_backup_status'] = $stats['runtime_backup_adapter_status'];
    $result['local_fail_backup_error_mentions_throttled'] = str_contains($stats['runtime_backup_adapter_error'], 'throttled');
    $result['local_fail_object_still_readable'] = king_object_store_get('doc-backup-fail');
    $result['local_fail_is_backed_up'] = $meta['is_backed_up'];
    $result['local_fail_replication_status'] = $meta['replication_status'];
    $result['cloud_fail_get_after_local_failure'] = king_object_store_init($cloudConfig)
        ? king_object_store_get('doc-backup-fail')
        : 'init-failed';
    $result['cloud_fail_list_count_after_local_failure'] = count(king_object_store_list());

    $result['local_fail_final_init'] = king_object_store_init($localConfig);
    $result['local_fail_final_delete'] = king_object_store_delete('doc-backup-fail');
    $result['local_fail_final_get'] = king_object_store_get('doc-backup-fail');
    $result['cloud_fail_final_get'] = king_object_store_init($cloudConfig)
        ? king_object_store_get('doc-backup-fail')
        : 'init-failed';
    $result['cloud_fail_final_list_count'] = count(king_object_store_list());

    $capture = king_object_store_s3_mock_stop_server($mock);
    $targets = array_map(
        static fn(array $event): string => $event['method'] . ' ' . $event['target'],
        $capture['events']
    );
    $result['delete_doc_ok_count'] = count(array_filter(
        $targets,
        static fn(string $target): bool => $target === 'DELETE /' . $spec['container_name'] . '/doc-ok'
    ));
    $result['delete_doc_missing_local_count'] = count(array_filter(
        $targets,
        static fn(string $target): bool => $target === 'DELETE /' . $spec['container_name'] . '/doc-missing-local'
    ));
    $result['delete_doc_backup_fail_count'] = count(array_filter(
        $targets,
        static fn(string $target): bool => $target === 'DELETE /' . $spec['container_name'] . '/doc-backup-fail'
    ));
    $result['delete_doc_cloud_fail_count'] = count(array_filter(
        $targets,
        static fn(string $target): bool => $target === 'DELETE /' . $spec['container_name'] . '/doc-cloud-fail'
    ));

    $results[$provider] = $result;

    $cleanupTree($root);
    king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
}

var_export($results);
?>
--EXPECTF--
array (
  's3' =>
  array (
    'cloud_missing_delete' => true,
    'cloud_fail_put' => true,
    'cloud_fail_exception_class' => 'King\\SystemException',
    'cloud_fail_message_contains_delete' => true,
    'cloud_fail_object_still_readable' => 'delta',
    'cloud_fail_final_delete' => true,
    'cloud_fail_final_get' => false,
    'local_ok_init' => true,
    'local_ok_put' => true,
    'local_ok_delete' => true,
    'local_ok_get_after' => false,
    'cloud_ok_get_after' => false,
    'cloud_ok_list_count' => 0,
    'local_missing_init' => true,
    'local_missing_put' => true,
    'local_missing_payload_exists_before' => true,
    'local_missing_meta_exists_before' => true,
    'local_missing_unlink_payload' => true,
    'local_missing_payload_exists_after_unlink' => false,
    'local_missing_delete' => true,
    'local_missing_get_after' => false,
    'local_missing_meta_exists_after_delete' => false,
    'cloud_missing_get_after' => false,
    'cloud_missing_list_count' => 0,
    'local_fail_init' => true,
    'local_fail_put' => true,
    'local_fail_exception_class' => 'King\\SystemException',
    'local_fail_message_contains_backup' => true,
    'local_fail_primary_status' => 'failed',
    'local_fail_primary_error_mentions_backup' => true,
    'local_fail_backup_status' => 'failed',
    'local_fail_backup_error_mentions_throttled' => true,
    'local_fail_object_still_readable' => 'charlie',
    'local_fail_is_backed_up' => 1,
    'local_fail_replication_status' => 2,
    'cloud_fail_get_after_local_failure' => 'charlie',
    'cloud_fail_list_count_after_local_failure' => 1,
    'local_fail_final_init' => true,
    'local_fail_final_delete' => true,
    'local_fail_final_get' => false,
    'cloud_fail_final_list_count' => 0,
    'delete_doc_ok_count' => 1,
    'delete_doc_missing_local_count' => 1,
    'delete_doc_backup_fail_count' => 2,
    'delete_doc_cloud_fail_count' => 2,
  ),
  'gcs' =>
  array (
    'cloud_missing_delete' => true,
    'cloud_fail_put' => true,
    'cloud_fail_exception_class' => 'King\\SystemException',
    'cloud_fail_message_contains_delete' => true,
    'cloud_fail_object_still_readable' => 'delta',
    'cloud_fail_final_delete' => true,
    'cloud_fail_final_get' => false,
    'local_ok_init' => true,
    'local_ok_put' => true,
    'local_ok_delete' => true,
    'local_ok_get_after' => false,
    'cloud_ok_get_after' => false,
    'cloud_ok_list_count' => 0,
    'local_missing_init' => true,
    'local_missing_put' => true,
    'local_missing_payload_exists_before' => true,
    'local_missing_meta_exists_before' => true,
    'local_missing_unlink_payload' => true,
    'local_missing_payload_exists_after_unlink' => false,
    'local_missing_delete' => true,
    'local_missing_get_after' => false,
    'local_missing_meta_exists_after_delete' => false,
    'cloud_missing_get_after' => false,
    'cloud_missing_list_count' => 0,
    'local_fail_init' => true,
    'local_fail_put' => true,
    'local_fail_exception_class' => 'King\\SystemException',
    'local_fail_message_contains_backup' => true,
    'local_fail_primary_status' => 'failed',
    'local_fail_primary_error_mentions_backup' => true,
    'local_fail_backup_status' => 'failed',
    'local_fail_backup_error_mentions_throttled' => true,
    'local_fail_object_still_readable' => 'charlie',
    'local_fail_is_backed_up' => 1,
    'local_fail_replication_status' => 2,
    'cloud_fail_get_after_local_failure' => 'charlie',
    'cloud_fail_list_count_after_local_failure' => 1,
    'local_fail_final_init' => true,
    'local_fail_final_delete' => true,
    'local_fail_final_get' => false,
    'cloud_fail_final_list_count' => 0,
    'delete_doc_ok_count' => 1,
    'delete_doc_missing_local_count' => 1,
    'delete_doc_backup_fail_count' => 2,
    'delete_doc_cloud_fail_count' => 2,
  ),
  'azure' =>
  array (
    'cloud_missing_delete' => true,
    'cloud_fail_put' => true,
    'cloud_fail_exception_class' => 'King\\SystemException',
    'cloud_fail_message_contains_delete' => true,
    'cloud_fail_object_still_readable' => 'delta',
    'cloud_fail_final_delete' => true,
    'cloud_fail_final_get' => false,
    'local_ok_init' => true,
    'local_ok_put' => true,
    'local_ok_delete' => true,
    'local_ok_get_after' => false,
    'cloud_ok_get_after' => false,
    'cloud_ok_list_count' => 0,
    'local_missing_init' => true,
    'local_missing_put' => true,
    'local_missing_payload_exists_before' => true,
    'local_missing_meta_exists_before' => true,
    'local_missing_unlink_payload' => true,
    'local_missing_payload_exists_after_unlink' => false,
    'local_missing_delete' => true,
    'local_missing_get_after' => false,
    'local_missing_meta_exists_after_delete' => false,
    'cloud_missing_get_after' => false,
    'cloud_missing_list_count' => 0,
    'local_fail_init' => true,
    'local_fail_put' => true,
    'local_fail_exception_class' => 'King\\SystemException',
    'local_fail_message_contains_backup' => true,
    'local_fail_primary_status' => 'failed',
    'local_fail_primary_error_mentions_backup' => true,
    'local_fail_backup_status' => 'failed',
    'local_fail_backup_error_mentions_throttled' => true,
    'local_fail_object_still_readable' => 'charlie',
    'local_fail_is_backed_up' => 1,
    'local_fail_replication_status' => 2,
    'cloud_fail_get_after_local_failure' => 'charlie',
    'cloud_fail_list_count_after_local_failure' => 1,
    'local_fail_final_init' => true,
    'local_fail_final_delete' => true,
    'local_fail_final_get' => false,
    'cloud_fail_final_list_count' => 0,
    'delete_doc_ok_count' => 1,
    'delete_doc_missing_local_count' => 1,
    'delete_doc_backup_fail_count' => 2,
    'delete_doc_cloud_fail_count' => 2,
  ),
)
