--TEST--
King proto encode rejects out-of-range integer values in the skeleton primitive subset
--FILE--
<?php
var_dump(king_proto_define_schema('I32', [
    'count' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));
var_dump(king_proto_define_schema('S32', [
    'delta' => ['tag' => 1, 'type' => 'sint32', 'required' => true],
]));
var_dump(king_proto_define_schema('U32', [
    'count' => ['tag' => 1, 'type' => 'uint32', 'required' => true],
]));
var_dump(king_proto_define_schema('U64', [
    'count' => ['tag' => 1, 'type' => 'uint64', 'required' => true],
]));

var_dump(bin2hex(king_proto_encode('U32', ['count' => 4294967295])));

try {
    king_proto_encode('I32', ['count' => 2147483648]);
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_encode('S32', ['delta' => -2147483649]);
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_encode('U32', ['count' => -1]);
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_encode('U32', ['count' => 4294967296]);
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_encode('U64', ['count' => -1]);
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
string(12) "08ffffffff0f"
string(83) "Encoding failed: Field 'count' expects a 32-bit signed integer, but got 2147483648."
string(84) "Encoding failed: Field 'delta' expects a 32-bit signed integer, but got -2147483649."
string(78) "Encoding failed: Field 'count' expects an unsigned 32-bit integer, but got -1."
string(86) "Encoding failed: Field 'count' expects an unsigned 32-bit integer, but got 4294967296."
string(78) "Encoding failed: Field 'count' expects an unsigned 64-bit integer, but got -1."
