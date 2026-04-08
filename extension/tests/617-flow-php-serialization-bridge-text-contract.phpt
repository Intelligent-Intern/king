--TEST--
Repo-local Flow PHP serialization bridge preserves NDJSON resume plus CSV and JSON document workflows
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../userland/flow-php/src/SerializationBridge.php';

use King\Flow\CsvCodec;
use King\Flow\JsonDocumentCodec;
use King\Flow\NdjsonCodec;
use King\Flow\ObjectStoreDataset;
use King\Flow\SerializedRecordReader;
use King\Flow\SerializedRecordWriter;
use King\Flow\SinkCursor;
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

$root = sys_get_temp_dir() . '/king-flow-serialization-text-' . getmypid();
$cleanupTree($root);
mkdir($root, 0700, true);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]);

king_object_store_put('orders.ndjson', implode("\n", [
    '{"id":1,"country":"de"}',
    '{"id":2,"country":"fr"}',
]) . "\n", [
    'content_type' => 'application/x-ndjson',
    'object_type' => 'document',
]);

$ndjsonReader = new SerializedRecordReader(
    new ObjectStoreDataset('orders.ndjson', 5)->source(),
    new NdjsonCodec()
);

$firstRecords = [];
$firstResult = $ndjsonReader->pumpRecords(
    function (array $record, SourceCursor $cursor) use (&$firstRecords): bool {
        $firstRecords[] = [$record['id'], $record['country'], $cursor->resumeStrategy()];

        return false;
    }
);

$resumeCursor = SourceCursor::fromArray($firstResult->cursor()->toArray());
$remainingRecords = [];
$secondResult = $ndjsonReader->pumpRecords(
    function (array $record) use (&$remainingRecords): bool {
        $remainingRecords[] = [$record['id'], $record['country']];

        return true;
    },
    $resumeCursor
);

$csvDataset = new ObjectStoreDataset('orders.csv', 8);
$csvCodec = new CsvCodec();
$csvOptions = [
    'content_type' => 'text/csv',
    'object_type' => 'document',
    'cache_policy' => 'etag',
];
$csvWriter = new SerializedRecordWriter(
    static fn(?SinkCursor $cursor) => $csvDataset->sink($csvOptions, $cursor),
    $csvCodec
);
$csvFirstWrite = $csvWriter->writeRecord(['id' => '1', 'country' => 'de']);
$csvResumeCursor = SinkCursor::fromArray($csvFirstWrite->cursor()->toArray());
$csvResumedWriter = new SerializedRecordWriter(
    static fn(?SinkCursor $cursor) => $csvDataset->sink($csvOptions, $cursor),
    $csvCodec,
    $csvResumeCursor
);
$csvSecondWrite = $csvResumedWriter->writeRecord(['id' => '2', 'country' => 'fr']);
$csvComplete = $csvResumedWriter->complete();
$csvPayload = king_object_store_get('orders.csv');

$csvRecords = [];
$csvReader = new SerializedRecordReader($csvDataset->source(), new CsvCodec());
$csvRead = $csvReader->pumpRecords(
    function (array $record) use (&$csvRecords): bool {
        $csvRecords[] = $record;

        return true;
    }
);

$jsonDataset = new ObjectStoreDataset('summary.json', 7);
$jsonCodec = new JsonDocumentCodec();
$jsonWriter = new SerializedRecordWriter(
    static fn(?SinkCursor $cursor) => $jsonDataset->sink([
        'content_type' => 'application/json',
        'object_type' => 'document',
    ], $cursor),
    $jsonCodec
);
$jsonComplete = $jsonWriter->complete([
    'count' => 2,
    'countries' => ['de', 'fr'],
]);

$jsonDocuments = [];
$jsonReader = new SerializedRecordReader($jsonDataset->source(), $jsonCodec);
$jsonRead = $jsonReader->pumpRecords(
    function (array $record, SourceCursor $cursor) use (&$jsonDocuments): bool {
        $jsonDocuments[] = [$record['count'], $record['countries'][0], $record['countries'][1], $cursor->resumeStrategy()];

        return true;
    }
);

var_dump($firstResult->complete());
var_dump($firstResult->recordsDelivered());
var_dump($firstRecords);
var_dump($secondResult->complete());
var_dump($secondResult->recordsDelivered());
var_dump($remainingRecords);
var_dump($csvFirstWrite->cursor()->resumeStrategy());
var_dump($csvSecondWrite->recordsAccepted());
var_dump($csvComplete->complete());
var_dump($csvPayload);
var_dump($csvRead->recordsDelivered());
var_dump($csvRecords);
var_dump($jsonComplete->transportCommitted());
var_dump($jsonRead->recordsDelivered());
var_dump($jsonDocuments);

$cleanupTree($root);
?>
--EXPECT--
bool(false)
int(1)
array(1) {
  [0]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    string(2) "de"
    [2]=>
    string(20) "serialization_cursor"
  }
}
bool(true)
int(1)
array(1) {
  [0]=>
  array(2) {
    [0]=>
    int(2)
    [1]=>
    string(2) "fr"
  }
}
string(20) "serialization_cursor"
int(2)
bool(true)
string(21) "id,country
1,de
2,fr
"
int(2)
array(2) {
  [0]=>
  array(2) {
    ["id"]=>
    string(1) "1"
    ["country"]=>
    string(2) "de"
  }
  [1]=>
  array(2) {
    ["id"]=>
    string(1) "2"
    ["country"]=>
    string(2) "fr"
  }
}
bool(true)
int(1)
array(1) {
  [0]=>
  array(4) {
    [0]=>
    int(2)
    [1]=>
    string(2) "de"
    [2]=>
    string(2) "fr"
    [3]=>
    string(15) "replay_document"
  }
}
