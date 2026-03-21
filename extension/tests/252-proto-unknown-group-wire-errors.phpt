--TEST--
King proto decode rejects unsupported group wire types in unknown fields
--FILE--
<?php
var_dump(king_proto_define_schema('User', [
    'id' => ['tag' => 1, 'type' => 'int32'],
]));
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32'],
]));
var_dump(king_proto_define_schema('Parent', [
    'child' => ['tag' => 1, 'type' => 'Child'],
]));

foreach ([
    ['User', '1b'],
    ['Parent', '0a011b'],
] as [$schema, $hex]) {
    try {
        king_proto_decode($schema, hex2bin($hex));
    } catch (King\Exception $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
Decoding error: failed to skip unknown field with tag 3 in schema 'User'.
Decoding error: failed to skip unknown field with tag 3 in schema 'Child'.
