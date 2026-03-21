--TEST--
King proto map<string,Message> decode initializes missing entry values via the nested runtime schema
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'name' => ['tag' => 1, 'type' => 'string', 'default' => 'anon'],
]));
var_dump(king_proto_define_schema('Catalog', [
    'children' => ['tag' => 1, 'type' => 'map<string,Child>'],
]));

$decoded_array = king_proto_decode('Catalog', hex2bin('0a050a03666f6f'));
var_dump($decoded_array);

$decoded_object = king_proto_decode('Catalog', hex2bin('0a050a03666f6f'), true);
var_dump($decoded_object instanceof stdClass);
var_dump(is_array($decoded_object->children));
var_dump($decoded_object->children['foo'] instanceof stdClass);
var_dump((array) $decoded_object->children['foo']);
?>
--EXPECTF--
bool(true)
bool(true)
array(1) {
  ["children"]=>
  array(1) {
    ["foo"]=>
    array(1) {
      ["name"]=>
      string(4) "anon"
    }
  }
}
bool(true)
bool(true)
bool(true)
array(1) {
  ["name"]=>
  string(4) "anon"
}
