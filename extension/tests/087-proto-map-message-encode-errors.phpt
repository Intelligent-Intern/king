--TEST--
King proto map<string,Message> encode validates nested message payloads
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'name' => ['tag' => 2, 'type' => 'string'],
]));
var_dump(king_proto_define_schema('Catalog', [
    'children' => ['tag' => 1, 'type' => 'map<string,Child>'],
]));

try {
    king_proto_encode('Catalog', [
        'children' => ['primary' => 'bad'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_encode('Catalog', [
        'children' => ['primary' => ['name' => 'api']],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
bool(true)
Encoding failed: Field 'children' expects a message object or array, but got string.
Encoding failed: Required field 'id' (tag 1) is missing or null.
