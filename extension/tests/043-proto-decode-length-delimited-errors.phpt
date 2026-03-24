--TEST--
King proto decode raises stable length-delimited errors in the runtime primitive subset
--FILE--
<?php
var_dump(king_proto_define_schema('User', [
    'name' => ['tag' => 3, 'type' => 'string'],
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'enabled' => ['tag' => 2, 'type' => 'bool'],
]));

try {
    king_proto_decode('User', hex2bin('080110011a056b69'));
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_decode('User', hex2bin('080110011a80'));
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(true)
string(83) "Decoding error: length-delimited field 'name' exceeds buffer size in schema 'User'."
string(74) "Decoding error: malformed length prefix for field 'name' in schema 'User'."
