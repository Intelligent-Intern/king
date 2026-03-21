--TEST--
King proto zero-field schemas decode empty and unknown-field payloads in the skeleton build
--FILE--
<?php
var_dump(king_proto_define_schema('EmptyMessage', []));

$decoded_array = king_proto_decode('EmptyMessage', '');
var_dump($decoded_array);

$decoded_object = king_proto_decode('EmptyMessage', '', true);
var_dump($decoded_object instanceof stdClass);
var_dump((array) $decoded_object);

var_dump(king_proto_decode('EmptyMessage', "\x08\x01"));

try {
    king_proto_decode('EmptyMessage', "\x80");
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(true)
array(0) {
}
bool(true)
array(0) {
}
array(0) {
}
string(72) "Decoding error: malformed tag/wire_type varint in schema 'EmptyMessage'."
