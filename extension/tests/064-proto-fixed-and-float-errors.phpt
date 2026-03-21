--TEST--
King proto fixed-width and floating-point fields raise stable encode and decode errors
--FILE--
<?php
var_dump(king_proto_define_schema('FloatErrors', [
    'f' => ['tag' => 1, 'type' => 'float'],
    'u32' => ['tag' => 2, 'type' => 'fixed32'],
    's32' => ['tag' => 3, 'type' => 'sfixed32'],
    'u64' => ['tag' => 4, 'type' => 'fixed64'],
]));

foreach ([
    ['f' => 'bad'],
    ['u32' => -1],
    ['s32' => 2147483648],
    ['u64' => -1],
] as $payload) {
    try {
        king_proto_encode('FloatErrors', $payload);
    } catch (King\Exception $e) {
        var_dump($e->getMessage());
    }
}

var_dump(king_proto_define_schema('DecodeFixed64', [
    'value' => ['tag' => 1, 'type' => 'fixed64'],
]));

foreach ([
    '09ffffffffffffffff',
    '0d01020304',
] as $hex) {
    try {
        king_proto_decode('DecodeFixed64', hex2bin($hex));
    } catch (King\Exception $e) {
        var_dump($e->getMessage());
    }
}
?>
--EXPECT--
bool(true)
string(70) "Encoding failed: Field 'f' expects a float or integer, but got string."
string(82) "Encoding failed: Field 'u32' expects an unsigned 32-bit fixed integer, but got -1."
string(87) "Encoding failed: Field 's32' expects a 32-bit signed fixed integer, but got 2147483648."
string(82) "Encoding failed: Field 'u64' expects an unsigned 64-bit fixed integer, but got -1."
bool(true)
string(94) "Decoding error: Field 'value' exceeds PHP integer range for fixed64 in schema 'DecodeFixed64'."
string(114) "Schema 'DecodeFixed64': Wire type mismatch for field 'value' (tag 1). Expected wire type 1, but got 5 on the wire."
