--TEST--
King object-store full reads enforce stored integrity on local_fs and real cloud backends
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

$localDir = sys_get_temp_dir() . '/king_object_store_integrity_local_426_' . getmypid();
if (!is_dir($localDir)) {
    mkdir($localDir, 0700, true);
}

var_dump(king_object_store_init([
    'storage_root_path' => $localDir,
    'primary_backend' => 'local_fs',
]));
var_dump(king_object_store_put('doc-local', 'alpha'));
file_put_contents($localDir . '/doc-local', 'omega');
try {
    king_object_store_get('doc-local');
    echo "no-local-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'integrity validation failed'));
}

$cloudRoot = sys_get_temp_dir() . '/king_object_store_integrity_cloud_426_' . getmypid();
if (!is_dir($cloudRoot)) {
    mkdir($cloudRoot, 0700, true);
}
$mock = king_object_store_s3_mock_start_server();

var_dump(king_object_store_init([
    'storage_root_path' => $cloudRoot,
    'primary_backend' => 'cloud_s3',
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'integrity-test',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
]));
var_dump(king_object_store_put('doc-cloud', 'alpha'));
file_put_contents(
    $mock['state_directory'] . '/objects/' . rawurlencode('doc-cloud'),
    'omega'
);
try {
    king_object_store_get('doc-cloud');
    echo "no-cloud-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'integrity validation failed'));
}

$capture = king_object_store_s3_mock_stop_server($mock);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool => $event['method'] === 'HEAD' && $event['object_id'] === 'doc-cloud'
)) === 1);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool => $event['method'] === 'GET' && $event['object_id'] === 'doc-cloud'
)) >= 1);

foreach (scandir($localDir) as $entry) {
    if ($entry !== '.' && $entry !== '..') {
        @unlink($localDir . '/' . $entry);
    }
}
@rmdir($localDir);

foreach (scandir($cloudRoot) as $entry) {
    if ($entry !== '.' && $entry !== '..') {
        @unlink($cloudRoot . '/' . $entry);
    }
}
@rmdir($cloudRoot);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
bool(true)
