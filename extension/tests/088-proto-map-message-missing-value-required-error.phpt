--TEST--
King proto map<string, message> missing values preserve nested required-field decode errors
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));
var_dump(king_proto_define_schema('Catalog', [
    'children' => ['tag' => 1, 'type' => 'map<string,Child>'],
]));

try {
    king_proto_decode('Catalog', hex2bin('0a050a03666f6f'));
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
bool(true)
Decoding error: Required field 'id' (tag 1) not found in payload for schema 'Child'.
