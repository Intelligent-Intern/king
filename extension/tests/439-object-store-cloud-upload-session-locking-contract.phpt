--TEST--
King object-store cloud upload sessions hold the object mutation lock until abort or completion
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

function king_object_store_439_cleanup_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            king_object_store_439_cleanup_dir($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

$root = sys_get_temp_dir() . '/king_object_store_locking_439_' . getmypid();
@mkdir($root, 0700, true);

$mock = king_object_store_s3_mock_start_server();

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_s3',
    'chunk_size_kb' => 1,
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'locking-s3-test',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
]));

$started = king_object_store_begin_resumable_upload('locked-cloud', [
    'content_type' => 'application/octet-stream',
]);
var_dump($started['backend']);
var_dump($started['completed']);

try {
    king_object_store_begin_resumable_upload('locked-cloud');
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'active mutation'));
}

try {
    king_object_store_put('locked-cloud', 'beta');
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'active mutation'));
}

var_dump(king_object_store_delete('locked-cloud'));

$status = king_object_store_get_resumable_upload_status($started['upload_id']);
var_dump($status['aborted']);
var_dump(king_object_store_abort_resumable_upload($started['upload_id']));
var_dump(king_object_store_put('locked-cloud', 'beta'));
var_dump(king_object_store_get('locked-cloud'));

$capture = king_object_store_s3_mock_stop_server($mock);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'POST'
        && $event['target'] === '/locking-s3-test/locked-cloud?uploads'
)) === 1);

king_object_store_439_cleanup_dir($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
string(8) "cloud_s3"
bool(false)
string(21) "King\RuntimeException"
bool(true)
string(21) "King\RuntimeException"
bool(true)
bool(false)
bool(false)
bool(true)
bool(true)
string(4) "beta"
bool(true)
