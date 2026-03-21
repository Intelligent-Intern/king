--TEST--
King proto nested message errors preserve child-schema required checks and parent wire checks
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));
var_dump(king_proto_define_schema('Parent', [
    'child' => ['tag' => 1, 'type' => 'Child', 'required' => true],
]));

try {
    king_proto_decode('Parent', hex2bin('0a00'));
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_decode('Parent', hex2bin('0801'));
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
bool(true)
Decoding error: Required field 'id' (tag 1) not found in payload for schema 'Child'.
Schema 'Parent': Wire type mismatch for field 'child' (tag 1). Expected wire type 2, but got 0 on the wire.
