--TEST--
King proto decode raises stable errors for malformed packed repeated payloads
--FILE--
<?php
var_dump(king_proto_define_schema('Batch', [
    'ids' => ['tag' => 1, 'type' => 'repeated_int32', 'required' => true],
]));

try {
    king_proto_decode('Batch', hex2bin('0a0196'));
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_decode('Batch', hex2bin('0a0296'));
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_decode('Batch', hex2bin('0a00'));
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(true)
string(73) "Decoding error: malformed varint value for field 'ids' in schema 'Batch'."
string(83) "Decoding error: length-delimited field 'ids' exceeds buffer size in schema 'Batch'."
string(85) "Decoding error: Required field 'ids' (tag 1) not found in payload for schema 'Batch'."
