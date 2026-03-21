--TEST--
King proto decode rejects out-of-range integer payloads in the skeleton primitive subset
--FILE--
<?php
var_dump(king_proto_define_schema('I32', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));
var_dump(king_proto_define_schema('U32', [
    'count' => ['tag' => 1, 'type' => 'uint32', 'required' => true],
]));
var_dump(king_proto_define_schema('S32', [
    'delta' => ['tag' => 1, 'type' => 'sint32', 'required' => true],
]));
var_dump(king_proto_define_schema('U64', [
    'count' => ['tag' => 1, 'type' => 'uint64', 'required' => true],
]));

var_dump(king_proto_decode('U32', hex2bin('08ffffffff0f')));

try {
    king_proto_decode('I32', hex2bin('088080808010'));
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_decode('U32', hex2bin('088080808010'));
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_decode('S32', hex2bin('088080808010'));
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_decode('U64', hex2bin('0880808080808080808001'));
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
array(1) {
  ["count"]=>
  int(4294967295)
}
string(63) "Decoding error: Field 'id' exceeds int32 range in schema 'I32'."
string(67) "Decoding error: Field 'count' exceeds uint32 range in schema 'U32'."
string(67) "Decoding error: Field 'delta' exceeds sint32 range in schema 'S32'."
string(83) "Decoding error: Field 'count' exceeds PHP integer range for uint64 in schema 'U64'."
