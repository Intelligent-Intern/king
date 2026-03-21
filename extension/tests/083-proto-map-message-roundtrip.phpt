--TEST--
King proto map<string, message> fields roundtrip through the skeleton runtime subset
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'name' => ['tag' => 2, 'type' => 'string'],
]));
var_dump(king_proto_define_schema('Catalog', [
    'children' => ['tag' => 1, 'type' => 'map<string,Child>'],
]));

$payload = king_proto_encode('Catalog', [
    'children' => [
        'a' => ['id' => 1, 'name' => 'x'],
        'b' => ['id' => 2],
    ],
]);

var_dump(bin2hex($payload));
var_dump(king_proto_decode('Catalog', $payload));

$decoded_object = king_proto_decode('Catalog', $payload, true);
var_dump($decoded_object instanceof stdClass);
var_dump(is_array($decoded_object->children));
var_dump($decoded_object->children['a'] instanceof stdClass);
var_dump((array) $decoded_object->children['a']);
var_dump($decoded_object->children['b'] instanceof stdClass);
var_dump((array) $decoded_object->children['b']);
?>
--EXPECT--
bool(true)
bool(true)
string(42) "0a0a0a0161120508011201780a070a016212020802"
array(1) {
  ["children"]=>
  array(2) {
    ["a"]=>
    array(2) {
      ["id"]=>
      int(1)
      ["name"]=>
      string(1) "x"
    }
    ["b"]=>
    array(1) {
      ["id"]=>
      int(2)
    }
  }
}
bool(true)
bool(true)
bool(true)
array(2) {
  ["id"]=>
  int(1)
  ["name"]=>
  string(1) "x"
}
bool(true)
array(1) {
  ["id"]=>
  int(2)
}
