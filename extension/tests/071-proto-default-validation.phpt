--TEST--
King proto schema defaults reject unsupported required repeated and invalid values
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));

try {
    king_proto_define_schema('BadRequired', [
        'id' => ['tag' => 1, 'type' => 'int32', 'required' => true, 'default' => 1],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadRepeated', [
        'ids' => ['tag' => 1, 'type' => 'repeated_int32', 'default' => [1]],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32'],
]));

try {
    king_proto_define_schema('BadMessage', [
        'child' => ['tag' => 1, 'type' => 'Child', 'default' => []],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadEnum', [
        'status' => ['tag' => 1, 'type' => 'Status', 'default' => 'UNKNOWN'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadString', [
        'label' => ['tag' => 1, 'type' => 'string', 'default' => []],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
Schema 'BadRequired': Field 'id' cannot set 'default' on required fields.
Schema 'BadRepeated': Field 'ids' cannot set 'default' on repeated fields.
bool(true)
Schema 'BadMessage': Field 'child' cannot use 'default' with type 'Child'.
Schema 'BadEnum': Field 'status' default enum 'Status' has no member named 'UNKNOWN'.
Schema 'BadString': Field 'label' default expects a string, but got array.
