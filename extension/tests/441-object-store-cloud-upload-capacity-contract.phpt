--TEST--
King object-store cloud upload sessions reject chunks that would exceed runtime capacity
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

function king_object_store_441_cleanup_dir(string $dir): void
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
            king_object_store_441_cleanup_dir($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

function king_object_store_441_stream(string $payload)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $payload);
    rewind($stream);
    return $stream;
}

$root = sys_get_temp_dir() . '/king_object_store_cloud_upload_capacity_441_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}

$mock = king_object_store_s3_mock_start_server();
$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_s3',
    'max_storage_size_bytes' => 5,
    'chunk_size_kb' => 1,
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'capacity-upload-s3-test',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
];

var_dump(king_object_store_init($config));
$started = king_object_store_begin_resumable_upload('quota-upload');
var_dump($started['backend']);
var_dump($started['protocol']);

$afterChunkOne = king_object_store_append_resumable_upload_chunk(
    $started['upload_id'],
    king_object_store_441_stream('abc')
);
var_dump($afterChunkOne['uploaded_bytes']);
var_dump($afterChunkOne['completed']);

try {
    king_object_store_append_resumable_upload_chunk(
        $started['upload_id'],
        king_object_store_441_stream('def'),
        ['final' => true]
    );
} catch (King\ValidationException $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

$status = king_object_store_get_resumable_upload_status($started['upload_id']);
var_dump($status['uploaded_bytes']);
var_dump($status['completed']);
var_dump($status['final_chunk_received']);

var_dump(king_object_store_abort_resumable_upload($started['upload_id']));
var_dump(king_object_store_get_resumable_upload_status($started['upload_id']));

$capture = king_object_store_s3_mock_stop_server($mock);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'POST'
        && $event['target'] === '/capacity-upload-s3-test/quota-upload?uploads'
)) === 1);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'PUT'
        && str_starts_with($event['target'], '/capacity-upload-s3-test/quota-upload?partNumber=1&uploadId=')
)) === 1);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'PUT'
        && str_starts_with($event['target'], '/capacity-upload-s3-test/quota-upload?partNumber=2&uploadId=')
)) === 0);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'POST'
        && str_starts_with($event['target'], '/capacity-upload-s3-test/quota-upload?uploadId=')
)) === 0);

king_object_store_441_cleanup_dir($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
string(8) "cloud_s3"
string(12) "s3_multipart"
int(3)
bool(false)
string(24) "King\ValidationException"
string(94) "Resumable upload for 'quota-upload' would exceed the configured object-store runtime capacity."
int(3)
bool(false)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
