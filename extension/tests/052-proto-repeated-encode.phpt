--TEST--
King proto repeated primitive and enum fields encode in canonical tag order
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));
var_dump(king_proto_define_schema('Batch', [
    'ids' => ['tag' => 1, 'type' => 'repeated_int32', 'required' => true],
    'names' => ['tag' => 2, 'type' => 'repeated_string'],
    'states' => ['tag' => 3, 'type' => 'repeated_Status'],
]));

var_dump(bin2hex(king_proto_encode('Batch', [
    'ids' => [1, 150],
    'names' => ['a', 'bc'],
    'states' => [2, 1],
])));
?>
--EXPECT--
bool(true)
bool(true)
string(32) "08010896011201611202626318021801"
