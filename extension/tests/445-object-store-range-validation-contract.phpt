--TEST--
King object-store unsatisfiable byte ranges map to validation failures across local and real cloud backends
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

function king_object_store_445_cleanup_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        @unlink($dir . '/' . $entry);
    }

    @rmdir($dir);
}

function king_object_store_445_assert_unsatisfiable_range(string $backend, array $config, ?array $mock = null): array
{
    $root = $config['storage_root_path'];
    $result = [];

    king_object_store_445_cleanup_dir($root);
    mkdir($root, 0700, true);

    $result['init'] = king_object_store_init($config);
    $result['put'] = king_object_store_put('doc', 'alpha');

    try {
        king_object_store_get('doc', ['offset' => 99]);
        $result['get_class'] = 'no-exception';
        $result['get_contains'] = false;
    } catch (Throwable $e) {
        $result['get_class'] = get_class($e);
        $result['get_contains'] = str_contains($e->getMessage(), 'range starts past the end');
    }

    $stream = fopen('php://temp', 'w+');
    try {
        king_object_store_get_to_stream('doc', $stream, ['offset' => 99]);
        $result['stream_class'] = 'no-exception';
        $result['stream_contains'] = false;
    } catch (Throwable $e) {
        $result['stream_class'] = get_class($e);
        $result['stream_contains'] = str_contains($e->getMessage(), 'range starts past the end');
    }
    rewind($stream);
    $result['stream_empty'] = stream_get_contents($stream) === '';
    fclose($stream);

    if ($mock !== null) {
        $capture = king_object_store_s3_mock_stop_server($mock);
        $result['saw_416'] = count(array_filter(
            $capture['events'],
            static fn(array $event): bool => ($event['response_status'] ?? 0) === 416
        )) >= 2;
        king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
    } else {
        $result['saw_416'] = true;
    }

    king_object_store_445_cleanup_dir($root);
    return $result;
}

$cases = [];

$cases['local_fs'] = king_object_store_445_assert_unsatisfiable_range('local_fs', [
    'storage_root_path' => sys_get_temp_dir() . '/king_object_store_range_445_local_' . getmypid(),
    'primary_backend' => 'local_fs',
]);

$s3Mock = king_object_store_s3_mock_start_server(null, '127.0.0.1');
$cases['cloud_s3'] = king_object_store_445_assert_unsatisfiable_range(
    'cloud_s3',
    [
        'storage_root_path' => sys_get_temp_dir() . '/king_object_store_range_445_s3_' . getmypid(),
        'primary_backend' => 'cloud_s3',
        'cloud_credentials' => [
            'api_endpoint' => $s3Mock['endpoint'],
            'bucket' => 'range-s3',
            'access_key' => 'access',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
            'path_style' => true,
            'verify_tls' => false,
        ],
    ],
    $s3Mock
);

$gcsMock = king_object_store_s3_mock_start_server(null, '127.0.0.1', [
    'provider' => 'gcs',
    'expected_access_token' => 'gcs-token',
]);
$cases['cloud_gcs'] = king_object_store_445_assert_unsatisfiable_range(
    'cloud_gcs',
    [
        'storage_root_path' => sys_get_temp_dir() . '/king_object_store_range_445_gcs_' . getmypid(),
        'primary_backend' => 'cloud_gcs',
        'cloud_credentials' => [
            'api_endpoint' => $gcsMock['endpoint'],
            'bucket' => 'range-gcs',
            'access_token' => 'gcs-token',
            'path_style' => true,
            'verify_tls' => false,
        ],
    ],
    $gcsMock
);

$azureMock = king_object_store_s3_mock_start_server(null, '127.0.0.1', [
    'provider' => 'azure',
    'expected_access_token' => 'azure-token',
]);
$cases['cloud_azure'] = king_object_store_445_assert_unsatisfiable_range(
    'cloud_azure',
    [
        'storage_root_path' => sys_get_temp_dir() . '/king_object_store_range_445_azure_' . getmypid(),
        'primary_backend' => 'cloud_azure',
        'cloud_credentials' => [
            'api_endpoint' => $azureMock['endpoint'],
            'container' => 'range-azure',
            'access_token' => 'azure-token',
            'verify_tls' => false,
        ],
    ],
    $azureMock
);

foreach (['local_fs', 'cloud_s3', 'cloud_gcs', 'cloud_azure'] as $backend) {
    var_dump($backend);
    var_dump($cases[$backend]['init']);
    var_dump($cases[$backend]['put']);
    var_dump($cases[$backend]['get_class']);
    var_dump($cases[$backend]['get_contains']);
    var_dump($cases[$backend]['stream_class']);
    var_dump($cases[$backend]['stream_contains']);
    var_dump($cases[$backend]['stream_empty']);
    var_dump($cases[$backend]['saw_416']);
}
?>
--EXPECT--
string(8) "local_fs"
bool(true)
bool(true)
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
bool(true)
bool(true)
string(8) "cloud_s3"
bool(true)
bool(true)
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
bool(true)
bool(true)
string(9) "cloud_gcs"
bool(true)
bool(true)
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
bool(true)
bool(true)
string(11) "cloud_azure"
bool(true)
bool(true)
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
bool(true)
bool(true)
