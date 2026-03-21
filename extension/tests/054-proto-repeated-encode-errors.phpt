--TEST--
King proto repeated fields enforce array shape and per-item validation
--FILE--
<?php
var_dump(king_proto_define_schema('Batch', [
    'ids' => ['tag' => 1, 'type' => 'repeated_int32', 'required' => true],
]));

try {
    king_proto_encode('Batch', [
        'ids' => [],
    ]);
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_encode('Batch', [
        'ids' => 'nope',
    ]);
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_encode('Batch', [
        'ids' => [1, 'bad'],
    ]);
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(true)
string(86) "Encoding failed: Required repeated field 'ids' (tag 1) must contain at least one item."
string(82) "Encoding failed: Field 'ids' expects an array for repeated values, but got string."
string(64) "Encoding failed: Field 'ids' expects an integer, but got string."
