--TEST--
Repo-local Flow PHP object-store source streams records with resumable cursors
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/StreamingSource.php';

use King\Flow\ObjectStoreByteSource;
use King\Flow\SourceCursor;

$root = sys_get_temp_dir() . '/king-flow-source-object-store-' . getmypid();
@mkdir($root, 0700, true);

king_object_store_init([
    'primary_backend' => 'local_fs',
    'storage_root_path' => $root,
    'chunk_size_kb' => 1,
]);

$payload = "alpha\nbeta\ngamma\ndelta\n";
$source = fopen('php://temp', 'w+');
fwrite($source, $payload);
rewind($source);

var_dump(king_object_store_put_from_stream('records.ndjson', $source));

$adapter = new ObjectStoreByteSource('records.ndjson', 4);
$firstLines = [];
$firstResult = $adapter->pumpLines(
    function (string $line, SourceCursor $cursor) use (&$firstLines): bool {
        $firstLines[] = [$line, $cursor->bytesConsumed()];

        return count($firstLines) < 2;
    }
);

$cursor = SourceCursor::fromArray($firstResult->cursor()->toArray());
$remainingLines = [];
$secondResult = $adapter->pumpLines(
    function (string $line, SourceCursor $cursor) use (&$remainingLines): bool {
        $remainingLines[] = [$line, $cursor->bytesConsumed()];

        return true;
    },
    $cursor
);

var_dump($firstResult->complete());
var_dump($firstResult->recordsDelivered());
var_dump($firstLines);
var_dump($secondResult->complete());
var_dump($secondResult->recordsDelivered());
var_dump($remainingLines);
var_dump($secondResult->cursor()->toArray()['resume_strategy']);
var_dump($secondResult->cursor()->toArray()['state']['next_offset']);

@unlink($root . '/records.ndjson');
@unlink($root . '/records.ndjson.meta');
@rmdir($root);
?>
--EXPECT--
bool(true)
bool(false)
int(2)
array(2) {
  [0]=>
  array(2) {
    [0]=>
    string(5) "alpha"
    [1]=>
    int(8)
  }
  [1]=>
  array(2) {
    [0]=>
    string(4) "beta"
    [1]=>
    int(12)
  }
}
bool(true)
int(2)
array(2) {
  [0]=>
  array(2) {
    [0]=>
    string(5) "gamma"
    [1]=>
    int(20)
  }
  [1]=>
  array(2) {
    [0]=>
    string(5) "delta"
    [1]=>
    int(23)
  }
}
string(12) "range_offset"
int(23)
