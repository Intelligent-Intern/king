--TEST--
King object-store cloud_gcs exposes a real resumable upload session contract
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

function king_object_store_432_cleanup_dir(string $dir): void
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

function king_object_store_432_stream(string $payload)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $payload);
    rewind($stream);
    return $stream;
}

$root = sys_get_temp_dir() . '/king_object_store_gcs_resumable_432_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}

$mock = king_object_store_s3_mock_start_server(
    null,
    '127.0.0.1',
    [
        'provider' => 'gcs',
        'expected_access_token' => 'gcs-token',
    ]
);
$payload = 'chunk-one' . '++' . 'chunk-two';
$payloadHash = hash('sha256', $payload);

$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_gcs',
    'chunk_size_kb' => 1,
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'resumable-gcs-test',
        'access_token' => 'gcs-token',
        'path_style' => true,
        'verify_tls' => false,
    ],
];

var_dump(king_object_store_init($config));

$started = king_object_store_begin_resumable_upload('resumable-gcs', [
    'content_type' => 'application/octet-stream',
    'integrity_sha256' => $payloadHash,
]);
var_dump($started['backend']);
var_dump($started['protocol']);
var_dump($started['uploaded_bytes']);
var_dump($started['chunk_size_bytes']);
var_dump($started['sequential_chunks_required']);
var_dump($started['final_chunk_may_be_shorter']);

$afterChunkOne = king_object_store_append_resumable_upload_chunk(
    $started['upload_id'],
    king_object_store_432_stream('chunk-one')
);
var_dump($afterChunkOne['uploaded_bytes']);
var_dump($afterChunkOne['remote_completed']);
var_dump($afterChunkOne['final_chunk_received']);

$status = king_object_store_get_resumable_upload_status($started['upload_id']);
var_dump($status['next_offset']);
var_dump($status['uploaded_part_count']);

$afterChunkTwo = king_object_store_append_resumable_upload_chunk(
    $started['upload_id'],
    king_object_store_432_stream('++chunk-two'),
    ['final' => true]
);
var_dump($afterChunkTwo['uploaded_bytes']);
var_dump($afterChunkTwo['remote_completed']);
var_dump($afterChunkTwo['final_chunk_received']);

$completed = king_object_store_complete_resumable_upload($started['upload_id']);
var_dump($completed['completed']);
var_dump($completed['remote_completed']);
var_dump(king_object_store_get_resumable_upload_status($started['upload_id']));
var_dump(king_object_store_get('resumable-gcs'));

$metadata = king_object_store_get_metadata('resumable-gcs');
var_dump($metadata['content_length']);
var_dump($metadata['integrity_sha256'] === $payloadHash);

$capture = king_object_store_s3_mock_stop_server($mock);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'POST'
        && $event['target'] === '/resumable-gcs-test/resumable-gcs?uploadType=resumable'
)) === 1);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'PUT'
        && str_starts_with($event['target'], '/__gcs_resumable/')
        && (($event['headers']['content-range'] ?? '') === 'bytes 0-8/*')
)) === 1);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'PUT'
        && str_starts_with($event['target'], '/__gcs_resumable/')
        && (($event['headers']['content-range'] ?? '') === 'bytes 9-19/20')
)) === 1);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool => ($event['authorization_bearer_token'] ?? '') === 'gcs-token'
)) >= 3);

king_object_store_432_cleanup_dir($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
string(9) "cloud_gcs"
string(13) "gcs_resumable"
int(0)
int(1024)
bool(true)
bool(true)
int(9)
bool(false)
bool(false)
int(9)
int(1)
int(20)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
string(20) "chunk-one++chunk-two"
int(20)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
