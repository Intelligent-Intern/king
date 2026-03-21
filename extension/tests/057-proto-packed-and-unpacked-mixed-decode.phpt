--TEST--
King proto decode preserves order when repeated numeric fields mix unpacked and packed entries
--FILE--
<?php
var_dump(king_proto_define_schema('Batch', [
    'ids' => ['tag' => 1, 'type' => 'repeated_int32', 'required' => true],
]));

var_dump(king_proto_decode('Batch', hex2bin('08010a029601')));
var_dump(king_proto_decode('Batch', hex2bin('0a0201020803')));
?>
--EXPECT--
bool(true)
array(1) {
  ["ids"]=>
  array(2) {
    [0]=>
    int(1)
    [1]=>
    int(150)
  }
}
array(1) {
  ["ids"]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    int(2)
    [2]=>
    int(3)
  }
}
