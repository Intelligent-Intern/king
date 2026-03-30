--TEST--
King object-store cloud_s3 surfaces credential rejection through runtime status and write failures
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

$root = sys_get_temp_dir() . '/king_object_store_s3_auth_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}
$mock = king_object_store_s3_mock_start_server(
    null,
    '127.0.0.1',
    ['expected_access_key' => 'expected-access']
);

$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_s3',
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'credential-test',
        'access_key' => 'wrong-access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
];

var_dump(king_object_store_init($config));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'credentials were rejected'));
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'HTTP 403'));

try {
    king_object_store_put('doc-s3', 'alpha');
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'credentials were rejected'));
}

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'credentials were rejected'));

var_dump(king_object_store_get('doc-s3'));
$list = king_object_store_list();
var_dump(count($list));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'credentials were rejected'));

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(in_array('GET /credential-test?list-type=2', $targets, true));
var_dump(in_array('HEAD /credential-test/doc-s3', $targets, true));
var_dump(in_array('GET /credential-test/doc-s3', $targets, true));
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool => !empty($event['auth_rejected']) && ($event['received_access_key'] ?? '') === 'wrong-access'
)) >= 3);

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
string(6) "failed"
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
string(6) "failed"
bool(true)
bool(false)
int(0)
string(6) "failed"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
