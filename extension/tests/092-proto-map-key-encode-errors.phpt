--TEST--
King proto map bool and 32-bit integer keys validate container keys during encode
--FILE--
<?php
var_dump(king_proto_define_schema('Catalog', [
    'labels' => ['tag' => 1, 'type' => 'map<int32,string>'],
    'flags' => ['tag' => 2, 'type' => 'map<bool,string>'],
    'children' => ['tag' => 3, 'type' => 'map<uint32,string>'],
]));

try {
    king_proto_encode('Catalog', [
        'labels' => (object) ['nope' => 'x'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_encode('Catalog', [
        'flags' => [2 => 'hot'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_encode('Catalog', [
        'children' => [-1 => 'bad'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
Encoding failed: Field 'labels' expects int32-compatible map keys, but got string key 'nope'.
Encoding failed: Field 'flags' expects bool map keys 0 or 1, but got 2.
Encoding failed: Field 'children' expects an unsigned 32-bit map key, but got -1.
