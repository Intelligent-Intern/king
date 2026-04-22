--TEST--
King proto decode raises stable errors for malformed packed fixed-width payloads
--FILE--
<?php
var_dump(king_proto_define_schema('Floats', [
    'fs' => ['tag' => 1, 'type' => 'repeated_float', 'packed' => true],
    'ds' => ['tag' => 2, 'type' => 'repeated_double', 'packed' => true],
]));

foreach ([
    '0a03000080',
    '120700000000000000',
] as $hex) {
    try {
        king_proto_decode('Floats', hex2bin($hex));
    } catch (King\Exception $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
bool(true)
Decoding error: malformed fixed32 value for field 'fs' in schema 'Floats'.
Decoding error: malformed fixed64 value for field 'ds' in schema 'Floats'.
