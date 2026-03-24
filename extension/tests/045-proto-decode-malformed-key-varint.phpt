--TEST--
King proto decode raises a stable malformed key error in the runtime primitive subset
--FILE--
<?php
var_dump(king_proto_define_schema('User', [
    'name' => ['tag' => 3, 'type' => 'string'],
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'enabled' => ['tag' => 2, 'type' => 'bool'],
]));

try {
    king_proto_decode('User', "\x80");
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(true)
string(64) "Decoding error: malformed tag/wire_type varint in schema 'User'."
