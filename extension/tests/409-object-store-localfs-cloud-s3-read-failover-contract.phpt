--TEST--
King object-store local_fs primary reads can heal from a real cloud_s3 backup without resurrecting deleted objects
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

$root = sys_get_temp_dir() . '/king_object_store_read_failover_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}

$mock = king_object_store_s3_mock_start_server();
$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'cloud_s3',
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'read-failover',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
];

$payloadPath = $root . '/doc-s3';
$metaPath = $root . '/doc-s3.meta';

var_dump(king_object_store_init($config));
var_dump(king_object_store_put('doc-s3', 'alpha'));
clearstatcache(false, $payloadPath);
clearstatcache(false, $metaPath);
var_dump(is_file($payloadPath));
var_dump(is_file($metaPath));

var_dump(@unlink($payloadPath));
clearstatcache(false, $payloadPath);
var_dump(is_file($payloadPath));

var_dump(king_object_store_init($config));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

var_dump(king_object_store_get('doc-s3'));
clearstatcache(false, $payloadPath);
var_dump(is_file($payloadPath));
$meta = king_object_store_get_metadata('doc-s3');
var_dump($meta['object_id']);
var_dump($meta['content_length']);
var_dump($meta['is_backed_up']);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump($stats['runtime_backup_adapter_status']);
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

var_dump(king_object_store_delete('doc-s3'));
clearstatcache(false, $payloadPath);
clearstatcache(false, $metaPath);
var_dump(is_file($payloadPath));
var_dump(is_file($metaPath));
var_dump(king_object_store_get('doc-s3'));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);
var_dump($stats['runtime_backup_adapter_status']);

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(count(array_filter(
    $targets,
    static fn(string $target): bool => $target === 'PUT /read-failover/doc-s3'
)) === 1);
var_dump(count(array_filter(
    $targets,
    static fn(string $target): bool => $target === 'GET /read-failover/doc-s3'
)) === 1);

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
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
int(0)
int(0)
string(5) "alpha"
bool(true)
string(6) "doc-s3"
int(5)
int(1)
string(2) "ok"
string(2) "ok"
int(1)
int(5)
bool(true)
bool(false)
bool(false)
bool(false)
int(0)
int(0)
string(2) "ok"
bool(true)
bool(true)
