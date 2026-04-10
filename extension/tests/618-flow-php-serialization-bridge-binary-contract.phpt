--TEST--
Repo-local Flow PHP serialization bridge preserves Proto, IIBIN, and binary object payload workflows
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/SerializationBridge.php';

use King\Flow\BinaryObjectCodec;
use King\Flow\BinaryObjectPayload;
use King\Flow\IibinSchemaCodec;
use King\Flow\ObjectStoreDataset;
use King\Flow\ProtoSchemaCodec;
use King\Flow\SerializedRecordReader;
use King\Flow\SerializedRecordWriter;
use King\Flow\SinkCursor;

final class FlowProtoChild618
{
    public int $id;
    public ?string $name = null;
}

final class FlowProtoParent618
{
    public $child;
    public bool $enabled;
}

final class FlowIibinItem618
{
    public int $id;
}

final class FlowIibinCatalog618
{
    public array $children;
}

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

$root = sys_get_temp_dir() . '/king-flow-serialization-binary-' . getmypid();
$cleanupTree($root);
mkdir($root, 0700, true);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]);

king_proto_define_schema('FlowProtoChild618', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'name' => ['tag' => 2, 'type' => 'string'],
]);
king_proto_define_schema('FlowProtoParent618', [
    'child' => ['tag' => 1, 'type' => 'FlowProtoChild618', 'required' => true],
    'enabled' => ['tag' => 2, 'type' => 'bool'],
]);
King\IIBIN::defineSchema('FlowIibinItem618', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]);
King\IIBIN::defineSchema('FlowIibinCatalog618', [
    'children' => ['tag' => 1, 'type' => 'map<string,FlowIibinItem618>'],
]);

$protoDataset = new ObjectStoreDataset('message.proto', 4);
$protoCodec = new ProtoSchemaCodec('FlowProtoParent618', [
    'FlowProtoParent618' => FlowProtoParent618::class,
    'FlowProtoChild618' => FlowProtoChild618::class,
]);
$protoWriter = new SerializedRecordWriter(
    static fn(?SinkCursor $cursor) => $protoDataset->sink([
        'content_type' => 'application/x-protobuf',
        'object_type' => 'binary_data',
    ], $cursor),
    $protoCodec
);
$protoComplete = $protoWriter->complete([
    'child' => ['id' => 7, 'name' => 'ab'],
    'enabled' => true,
]);
$protoReader = new SerializedRecordReader($protoDataset->source(), $protoCodec);
$protoDecoded = [];
$protoRead = $protoReader->pumpRecords(
    function (FlowProtoParent618 $record) use (&$protoDecoded): bool {
        $protoDecoded[] = [get_class($record), get_class($record->child), $record->child->id, $record->child->name, $record->enabled];

        return true;
    }
);
$protoPayload = king_object_store_get('message.proto');

$iibinDataset = new ObjectStoreDataset('catalog.iibin', 6);
$iibinCodec = new IibinSchemaCodec('FlowIibinCatalog618', [
    'FlowIibinCatalog618' => FlowIibinCatalog618::class,
    'FlowIibinItem618' => FlowIibinItem618::class,
]);
$iibinWriter = new SerializedRecordWriter(
    static fn(?SinkCursor $cursor) => $iibinDataset->sink([
        'content_type' => 'application/x-iibin',
        'object_type' => 'binary_data',
    ], $cursor),
    $iibinCodec
);
$iibinComplete = $iibinWriter->complete([
    'children' => [
        'a' => ['id' => 1],
        'b' => ['id' => 2],
    ],
]);
$iibinReader = new SerializedRecordReader($iibinDataset->source(), $iibinCodec);
$iibinDecoded = [];
$iibinRead = $iibinReader->pumpRecords(
    function (FlowIibinCatalog618 $record) use (&$iibinDecoded): bool {
        $iibinDecoded[] = [get_class($record), get_class($record->children['a']), $record->children['a']->id, $record->children['b']->id];

        return true;
    }
);
$iibinPayload = king_object_store_get('catalog.iibin');

$binaryDataset = new ObjectStoreDataset('blob.bin', 2);
$binaryCodec = new BinaryObjectCodec();
$binaryWriter = new SerializedRecordWriter(
    static fn(?SinkCursor $cursor) => $binaryDataset->sink([
        'content_type' => 'application/octet-stream',
        'object_type' => 'binary_data',
    ], $cursor),
    $binaryCodec
);
$binaryComplete = $binaryWriter->complete(new BinaryObjectPayload("A\0B"));
$binaryReader = new SerializedRecordReader($binaryDataset->source(), $binaryCodec);
$binaryDecoded = [];
$binaryRead = $binaryReader->pumpRecords(
    function (BinaryObjectPayload $record) use (&$binaryDecoded): bool {
        $binaryDecoded[] = [bin2hex($record->payload()), strlen($record->payload())];

        return true;
    }
);

var_dump($protoComplete->complete());
var_dump(bin2hex($protoPayload));
var_dump($protoRead->recordsDelivered());
var_dump($protoDecoded);
var_dump($iibinComplete->transportCommitted());
var_dump(bin2hex($iibinPayload));
var_dump($iibinRead->recordsDelivered());
var_dump($iibinDecoded);
var_dump($binaryComplete->transportCommitted());
var_dump($binaryRead->recordsDelivered());
var_dump($binaryDecoded);

$cleanupTree($root);
?>
--EXPECT--
bool(true)
string(20) "0a060807120261621001"
int(1)
array(1) {
  [0]=>
  array(5) {
    [0]=>
    string(18) "FlowProtoParent618"
    [1]=>
    string(17) "FlowProtoChild618"
    [2]=>
    int(7)
    [3]=>
    string(2) "ab"
    [4]=>
    bool(true)
  }
}
bool(true)
string(36) "0a070a0161120208010a070a016212020802"
int(1)
array(1) {
  [0]=>
  array(4) {
    [0]=>
    string(19) "FlowIibinCatalog618"
    [1]=>
    string(16) "FlowIibinItem618"
    [2]=>
    int(1)
    [3]=>
    int(2)
  }
}
bool(true)
int(1)
array(1) {
  [0]=>
  array(2) {
    [0]=>
    string(6) "410042"
    [1]=>
    int(3)
  }
}
