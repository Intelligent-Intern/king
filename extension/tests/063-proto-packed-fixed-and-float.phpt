--TEST--
King proto packed field opt-in also works for floating-point and fixed-width repeated fields
--FILE--
<?php
var_dump(king_proto_define_schema('PackedNums', [
    'fs' => ['tag' => 1, 'type' => 'repeated_float', 'packed' => true],
    'u32s' => ['tag' => 2, 'type' => 'repeated_fixed32', 'packed' => true],
    'ds' => ['tag' => 3, 'type' => 'repeated_double', 'packed' => true],
]));

$encoded = king_proto_encode('PackedNums', [
    'fs' => [1.5, 2.0],
    'u32s' => [42, 7],
    'ds' => [3.25, 4.5],
]);

var_dump(bin2hex($encoded));
var_dump(king_proto_decode('PackedNums', $encoded));
?>
--EXPECT--
bool(true)
string(76) "0a080000c03f0000004012082a000000070000001a100000000000000a400000000000001240"
array(3) {
  ["fs"]=>
  array(2) {
    [0]=>
    float(1.5)
    [1]=>
    float(2)
  }
  ["u32s"]=>
  array(2) {
    [0]=>
    int(42)
    [1]=>
    int(7)
  }
  ["ds"]=>
  array(2) {
    [0]=>
    float(3.25)
    [1]=>
    float(4.5)
  }
}
