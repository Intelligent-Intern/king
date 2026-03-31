--TEST--
King object-store cloud_azure exposes a real block upload session contract
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

function king_object_store_433_cleanup_dir(string $dir): void
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

function king_object_store_433_stream(string $payload)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $payload);
    rewind($stream);
    return $stream;
}

$root = sys_get_temp_dir() . '/king_object_store_azure_blocks_433_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}

$mock = king_object_store_s3_mock_start_server(
    null,
    '127.0.0.1',
    [
        'provider' => 'azure',
        'expected_access_token' => 'azure-token',
    ]
);
$payload = 'azure-' . 'blocks';
$payloadHash = hash('sha256', $payload);

$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_azure',
    'chunk_size_kb' => 1,
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'container' => 'block-azure-test',
        'access_token' => 'azure-token',
        'verify_tls' => false,
    ],
];

var_dump(king_object_store_init($config));

$started = king_object_store_begin_resumable_upload('block-azure', [
    'content_type' => 'application/octet-stream',
    'cache_policy' => 'etag',
    'integrity_sha256' => $payloadHash,
]);
var_dump($started['backend']);
var_dump($started['protocol']);
var_dump($started['chunk_size_bytes']);
var_dump($started['sequential_chunks_required']);
var_dump($started['final_chunk_may_be_shorter']);
var_dump($started['next_part_number']);

$afterChunkOne = king_object_store_append_resumable_upload_chunk(
    $started['upload_id'],
    king_object_store_433_stream('azure-')
);
var_dump($afterChunkOne['uploaded_bytes']);
var_dump($afterChunkOne['remote_completed']);
var_dump($afterChunkOne['next_part_number']);

$afterChunkTwo = king_object_store_append_resumable_upload_chunk(
    $started['upload_id'],
    king_object_store_433_stream('blocks'),
    ['final' => true]
);
var_dump($afterChunkTwo['uploaded_bytes']);
var_dump($afterChunkTwo['final_chunk_received']);
var_dump($afterChunkTwo['remote_completed']);

$completed = king_object_store_complete_resumable_upload($started['upload_id']);
var_dump($completed['completed']);
var_dump($completed['remote_completed']);
var_dump(king_object_store_get_resumable_upload_status($started['upload_id']));
var_dump(king_object_store_get('block-azure'));

$metadata = king_object_store_get_metadata('block-azure');
var_dump($metadata['content_length']);
var_dump($metadata['integrity_sha256'] === $payloadHash);
var_dump($metadata['cache_policy_name']);

$capture = king_object_store_s3_mock_stop_server($mock);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'PUT'
        && str_starts_with($event['target'], '/block-azure-test/block-azure?comp=block&blockid=')
)) === 2);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'PUT'
        && $event['target'] === '/block-azure-test/block-azure?comp=blocklist'
)) === 1);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool => ($event['authorization_bearer_token'] ?? '') === 'azure-token'
)) >= 3);

king_object_store_433_cleanup_dir($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
string(11) "cloud_azure"
string(12) "azure_blocks"
int(1024)
bool(true)
bool(true)
int(1)
int(6)
bool(false)
int(2)
int(12)
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
string(12) "azure-blocks"
int(12)
bool(true)
string(4) "etag"
bool(true)
bool(true)
bool(true)
