--TEST--
King object-store runtime capacity is enforced consistently for real cloud primaries
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

function king_object_store_440_cleanup_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            king_object_store_440_cleanup_dir($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

$root = sys_get_temp_dir() . '/king_object_store_cloud_capacity_440_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}

$mock = king_object_store_s3_mock_start_server();
$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_s3',
    'max_storage_size_bytes' => 5,
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'capacity-s3-test',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
];

var_dump(king_object_store_init($config));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_capacity_mode']);
var_dump($stats['runtime_capacity_scope']);
var_dump($stats['runtime_capacity_enforced']);
var_dump($stats['runtime_capacity_available_bytes']);

var_dump(king_object_store_put('cloud-cap-a', 'four'));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);
var_dump($stats['runtime_capacity_available_bytes']);

try {
    king_object_store_put('cloud-cap-b', 'zz');
} catch (King\ValidationException $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

var_dump(king_object_store_init($config));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);
var_dump($stats['runtime_capacity_available_bytes']);
var_dump(king_object_store_get('cloud-cap-a'));

try {
    king_object_store_put('cloud-cap-b', 'zz');
} catch (King\ValidationException $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

$capture = king_object_store_s3_mock_stop_server($mock);
king_object_store_440_cleanup_dir($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
string(18) "logical_hard_limit"
string(33) "committed_primary_inventory_bytes"
bool(true)
int(5)
bool(true)
int(1)
int(4)
int(1)
string(24) "King\ValidationException"
string(82) "king_object_store_put() would exceed the configured object-store runtime capacity."
bool(true)
int(1)
int(4)
int(1)
string(4) "four"
string(24) "King\ValidationException"
string(82) "king_object_store_put() would exceed the configured object-store runtime capacity."
