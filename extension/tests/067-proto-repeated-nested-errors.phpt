--TEST--
King proto repeated nested message fields preserve repeated, child-required, and packed guards
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));
var_dump(king_proto_define_schema('Parent', [
    'children' => ['tag' => 1, 'type' => 'repeated_Child', 'required' => true],
]));

try {
    king_proto_encode('Parent', [
        'children' => [],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_encode('Parent', [
        'children' => ['bad'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_decode('Parent', hex2bin('0a00'));
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadParent', [
        'children' => ['tag' => 1, 'type' => 'repeated_Child', 'packed' => true],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
bool(true)
Encoding failed: Required repeated field 'children' (tag 1) must contain at least one item.
Encoding failed: Field 'children' expects a message object or array, but got string.
Decoding error: Required field 'id' (tag 1) not found in payload for schema 'Child'.
Schema 'BadParent': Field 'children' cannot use 'packed' with type 'repeated_Child'.
