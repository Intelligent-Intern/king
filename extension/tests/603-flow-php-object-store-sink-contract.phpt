--TEST--
Repo-local Flow PHP object-store sink preserves resumable upload progress across cursor resume
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
require_once __DIR__ . '/../../userland/flow-php/src/StreamingSink.php';

use King\Flow\ObjectStoreByteSink;
use King\Flow\SinkCursor;

function king_flow_object_store_sink_cleanup_dir(string $dir): void
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

$root = sys_get_temp_dir() . '/king-flow-sink-object-store-' . getmypid();
@mkdir($root, 0700, true);

$mock = king_object_store_s3_mock_start_server(
    null,
    '127.0.0.1',
    [
        'provider' => 'gcs',
        'expected_access_token' => 'gcs-token',
    ]
);

$partA = str_repeat('A', 900);
$partB = str_repeat('B', 900);
$payload = $partA . $partB;
$payloadHash = hash('sha256', $payload);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_gcs',
    'chunk_size_kb' => 1,
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'flow-sink-gcs',
        'access_token' => 'gcs-token',
        'path_style' => true,
        'verify_tls' => false,
    ],
]);

$adapter = new ObjectStoreByteSink('flow-sink-gcs.ndjson', [
    'content_type' => 'application/octet-stream',
    'integrity_sha256' => $payloadHash,
]);

$first = $adapter->write($partA);
$second = $adapter->write($partB);
$cursor = SinkCursor::fromArray($second->cursor()->toArray());
$resumed = new ObjectStoreByteSink('flow-sink-gcs.ndjson', [
    'content_type' => 'application/octet-stream',
    'integrity_sha256' => $payloadHash,
], $cursor);
$complete = $resumed->complete();

var_dump($first->failure());
var_dump($second->cursor()->toArray()['resume_strategy']);
var_dump($second->cursor()->toArray()['state']['uploaded_bytes']);
var_dump(strlen(base64_decode($second->cursor()->toArray()['state']['pending_buffer_base64'], true)));
var_dump($complete->complete());
var_dump($complete->transportCommitted());
var_dump(king_object_store_get('flow-sink-gcs.ndjson') === $payload);

$capture = king_object_store_s3_mock_stop_server($mock);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'POST'
        && $event['target'] === '/flow-sink-gcs/flow-sink-gcs.ndjson?uploadType=resumable'
)) === 1);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'PUT'
        && str_starts_with($event['target'], '/__gcs_resumable/')
        && (($event['headers']['content-range'] ?? '') === 'bytes 0-1023/*')
)) === 1);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'PUT'
        && str_starts_with($event['target'], '/__gcs_resumable/')
        && (($event['headers']['content-range'] ?? '') === 'bytes 1024-1799/1800')
)) === 1);

king_flow_object_store_sink_cleanup_dir($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
NULL
string(21) "resume_upload_session"
int(1024)
int(776)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
