--TEST--
King proto enum fields accept registered enum member names during encode
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'ACTIVE' => 1,
    'DISABLED' => 2,
    'PAUSED' => 3,
]));
var_dump(king_proto_define_schema('Job', [
    'status' => ['tag' => 1, 'type' => 'Status', 'required' => true],
    'history' => ['tag' => 2, 'type' => 'repeated_Status', 'packed' => true],
]));

var_dump(bin2hex(king_proto_encode('Job', [
    'status' => 'DISABLED',
    'history' => ['ACTIVE', 3, 'DISABLED'],
])));

var_dump(king_proto_decode('Job', hex2bin('08021203010302')));
?>
--EXPECT--
bool(true)
bool(true)
string(14) "08021203010302"
array(2) {
  ["status"]=>
  int(2)
  ["history"]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    int(3)
    [2]=>
    int(2)
  }
}
