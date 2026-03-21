--TEST--
King proto decode keeps the last singular field value and skips nested unknown fields
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32'],
]));
var_dump(king_proto_define_schema('Msg', [
    'value' => ['tag' => 1, 'type' => 'int32'],
    'child' => ['tag' => 2, 'type' => 'Child'],
]));
var_dump(king_proto_define_schema('Parent', [
    'child' => ['tag' => 1, 'type' => 'Child'],
    'name' => ['tag' => 2, 'type' => 'string'],
]));

var_dump(king_proto_decode('Msg', hex2bin('08010802')));
var_dump(king_proto_decode('Parent', hex2bin('12046b696e670a06080710010809')));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
array(1) {
  ["value"]=>
  int(2)
}
array(2) {
  ["name"]=>
  string(4) "king"
  ["child"]=>
  array(1) {
    ["id"]=>
    int(9)
  }
}
