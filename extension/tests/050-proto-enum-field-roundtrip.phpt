--TEST--
King proto enum fields are handled numerically in the skeleton runtime subset
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'NEG' => -1,
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));
var_dump(king_proto_define_schema('Job', [
    'status' => ['tag' => 1, 'type' => 'Status', 'required' => true],
]));

var_dump(bin2hex(king_proto_encode('Job', [
    'status' => 2,
])));

var_dump(king_proto_decode('Job', hex2bin('08ffffffffffffffffff01')));
var_dump(king_proto_decode('Job', hex2bin('0801')));
var_dump(king_proto_decode('Job', hex2bin('0863')));
?>
--EXPECT--
bool(true)
bool(true)
string(4) "0802"
array(1) {
  ["status"]=>
  int(-1)
}
array(1) {
  ["status"]=>
  int(1)
}
array(1) {
  ["status"]=>
  int(99)
}
