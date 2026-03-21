--TEST--
King proto schema definitions populate the active skeleton registry
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));

var_dump(king_proto_define_schema('User', [
    'id' => ['tag' => 1, 'type' => 'int32'],
    'status' => ['tag' => 2, 'type' => 'Status'],
    'tags' => ['tag' => 3, 'type' => 'repeated_string'],
]));

var_dump(king_proto_is_defined('User'));
var_dump(king_proto_is_schema_defined('User'));
var_dump(king_proto_get_defined_schemas());

var_dump(bin2hex(king_proto_encode('User', ['id' => 1])));
var_dump(king_proto_decode('User', hex2bin('0801')));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
array(1) {
  [0]=>
  string(4) "User"
}
string(4) "0801"
array(1) {
  ["id"]=>
  int(1)
}
