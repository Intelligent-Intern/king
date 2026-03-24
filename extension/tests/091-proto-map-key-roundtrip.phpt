--TEST--
King proto map bool and 32-bit integer keys roundtrip through the runtime subset
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));
var_dump(king_proto_define_schema('Catalog', [
    'labels' => ['tag' => 1, 'type' => 'map<int32,string>'],
    'flags' => ['tag' => 2, 'type' => 'map<bool,string>'],
    'children' => ['tag' => 3, 'type' => 'map<uint32,Child>'],
]));

$payload = king_proto_encode('Catalog', [
    'labels' => [-7 => 'neg', 42 => 'pos'],
    'flags' => [0 => 'cold', 1 => 'hot'],
    'children' => [7 => ['id' => 9]],
]);

var_dump(bin2hex($payload));
var_dump(king_proto_decode('Catalog', $payload));

$decoded_object = king_proto_decode('Catalog', $payload, true);
var_dump($decoded_object instanceof stdClass);
var_dump(is_array($decoded_object->labels));
var_dump($decoded_object->children[7] instanceof stdClass);
var_dump((array) $decoded_object->children[7]);
?>
--EXPECTF--
bool(true)
bool(true)
string(%d) "%s"
array(3) {
  ["labels"]=>
  array(2) {
    [-7]=>
    string(3) "neg"
    [42]=>
    string(3) "pos"
  }
  ["flags"]=>
  array(2) {
    [0]=>
    string(4) "cold"
    [1]=>
    string(3) "hot"
  }
  ["children"]=>
  array(1) {
    [7]=>
    array(1) {
      ["id"]=>
      int(9)
    }
  }
}
bool(true)
bool(true)
bool(true)
array(1) {
  ["id"]=>
  int(9)
}
