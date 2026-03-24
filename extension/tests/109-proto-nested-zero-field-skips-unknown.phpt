--TEST--
King proto nested zero-field messages ignore unknown child fields in the runtime subset
--FILE--
<?php
var_dump(king_proto_define_schema('Child', []));
var_dump(king_proto_define_schema('Parent', [
    'child' => ['tag' => 1, 'type' => 'Child', 'required' => true],
]));

$decoded_array = king_proto_decode('Parent', hex2bin('0a020801'));
var_dump($decoded_array);

$decoded_object = king_proto_decode('Parent', hex2bin('0a020801'), true);
var_dump($decoded_object instanceof stdClass);
var_dump($decoded_object->child instanceof stdClass);
var_dump((array) $decoded_object->child);
?>
--EXPECT--
bool(true)
bool(true)
array(1) {
  ["child"]=>
  array(0) {
  }
}
bool(true)
bool(true)
array(0) {
}
