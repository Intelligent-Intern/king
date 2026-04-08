--TEST--
Repo-local Flow PHP failure taxonomy classifies source and sink validation missing-data transport and quota failures
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
require_once __DIR__ . '/../../userland/flow-php/src/StreamingSource.php';
require_once __DIR__ . '/../../userland/flow-php/src/StreamingSink.php';
require_once __DIR__ . '/../../userland/flow-php/src/FailureTaxonomy.php';

use King\Flow\FlowFailureTaxonomy;
use King\Flow\HttpByteSource;
use King\Flow\ObjectStoreByteSink;
use King\Flow\ObjectStoreByteSource;
use King\Flow\SourceCursor;

function king_flow_failure_taxonomy_cleanup_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_flow_failure_taxonomy_cleanup_tree($path . '/' . $entry);
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
}

$root = sys_get_temp_dir() . '/king-flow-failure-taxonomy-611-' . getmypid();
king_flow_failure_taxonomy_cleanup_tree($root);
mkdir($root, 0700, true);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]);

$source = fopen('php://temp', 'w+');
fwrite($source, "alpha\n");
rewind($source);
king_object_store_put_from_stream('records.ndjson', $source);

try {
    (new ObjectStoreByteSource('records.ndjson', 4))->pumpBytes(
        static fn(string $chunk, SourceCursor $cursor, bool $complete): bool => true,
        new SourceCursor('http', 'GET http://127.0.0.1/payload', 0)
    );
    $validation = null;
} catch (Throwable $error) {
    $validation = FlowFailureTaxonomy::fromThrowable(
        $error,
        'source',
        'pump_bytes',
        ['transport' => 'object_store', 'identity' => 'records.ndjson']
    );
}

try {
    (new ObjectStoreByteSource('missing.ndjson', 4))->pumpBytes(
        static fn(string $chunk, SourceCursor $cursor, bool $complete): bool => true
    );
    $missingData = null;
} catch (Throwable $error) {
    $missingData = FlowFailureTaxonomy::fromThrowable(
        $error,
        'source',
        'pump_bytes',
        ['transport' => 'object_store', 'identity' => 'missing.ndjson']
    );
}

$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe === false) {
    throw new RuntimeException('failed to reserve transport test port');
}
$address = stream_socket_get_name($probe, false);
$deadPort = (int) substr((string) strrchr((string) $address, ':'), 1);
fclose($probe);

try {
    (new HttpByteSource(
        'http://127.0.0.1:' . $deadPort . '/payload',
        'GET',
        [],
        null,
        5,
        ['timeout_ms' => 250]
    ))->pumpBytes(static fn(string $chunk, SourceCursor $cursor, bool $complete): bool => true);
    $transport = null;
} catch (Throwable $error) {
    $transport = FlowFailureTaxonomy::fromThrowable(
        $error,
        'source',
        'pump_bytes',
        ['transport' => 'http']
    );
}

$mock = king_object_store_s3_mock_start_server(
    null,
    '127.0.0.1',
    [
        'provider' => 'gcs',
        'expected_access_token' => 'gcs-token',
        'forced_responses' => [[
            'method' => 'POST',
            'target' => '/flow-taxonomy/flow-taxonomy-quota.ndjson?uploadType=resumable',
            'status' => 507,
            'error_code' => 'InsufficientStorage',
            'error_message' => 'Not enough storage quota.',
        ]],
    ]
);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_gcs',
    'chunk_size_kb' => 1,
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'flow-taxonomy',
        'access_token' => 'gcs-token',
        'path_style' => true,
        'verify_tls' => false,
    ],
]);

$quotaResult = (new ObjectStoreByteSink('flow-taxonomy-quota.ndjson', [
    'content_type' => 'application/octet-stream',
]))->write('alpha');
$quota = FlowFailureTaxonomy::fromSinkResult($quotaResult, 'write');

var_dump($validation?->category() === 'validation');
var_dump($validation?->retryDisposition() === 'non_retryable');
var_dump($validation?->retryable() === false);

var_dump($missingData?->category() === 'missing_data');
var_dump($missingData?->retryDisposition() === 'wait_for_data');
var_dump($missingData?->retryable() === true);

var_dump($transport?->category() === 'transport');
var_dump($transport?->retryDisposition() === 'retry_with_backoff');
var_dump($transport?->retryable() === true);

var_dump($quotaResult->failure()?->category() === 'quota');
var_dump($quota?->category() === 'quota');
var_dump($quota?->retryDisposition() === 'retry_after_quota_relief');
var_dump($quota?->retryable() === true);

king_object_store_s3_mock_stop_server($mock);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
king_flow_failure_taxonomy_cleanup_tree($root);
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
