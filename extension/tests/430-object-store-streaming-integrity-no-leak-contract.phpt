--TEST--
King object-store stream egress does not leak payload bytes before full-read integrity validation succeeds
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

$localDir = sys_get_temp_dir() . '/king_object_store_stream_integrity_local_430_' . getmypid();
if (!is_dir($localDir)) {
    mkdir($localDir, 0700, true);
}

$localSource = fopen('php://temp', 'w+');
fwrite($localSource, 'alpha');
rewind($localSource);

var_dump(king_object_store_init([
    'storage_root_path' => $localDir,
    'primary_backend' => 'local_fs',
]));
var_dump(king_object_store_put_from_stream('doc-local', $localSource));
file_put_contents($localDir . '/doc-local', 'omega');

$localDestination = fopen('php://temp', 'w+');
try {
    king_object_store_get_to_stream('doc-local', $localDestination);
    echo "no-local-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'integrity validation failed'));
}
rewind($localDestination);
var_dump(stream_get_contents($localDestination) === '');

$cloudRoot = sys_get_temp_dir() . '/king_object_store_stream_integrity_cloud_430_' . getmypid();
if (!is_dir($cloudRoot)) {
    mkdir($cloudRoot, 0700, true);
}

$mock = king_object_store_s3_mock_start_server();

var_dump(king_object_store_init([
    'storage_root_path' => $cloudRoot,
    'primary_backend' => 'cloud_s3',
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'stream-integrity-test',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
]));

$cloudSource = fopen('php://temp', 'w+');
fwrite($cloudSource, 'alpha');
rewind($cloudSource);

var_dump(king_object_store_put_from_stream('doc-cloud', $cloudSource));
file_put_contents(
    $mock['state_directory'] . '/objects/' . rawurlencode('doc-cloud'),
    'omega'
);

$cloudDestination = fopen('php://temp', 'w+');
try {
    king_object_store_get_to_stream('doc-cloud', $cloudDestination);
    echo "no-cloud-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'integrity validation failed'));
}
rewind($cloudDestination);
var_dump(stream_get_contents($cloudDestination) === '');

$capture = king_object_store_s3_mock_stop_server($mock);
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
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
bool(true)
