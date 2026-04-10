--TEST--
Repo-local Flow PHP object-store dataset bridge preserves resumable cloud multipart upload and streamed readback
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
require_once __DIR__ . '/../../demo/userland/flow-php/src/ObjectStoreDataset.php';

use King\Flow\ObjectStoreDataset;
use King\Flow\SinkCursor;

function king_flow_dataset_bridge_cloud_cleanup_dir(string $dir): void
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

$root = sys_get_temp_dir() . '/king-flow-dataset-bridge-cloud-' . getmypid();
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
$integrity = hash('sha256', $payload);
$expiresAt = '2099-01-01T00:00:00Z';

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_gcs',
    'chunk_size_kb' => 1,
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'flow-dataset-gcs',
        'access_token' => 'gcs-token',
        'path_style' => true,
        'verify_tls' => false,
    ],
]);

$dataset = new ObjectStoreDataset('flow-dataset-gcs.ndjson', 700);
$writer = $dataset->sink([
    'content_type' => 'application/octet-stream',
    'object_type' => 'binary_data',
    'cache_policy' => 'etag',
    'expires_at' => $expiresAt,
    'integrity_sha256' => $integrity,
]);

$firstWrite = $writer->write($partA);
$secondWrite = $writer->write($partB);
$cursor = SinkCursor::fromArray($secondWrite->cursor()->toArray());
$resumed = $dataset->sink([
    'content_type' => 'application/octet-stream',
    'object_type' => 'binary_data',
    'cache_policy' => 'etag',
    'expires_at' => $expiresAt,
    'integrity_sha256' => $integrity,
], $cursor);
$complete = $resumed->complete();
$descriptor = $dataset->describe();
$metadata = king_object_store_get_metadata('flow-dataset-gcs.ndjson');

$chunkSizes = [];
$sourceResult = $dataset->source()->pumpBytes(
    function (string $chunk) use (&$chunkSizes): bool {
        $chunkSizes[] = strlen($chunk);

        return true;
    }
);

var_dump($firstWrite->failure());
var_dump($secondWrite->cursor()->toArray()['resume_strategy']);
var_dump($secondWrite->cursor()->toArray()['state']['uploaded_bytes']);
var_dump(strlen(base64_decode($secondWrite->cursor()->toArray()['state']['pending_buffer_base64'], true)));
var_dump($complete->complete());
var_dump($complete->transportCommitted());
var_dump($descriptor->integritySha256() === $integrity);
var_dump($descriptor->expiresAt() === $metadata['expires_at']);
var_dump($descriptor->objectTypeName());
var_dump($descriptor->cachePolicyName());
var_dump($descriptor->topology()->activeBackends());
var_dump($descriptor->topology()->cloudGcsPresent());
var_dump($sourceResult->complete());
var_dump($sourceResult->bytesDelivered());
var_dump($chunkSizes);
var_dump(king_object_store_get('flow-dataset-gcs.ndjson') === $payload);

$capture = king_object_store_s3_mock_stop_server($mock);
var_dump(count(array_filter(
    $capture['events'],
    static fn(array $event): bool =>
        $event['method'] === 'POST'
        && $event['target'] === '/flow-dataset-gcs/flow-dataset-gcs.ndjson?uploadType=resumable'
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

king_flow_dataset_bridge_cloud_cleanup_dir($root);
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
string(11) "binary_data"
string(4) "etag"
array(1) {
  [0]=>
  string(9) "cloud_gcs"
}
bool(true)
bool(true)
int(1800)
array(3) {
  [0]=>
  int(700)
  [1]=>
  int(700)
  [2]=>
  int(400)
}
bool(true)
bool(true)
bool(true)
bool(true)
