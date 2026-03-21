--TEST--
King proto map<string, message> decode uses the empty message value when an entry omits value
--FILE--
<?php
var_dump(king_proto_define_schema('Child', []));
var_dump(king_proto_define_schema('Catalog', [
    'children' => ['tag' => 1, 'type' => 'map<string,Child>'],
]));

$decoded_array = king_proto_decode('Catalog', hex2bin('0a030a016b'));
var_dump($decoded_array);

$decoded_object = king_proto_decode('Catalog', hex2bin('0a030a016b'), true);
var_dump($decoded_object instanceof stdClass);
var_dump(is_array($decoded_object->children));
var_dump($decoded_object->children['k'] instanceof stdClass);
var_dump((array) $decoded_object->children['k']);
?>
--EXPECT--
bool(true)
bool(true)
array(1) {
  ["children"]=>
  array(1) {
    ["k"]=>
    array(0) {
    }
  }
}
bool(true)
bool(true)
bool(true)
array(0) {
}
