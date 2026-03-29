--TEST--
King object-store future cloud backends do not synthesize throttling semantics before real network support exists
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

$root = sys_get_temp_dir() . '/king_object_store_future_cloud_throttle_' . getmypid();
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

$cleanupTree($root);
mkdir($root, 0700, true);

$mock = king_object_store_s3_mock_start_server(
    null,
    '127.0.0.1',
    [
        'forced_responses' => [
            [
                'status' => 429,
                'code' => 'TooManyRequests',
                'message' => 'the configured future cloud endpoint would throttle',
            ],
            [
                'status' => 503,
                'code' => 'SlowDown',
                'message' => 'the configured future cloud endpoint would slow down',
            ],
        ],
    ]
);

$cloudCredentials = [
    'api_endpoint' => $mock['endpoint'],
    'bucket' => 'future-cloud-throttle',
    'access_key' => 'access',
    'secret_key' => 'secret',
    'region' => 'us-east-1',
    'path_style' => true,
    'verify_tls' => false,
];

foreach (['cloud_gcs', 'cloud_azure'] as $backend) {
    var_dump(king_object_store_init([
        'storage_root_path' => $root,
        'primary_backend' => $backend,
        'cloud_credentials' => $cloudCredentials,
    ]));

    try {
        king_object_store_put('primary-' . $backend, 'payload');
        echo "no-exception\n";
    } catch (Throwable $e) {
        var_dump($e instanceof King\SystemException);
        var_dump(!str_contains(strtolower($e->getMessage()), 'throttl'));
        var_dump(!str_contains(strtolower($e->getMessage()), 'slowdown'));
        var_dump(str_contains(
            $e->getMessage(),
            "simulated backend '" . $backend . "' is simulated-only and unavailable for put operations."
        ));
    }

    $stats = king_object_store_get_stats()['object_store'];
    var_dump($stats['runtime_primary_adapter_status'] === 'failed');
    var_dump(!str_contains(strtolower($stats['runtime_primary_adapter_error']), 'throttl'));
    var_dump(!str_contains(strtolower($stats['runtime_primary_adapter_error']), 'slowdown'));
    var_dump(str_contains(
        $stats['runtime_primary_adapter_error'],
        "simulated backend '" . $backend . "' is simulated-only and unavailable for put operations."
    ));

    $list = king_object_store_list();
    var_dump(is_array($list) && count($list) === 0);
    $stats = king_object_store_get_stats()['object_store'];
    var_dump($stats['runtime_primary_adapter_status'] === 'failed');
    var_dump(!str_contains(strtolower($stats['runtime_primary_adapter_error']), 'throttl'));
    var_dump(!str_contains(strtolower($stats['runtime_primary_adapter_error']), 'slowdown'));
    var_dump(str_contains(
        $stats['runtime_primary_adapter_error'],
        "simulated backend '" . $backend . "' is simulated-only and unavailable for list operations."
    ));
}

foreach (['cloud_gcs', 'cloud_azure'] as $backend) {
    var_dump(king_object_store_init([
        'storage_root_path' => $root,
        'primary_backend' => 'local_fs',
        'backup_backend' => $backend,
        'cloud_credentials' => $cloudCredentials,
    ]));

    try {
        king_object_store_put('backup-' . $backend, 'payload');
        echo "no-exception\n";
    } catch (Throwable $e) {
        var_dump($e instanceof King\SystemException);
        var_dump(str_contains($e->getMessage(), 'backup operation failed'));
        var_dump(!str_contains(strtolower($e->getMessage()), 'throttl'));
        var_dump(!str_contains(strtolower($e->getMessage()), 'slowdown'));
    }

    $stats = king_object_store_get_stats()['object_store'];
    var_dump($stats['runtime_primary_adapter_status'] === 'failed');
    var_dump(str_contains($stats['runtime_primary_adapter_error'], 'backup operation failed'));
    var_dump(!str_contains(strtolower($stats['runtime_primary_adapter_error']), 'throttl'));
    var_dump($stats['runtime_backup_adapter_status'] === 'failed');
    var_dump(!str_contains(strtolower($stats['runtime_backup_adapter_error']), 'throttl'));
    var_dump(!str_contains(strtolower($stats['runtime_backup_adapter_error']), 'slowdown'));
    var_dump(str_contains(
        $stats['runtime_backup_adapter_error'],
        "simulated backend '" . $backend . "' is simulated-only and unavailable for object backup."
    ));
    var_dump(king_object_store_get('backup-' . $backend) === 'payload');
}

$capture = king_object_store_s3_mock_stop_server($mock);
var_dump(count($capture['events']) === 0);

$cleanupTree($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
