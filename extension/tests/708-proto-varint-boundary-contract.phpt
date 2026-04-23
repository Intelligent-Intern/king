--TEST--
King proto varint helpers preserve canonical boundaries and reject uint64 overflow
--FILE--
<?php
var_dump(king_proto_define_schema('VarintU32Q13', [
    'value' => ['tag' => 1, 'type' => 'uint32', 'required' => true],
]));
var_dump(king_proto_define_schema('VarintU64Q13', [
    'value' => ['tag' => 1, 'type' => 'uint64', 'required' => true],
]));

$u32Cases = [
    0,
    1,
    127,
    128,
    16383,
    16384,
    2097151,
    2097152,
    268435455,
    268435456,
    4294967295,
];
foreach ($u32Cases as $value) {
    echo $value, ':', bin2hex(king_proto_encode('VarintU32Q13', ['value' => $value])), "\n";
}

$max = PHP_INT_MAX;
echo 'PHP_INT_MAX:', bin2hex(king_proto_encode('VarintU64Q13', ['value' => $max])), "\n";
var_dump(king_proto_decode('VarintU64Q13', hex2bin('08ffffffffffffffff7f')));

foreach (['0880808080808080808002', '08808080808080808080'] as $payload) {
    try {
        king_proto_decode('VarintU64Q13', hex2bin($payload));
    } catch (King\Exception $e) {
        var_dump($e->getMessage());
    }
}
?>
--EXPECT--
bool(true)
bool(true)
0:0800
1:0801
127:087f
128:088001
16383:08ff7f
16384:08808001
2097151:08ffff7f
2097152:0880808001
268435455:08ffffff7f
268435456:088080808001
4294967295:08ffffffff0f
PHP_INT_MAX:08ffffffffffffffff7f
array(1) {
  ["value"]=>
  int(9223372036854775807)
}
string(82) "Decoding error: malformed varint value for field 'value' in schema 'VarintU64Q13'."
