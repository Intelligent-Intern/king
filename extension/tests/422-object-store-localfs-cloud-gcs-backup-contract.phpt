--TEST--
King object-store local_fs primary can back up to the real cloud_gcs backend
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

$root = sys_get_temp_dir() . '/king_object_store_local_gcs_backup_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}
$mock = king_object_store_s3_mock_start_server(
    null,
    '127.0.0.1',
    [
        'provider' => 'gcs',
        'expected_access_token' => 'gcs-token',
    ]
);

$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'cloud_gcs',
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'backup-gcs-test',
        'access_token' => 'gcs-token',
        'path_style' => true,
        'verify_tls' => false,
    ],
];

var_dump(king_object_store_init($config));
var_dump(king_object_store_put('doc-backup-gcs', 'alpha'));
var_dump(king_object_store_get('doc-backup-gcs'));
$meta = king_object_store_get_metadata('doc-backup-gcs');
var_dump($meta['is_backed_up']);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_backend_contract']);
var_dump($stats['runtime_backup_adapter_contract']);
var_dump($stats['runtime_primary_adapter_status']);
var_dump($stats['runtime_backup_adapter_status']);

var_dump(king_object_store_delete('doc-backup-gcs'));
var_dump(king_object_store_get('doc-backup-gcs'));

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(in_array('PUT /backup-gcs-test/doc-backup-gcs', $targets, true));
var_dump(in_array('DELETE /backup-gcs-test/doc-backup-gcs', $targets, true));
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool => ($event['authorization_bearer_token'] ?? '') === 'gcs-token'
)) >= 2);

foreach (scandir($root) as $file) {
    if ($file !== '.' && $file !== '..') {
        @unlink($root . '/' . $file);
    }
}
@rmdir($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
bool(true)
string(5) "alpha"
int(1)
string(5) "local"
string(5) "cloud"
string(2) "ok"
string(2) "ok"
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
