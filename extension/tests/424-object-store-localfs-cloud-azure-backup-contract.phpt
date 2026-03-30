--TEST--
King object-store local_fs primary can back up to the real cloud_azure backend
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

$root = sys_get_temp_dir() . '/king_object_store_local_azure_backup_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}
$mock = king_object_store_s3_mock_start_server(
    null,
    '127.0.0.1',
    [
        'provider' => 'azure',
        'expected_access_token' => 'azure-token',
    ]
);

$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'cloud_azure',
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'container' => 'backup-azure-test',
        'access_token' => 'azure-token',
        'verify_tls' => false,
    ],
];

var_dump(king_object_store_init($config));
var_dump(king_object_store_put('doc-backup-azure', 'alpha'));
var_dump(king_object_store_get('doc-backup-azure'));
$meta = king_object_store_get_metadata('doc-backup-azure');
var_dump($meta['is_backed_up']);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_backend_contract']);
var_dump($stats['runtime_backup_adapter_contract']);
var_dump($stats['runtime_primary_adapter_status']);
var_dump($stats['runtime_backup_adapter_status']);

var_dump(king_object_store_delete('doc-backup-azure'));
var_dump(king_object_store_get('doc-backup-azure'));

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(in_array('PUT /backup-azure-test/doc-backup-azure', $targets, true));
var_dump(in_array('DELETE /backup-azure-test/doc-backup-azure', $targets, true));
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool => ($event['authorization_bearer_token'] ?? '') === 'azure-token'
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
