--TEST--
King object-store cloud_s3 exposes a real multipart upload session contract
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

function king_object_store_431_cleanup_dir(string $dir): void
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

function king_object_store_431_stream(string $payload)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $payload);
    rewind($stream);
    return $stream;
}

$root = sys_get_temp_dir() . '/king_object_store_s3_multipart_431_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}

$mock = king_object_store_s3_mock_start_server();
$payload = 'alpha-' . 'omega';
$payloadHash = hash('sha256', $payload);

$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_s3',
    'chunk_size_kb' => 1,
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'multipart-s3-test',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
];

var_dump(king_object_store_init($config));

$started = king_object_store_begin_resumable_upload('multipart-s3', [
    'content_type' => 'application/octet-stream',
    'object_type' => 'binary_data',
    'cache_policy' => 'etag',
    'integrity_sha256' => $payloadHash,
]);
var_dump($started['backend']);
var_dump($started['protocol']);
var_dump($started['uploaded_bytes']);
var_dump($started['chunk_size_bytes']);
var_dump($started['sequential_chunks_required']);
var_dump($started['final_chunk_may_be_shorter']);
var_dump($started['next_part_number']);
var_dump($started['uploaded_part_count']);

$status = king_object_store_get_resumable_upload_status($started['upload_id']);
var_dump($status['upload_id'] === $started['upload_id']);
var_dump($status['completed']);

$afterChunkOne = king_object_store_append_resumable_upload_chunk(
    $started['upload_id'],
    king_object_store_431_stream('alpha-')
);
var_dump($afterChunkOne['uploaded_bytes']);
var_dump($afterChunkOne['next_part_number']);
var_dump($afterChunkOne['uploaded_part_count']);
var_dump($afterChunkOne['final_chunk_received']);

$afterChunkTwo = king_object_store_append_resumable_upload_chunk(
    $started['upload_id'],
    king_object_store_431_stream('omega'),
    ['final' => true]
);
var_dump($afterChunkTwo['uploaded_bytes']);
var_dump($afterChunkTwo['final_chunk_received']);
var_dump($afterChunkTwo['remote_completed']);

$completed = king_object_store_complete_resumable_upload($started['upload_id']);
var_dump($completed['completed']);
var_dump($completed['remote_completed']);
var_dump(king_object_store_get_resumable_upload_status($started['upload_id']));
var_dump(king_object_store_get('multipart-s3'));

$metadata = king_object_store_get_metadata('multipart-s3');
var_dump($metadata['content_length']);
var_dump($metadata['integrity_sha256'] === $payloadHash);
var_dump($metadata['object_type_name']);
var_dump($metadata['cache_policy_name']);

$capture = king_object_store_s3_mock_stop_server($mock);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'POST'
        && $event['target'] === '/multipart-s3-test/multipart-s3?uploads'
)) === 1);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'PUT'
        && str_starts_with($event['target'], '/multipart-s3-test/multipart-s3?partNumber=1&uploadId=')
)) === 1);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'PUT'
        && str_starts_with($event['target'], '/multipart-s3-test/multipart-s3?partNumber=2&uploadId=')
)) === 1);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'POST'
        && str_starts_with($event['target'], '/multipart-s3-test/multipart-s3?uploadId=')
)) === 1);

king_object_store_431_cleanup_dir($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
string(8) "cloud_s3"
string(12) "s3_multipart"
int(0)
int(1024)
bool(true)
bool(true)
int(1)
int(0)
bool(true)
bool(false)
int(6)
int(2)
int(1)
bool(false)
int(11)
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
string(11) "alpha-omega"
int(11)
bool(true)
string(11) "binary_data"
string(4) "etag"
bool(true)
bool(true)
bool(true)
bool(true)
