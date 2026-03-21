--TEST--
King proto nested zero-field messages encode and decode in the skeleton runtime subset
--FILE--
<?php
var_dump(king_proto_define_schema('Child', []));
var_dump(king_proto_define_schema('Parent', [
    'child' => ['tag' => 1, 'type' => 'Child', 'required' => true],
]));

var_dump(bin2hex(king_proto_encode('Parent', ['child' => []])));
var_dump(king_proto_decode('Parent', hex2bin('0a00')));

$decoded_object = king_proto_decode('Parent', hex2bin('0a00'), true);
var_dump($decoded_object instanceof stdClass);
var_dump($decoded_object->child instanceof stdClass);
var_dump((array) $decoded_object->child);
?>
--EXPECT--
bool(true)
bool(true)
string(4) "0a00"
array(1) {
  ["child"]=>
  array(0) {
  }
}
bool(true)
bool(true)
array(0) {
}
