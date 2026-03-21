--TEST--
King proto fixed-width and floating-point fields decode into stable PHP values
--FILE--
<?php
var_dump(king_proto_define_schema('Numbers', [
    'f32' => ['tag' => 1, 'type' => 'float'],
    'f64' => ['tag' => 2, 'type' => 'double'],
    'u32' => ['tag' => 3, 'type' => 'fixed32'],
    's32' => ['tag' => 4, 'type' => 'sfixed32'],
    'u64' => ['tag' => 5, 'type' => 'fixed64'],
    's64' => ['tag' => 6, 'type' => 'sfixed64'],
]));

$payload = hex2bin('0d0000c03f110000000000000a401d2a00000025f9ffffff29282300000000000031feffffffffffffff');
$decoded_array = king_proto_decode('Numbers', $payload);
var_dump($decoded_array);

$decoded_object = king_proto_decode('Numbers', $payload, true);
var_dump($decoded_object instanceof stdClass);
var_dump((array) $decoded_object);
?>
--EXPECT--
bool(true)
array(6) {
  ["f32"]=>
  float(1.5)
  ["f64"]=>
  float(3.25)
  ["u32"]=>
  int(42)
  ["s32"]=>
  int(-7)
  ["u64"]=>
  int(9000)
  ["s64"]=>
  int(-2)
}
bool(true)
array(6) {
  ["f32"]=>
  float(1.5)
  ["f64"]=>
  float(3.25)
  ["u32"]=>
  int(42)
  ["s32"]=>
  int(-7)
  ["u64"]=>
  int(9000)
  ["s64"]=>
  int(-2)
}
