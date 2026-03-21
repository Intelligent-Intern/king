--TEST--
King proto map<string, scalar|enum|message> encode validates containers keys and values
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));
var_dump(king_proto_define_schema('Catalog', [
    'labels' => ['tag' => 1, 'type' => 'map<string,string>'],
    'counts' => ['tag' => 2, 'type' => 'map<string,int32>'],
    'states' => ['tag' => 3, 'type' => 'map<string,Status>'],
    'children' => ['tag' => 4, 'type' => 'map<string,Child>'],
]));

try {
    king_proto_encode('Catalog', [
        'labels' => 'nope',
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_encode('Catalog', [
        'labels' => [1 => 'prod'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_encode('Catalog', [
        'counts' => ['ok' => 'bad'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_encode('Catalog', [
        'states' => ['primary' => 'UNKNOWN'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_encode('Catalog', [
        'children' => ['primary' => 'bad'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_encode('Catalog', [
        'children' => ['primary' => []],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
Encoding failed: Field 'labels' expects an array or object for map values, but got string.
Encoding failed: Field 'labels' expects string map keys, but found integer key 1.
Encoding failed: Field 'counts' expects an integer, but got string.
Encoding failed: Field 'states' enum 'Status' has no member named 'UNKNOWN'.
Encoding failed: Field 'children' expects a message object or array, but got string.
Encoding failed: Required field 'id' (tag 1) is missing or null.
