--TEST--
King proto sint32 schemas zigzag-encode and decode signed values in the skeleton build
--FILE--
<?php
var_dump(king_proto_define_schema('SignedMessage', [
    'delta' => ['tag' => 1, 'type' => 'sint32', 'required' => true],
]));

var_dump(bin2hex(king_proto_encode('SignedMessage', [
    'delta' => -7,
])));

var_dump(bin2hex(king_proto_encode('SignedMessage', (object) [
    'delta' => 9,
])));

var_dump(king_proto_decode('SignedMessage', hex2bin('080d')));

try {
    king_proto_decode('SignedMessage', '');
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECTF--
bool(true)
string(4) "080d"
string(4) "0812"
array(1) {
  ["delta"]=>
  int(-7)
}
string(%d) "Decoding error: Required field 'delta' (tag 1) not found in payload for schema 'SignedMessage'."
