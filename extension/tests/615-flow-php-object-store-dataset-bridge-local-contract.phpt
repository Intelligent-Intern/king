--TEST--
Repo-local Flow PHP object-store dataset bridge preserves hybrid topology metadata and bounded range reads
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/ObjectStoreDataset.php';

use King\Flow\ObjectStoreDataset;
use King\Flow\SourceCursor;

$cleanupTree = static function (string $path) use (&$cleanupTree): void {
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $cleanupTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
};

$root = sys_get_temp_dir() . '/king-flow-dataset-bridge-local-' . getmypid();
$cleanupTree($root);
mkdir($root, 0700, true);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'distributed',
    'chunk_size_kb' => 1,
]);

$payload = 'alpha-beta-gamma';
$integrity = hash('sha256', $payload);
$expiresAt = '2099-01-01T00:00:00Z';
$dataset = new ObjectStoreDataset('orders-dataset.ndjson', 3);
$writer = $dataset->sink([
    'content_type' => 'application/x-ndjson',
    'object_type' => 'document',
    'cache_policy' => 'smart_cdn',
    'expires_at' => $expiresAt,
    'integrity_sha256' => $integrity,
]);

$firstWrite = $writer->write('alpha-');
$secondWrite = $writer->write('beta-');
$complete = $writer->complete('gamma');
$descriptor = $dataset->describe();
$metadata = king_object_store_get_metadata('orders-dataset.ndjson');
$rangeSource = $dataset->source(6, 4);

$firstChunks = [];
$firstRange = $rangeSource->pumpBytes(
    function (string $chunk, SourceCursor $cursor) use (&$firstChunks): bool {
        $firstChunks[] = [
            $chunk,
            $cursor->bytesConsumed(),
            $cursor->state()['range_bytes_delivered'],
        ];

        return false;
    }
);

$resumeCursor = SourceCursor::fromArray($firstRange->cursor()->toArray());
$secondChunks = [];
$secondRange = $rangeSource->pumpBytes(
    function (string $chunk, SourceCursor $cursor) use (&$secondChunks): bool {
        $secondChunks[] = [
            $chunk,
            $cursor->bytesConsumed(),
            $cursor->state()['range_bytes_delivered'],
            $cursor->state()['range_end_offset'],
        ];

        return true;
    },
    $resumeCursor
);

var_dump($firstWrite->cursor()->toArray()['resume_strategy']);
var_dump($firstWrite->cursor()->toArray()['state']['mode']);
var_dump($secondWrite->cursor()->toArray()['bytes_accepted']);
var_dump($complete->complete());
var_dump($complete->transportCommitted());
var_dump($descriptor instanceof \King\Flow\ObjectStoreDatasetDescriptor);
var_dump($descriptor->contentType());
var_dump($descriptor->integritySha256() === $integrity);
var_dump($descriptor->expiresAt() === $metadata['expires_at']);
var_dump($descriptor->objectTypeName());
var_dump($descriptor->cachePolicyName());
var_dump($descriptor->topology()->activeBackends());
var_dump($descriptor->topology()->distributedPresent());
var_dump($descriptor->topology()->backedUp());
var_dump($firstRange->complete());
var_dump($firstChunks);
var_dump($secondRange->complete());
var_dump($secondChunks);

$cleanupTree($root);
?>
--EXPECT--
string(18) "replay_local_spool"
string(10) "staged_put"
int(11)
bool(true)
bool(true)
bool(true)
string(20) "application/x-ndjson"
bool(true)
bool(true)
string(8) "document"
string(9) "smart_cdn"
array(2) {
  [0]=>
  string(8) "local_fs"
  [1]=>
  string(11) "distributed"
}
bool(true)
bool(true)
bool(false)
array(1) {
  [0]=>
  array(3) {
    [0]=>
    string(3) "bet"
    [1]=>
    int(9)
    [2]=>
    int(3)
  }
}
bool(true)
array(1) {
  [0]=>
  array(4) {
    [0]=>
    string(1) "a"
    [1]=>
    int(10)
    [2]=>
    int(4)
    [3]=>
    int(10)
  }
}
