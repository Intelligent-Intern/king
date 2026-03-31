--TEST--
King object-store cloud_azure surfaces endpoint throttling through runtime status and write failures
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

$root = sys_get_temp_dir() . '/king_object_store_azure_throttle_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}
$mock = king_object_store_s3_mock_start_server(
    null,
    '127.0.0.1',
    [
        'provider' => 'azure',
        'expected_access_token' => 'azure-token',
        'forced_responses' => [
            [
                'method' => 'GET',
                'target' => '/throttle-test-azure?restype=container&comp=list',
                'status' => 429,
                'error_code' => 'TooManyRequests',
                'error_message' => 'Rate exceeded.',
                'headers' => ['Retry-After' => '2'],
            ],
            [
                'method' => 'PUT',
                'target' => '/throttle-test-azure/doc-azure',
                'status' => 503,
                'error_code' => 'ServerBusy',
                'error_message' => 'Reduce your request rate.',
            ],
        ],
    ]
);

$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_azure',
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'container' => 'throttle-test-azure',
        'access_token' => 'azure-token',
        'verify_tls' => false,
    ],
];

var_dump(king_object_store_init($config));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'was throttled by the configured endpoint'));
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'HTTP 429'));

try {
    king_object_store_put('doc-azure', 'alpha');
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), "cloud_azure write for 'doc-azure' was throttled"));
    var_dump(str_contains($e->getMessage(), 'HTTP 503'));
}

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump(str_contains($stats['runtime_primary_adapter_error'], "cloud_azure write for 'doc-azure' was throttled"));
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'HTTP 503'));

try {
    king_object_store_list();
    echo "no-list-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'cloud_azure list was throttled'));
    var_dump(str_contains($e->getMessage(), 'HTTP 429'));
}

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'cloud_azure list was throttled'));
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'HTTP 429'));

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(in_array('GET /throttle-test-azure?restype=container&comp=list', $targets, true));
var_dump(in_array('HEAD /throttle-test-azure/doc-azure', $targets, true));
var_dump(in_array('PUT /throttle-test-azure/doc-azure', $targets, true));
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool => ($event['forced_status'] ?? 0) === 429 && ($event['forced_error_code'] ?? '') === 'TooManyRequests'
)) >= 2);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool => ($event['forced_status'] ?? 0) === 503 && ($event['forced_error_code'] ?? '') === 'ServerBusy'
)) >= 1);

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
bool(true)
string(6) "failed"
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
string(6) "failed"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
