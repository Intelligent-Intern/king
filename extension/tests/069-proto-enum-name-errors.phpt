--TEST--
King proto enum name encode rejects unknown enum members and invalid value types
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));
var_dump(king_proto_define_schema('Job', [
    'status' => ['tag' => 1, 'type' => 'Status', 'required' => true],
]));

try {
    king_proto_encode('Job', [
        'status' => 'UNKNOWN',
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_encode('Job', [
        'status' => [],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
bool(true)
Encoding failed: Field 'status' enum 'Status' has no member named 'UNKNOWN'.
Encoding failed: Field 'status' expects an integer or enum member name string, but got array.
