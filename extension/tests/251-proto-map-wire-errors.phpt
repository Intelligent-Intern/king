--TEST--
King proto decode raises stable wire errors for malformed map entries
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32'],
]));
var_dump(king_proto_define_schema('Catalog', [
    'children' => ['tag' => 1, 'type' => 'map<string,Child>'],
]));

foreach ([
    '0a020801',
    '0a050a01611001',
    '0a0100',
    '0a011b',
] as $hex) {
    try {
        king_proto_decode('Catalog', hex2bin($hex));
    } catch (King\Exception $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
bool(true)
bool(true)
Schema 'Catalog': Wire type mismatch for map field 'children' entry key. Expected wire type 2, but got 0 on the wire.
Schema 'Catalog': Wire type mismatch for map field 'children' entry value. Expected wire type 2, but got 0 on the wire.
Decoding error: malformed tag/wire_type varint in map field 'children' for schema 'Catalog'.
Decoding error: failed to skip unknown map-entry field with tag 3 for field 'children' in schema 'Catalog'.
