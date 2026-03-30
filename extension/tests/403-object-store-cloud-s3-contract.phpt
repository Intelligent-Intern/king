--TEST--
King object-store cloud_s3 primary backend uses a real S3-compatible HTTP runtime
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

$root = sys_get_temp_dir() . '/king_object_store_s3_primary_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}
$mock = king_object_store_s3_mock_start_server();

$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_s3',
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'primary-test',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
];

var_dump(king_object_store_init($config));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_backend_contract']);
var_dump($stats['runtime_primary_adapter_status']);

var_dump(king_object_store_put('doc-s3', 'alpha'));
var_dump(king_object_store_get('doc-s3'));
$meta = king_object_store_get_metadata('doc-s3');
var_dump($meta['object_id']);
var_dump($meta['content_length']);

$list = king_object_store_list();
var_dump(count($list));
var_dump($list[0]['object_id']);
var_dump($list[0]['size_bytes']);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

var_dump(king_object_store_init($config));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);
var_dump(king_object_store_get('doc-s3'));

var_dump(king_object_store_delete('doc-s3'));
var_dump(king_object_store_get('doc-s3'));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(in_array('GET /primary-test?list-type=2', $targets, true));
var_dump(in_array('PUT /primary-test/doc-s3', $targets, true));
var_dump(in_array('GET /primary-test/doc-s3', $targets, true));
var_dump(in_array('DELETE /primary-test/doc-s3', $targets, true));

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
string(5) "cloud"
string(2) "ok"
bool(true)
string(5) "alpha"
string(6) "doc-s3"
int(5)
int(1)
string(6) "doc-s3"
int(5)
int(1)
int(5)
bool(true)
int(1)
int(5)
string(5) "alpha"
bool(true)
bool(false)
int(0)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
