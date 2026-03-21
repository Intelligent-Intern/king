--TEST--
King proto primitive schemas encode int32/bool/string fields in canonical tag order
--FILE--
<?php
var_dump(king_proto_define_schema('User', [
    'name' => ['tag' => 3, 'type' => 'string'],
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'enabled' => ['tag' => 2, 'type' => 'bool'],
]));

var_dump(bin2hex(king_proto_encode('User', [
    'name' => 'king',
    'enabled' => true,
    'id' => 150,
])));

var_dump(bin2hex(king_proto_encode('User', (object) [
    'name' => 'ok',
    'id' => 7,
])));

try {
    king_proto_encode('User', [
        'enabled' => true,
        'name' => 'king',
    ]);
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_encode('User', [
        'id' => 1,
        'enabled' => 'yes',
        'name' => 'king',
    ]);
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(true)
string(22) "08960110011a046b696e67"
string(12) "08071a026f6b"
string(64) "Encoding failed: Required field 'id' (tag 1) is missing or null."
string(67) "Encoding failed: Field 'enabled' expects a boolean, but got string."
