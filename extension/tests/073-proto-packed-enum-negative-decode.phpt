--TEST--
King proto packed repeated enum decode preserves negative int32 values
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'NEG' => -1,
    'ACTIVE' => 1,
]));
var_dump(king_proto_define_schema('Job', [
    'history' => ['tag' => 1, 'type' => 'repeated_Status', 'packed' => true],
]));

var_dump(king_proto_decode('Job', hex2bin('0a0bffffffffffffffffff0101')));
?>
--EXPECT--
bool(true)
bool(true)
array(1) {
  ["history"]=>
  array(2) {
    [0]=>
    int(-1)
    [1]=>
    int(1)
  }
}
