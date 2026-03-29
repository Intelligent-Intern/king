--TEST--
King object-store cloud_azure surfaces endpoint connection failures through runtime status and write failures
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

$root = sys_get_temp_dir() . '/king_object_store_azure_network_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}

$port = king_object_store_s3_mock_reserve_unused_port();
$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_azure',
    'cloud_credentials' => [
        'api_endpoint' => 'http://127.0.0.1:' . $port,
        'container' => 'network-test-azure',
        'access_token' => 'azure-token',
        'verify_tls' => false,
    ],
];

var_dump(king_object_store_init($config));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_backend_contract']);
var_dump($stats['runtime_primary_adapter_status']);
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'could not connect to the configured endpoint'));

try {
    king_object_store_put('doc-azure', 'alpha');
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'could not connect to the configured endpoint'));
}

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'could not connect to the configured endpoint'));

$list = king_object_store_list();
var_dump(count($list));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'could not connect to the configured endpoint'));

var_dump(king_object_store_get('doc-azure'));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'could not connect to the configured endpoint'));

foreach (scandir($root) as $file) {
    if ($file !== '.' && $file !== '..') {
        @unlink($root . '/' . $file);
    }
}
@rmdir($root);
?>
--EXPECT--
bool(true)
string(5) "cloud"
string(6) "failed"
bool(true)
string(20) "King\SystemException"
bool(true)
string(6) "failed"
bool(true)
int(0)
string(6) "failed"
bool(true)
bool(false)
string(6) "failed"
bool(true)
