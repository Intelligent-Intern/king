--TEST--
King proto repeated nested message fields roundtrip in the skeleton runtime subset
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'name' => ['tag' => 2, 'type' => 'string'],
]));
var_dump(king_proto_define_schema('Parent', [
    'children' => ['tag' => 1, 'type' => 'repeated_Child', 'required' => true],
]));

var_dump(bin2hex(king_proto_encode('Parent', [
    'children' => [
        ['name' => 'a', 'id' => 7],
        ['id' => 9],
    ],
])));

$decoded_array = king_proto_decode('Parent', hex2bin('0a0508071201610a020809'));
var_dump($decoded_array);

$decoded_object = king_proto_decode('Parent', hex2bin('0a0508071201610a020809'), true);
var_dump($decoded_object instanceof stdClass);
var_dump(is_array($decoded_object->children));
var_dump($decoded_object->children[0] instanceof stdClass);
var_dump((array) $decoded_object->children[0]);
?>
--EXPECT--
bool(true)
bool(true)
string(22) "0a0508071201610a020809"
array(1) {
  ["children"]=>
  array(2) {
    [0]=>
    array(2) {
      ["id"]=>
      int(7)
      ["name"]=>
      string(1) "a"
    }
    [1]=>
    array(1) {
      ["id"]=>
      int(9)
    }
  }
}
bool(true)
bool(true)
bool(true)
array(2) {
  ["id"]=>
  int(7)
  ["name"]=>
  string(1) "a"
}
