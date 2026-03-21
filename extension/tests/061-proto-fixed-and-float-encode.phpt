--TEST--
King proto fixed-width and floating-point fields encode in canonical tag order
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

var_dump(bin2hex(king_proto_encode('Numbers', [
    'f32' => 1.5,
    'f64' => 3.25,
    'u32' => 42,
    's32' => -7,
    'u64' => 9000,
    's64' => -2,
])));
?>
--EXPECT--
bool(true)
string(84) "0d0000c03f110000000000000a401d2a00000025f9ffffff29282300000000000031feffffffffffffff"
