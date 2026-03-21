--TEST--
King proto non-repeated nested messages roundtrip through the skeleton runtime subset
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'name' => ['tag' => 3, 'type' => 'string'],
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));
var_dump(king_proto_define_schema('Parent', [
    'child' => ['tag' => 1, 'type' => 'Child', 'required' => true],
    'enabled' => ['tag' => 2, 'type' => 'bool'],
]));

var_dump(bin2hex(king_proto_encode('Parent', [
    'enabled' => true,
    'child' => [
        'name' => 'k',
        'id' => 150,
    ],
])));

$decoded_array = king_proto_decode('Parent', hex2bin('0a060896011a016b1001'));
var_dump($decoded_array);

$decoded_object = king_proto_decode('Parent', hex2bin('0a060896011a016b1001'), true);
var_dump($decoded_object instanceof stdClass);
var_dump($decoded_object->child instanceof stdClass);
var_dump((array) $decoded_object->child);

try {
    king_proto_encode('Parent', [
        'child' => 'bad',
        'enabled' => true,
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
bool(true)
string(20) "0a060896011a016b1001"
array(2) {
  ["child"]=>
  array(2) {
    ["id"]=>
    int(150)
    ["name"]=>
    string(1) "k"
  }
  ["enabled"]=>
  bool(true)
}
bool(true)
bool(true)
array(2) {
  ["id"]=>
  int(150)
  ["name"]=>
  string(1) "k"
}
Encoding failed: Field 'child' expects a message object or array, but got string.
