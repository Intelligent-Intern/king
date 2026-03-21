--TEST--
King proto oneof schema validation rejects invalid flag and unsupported combinations
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32'],
]));

try {
    king_proto_define_schema('BadFlag', [
        'id' => ['tag' => 1, 'type' => 'int32', 'oneof' => true],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadRepeated', [
        'ids' => ['tag' => 1, 'type' => 'repeated_int32', 'oneof' => 'payload'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadRequired', [
        'id' => ['tag' => 1, 'type' => 'int32', 'oneof' => 'payload', 'required' => true],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadDefault', [
        'id' => ['tag' => 1, 'type' => 'int32', 'oneof' => 'payload', 'default' => 1],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadMap', [
        'labels' => ['tag' => 1, 'type' => 'map<string,string>', 'oneof' => 'payload'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
Schema 'BadFlag': Field 'id' has invalid 'oneof' group.
Schema 'BadRepeated': Field 'ids' cannot use 'oneof' with repeated fields.
Schema 'BadRequired': Field 'id' cannot mark oneof fields as 'required'.
Schema 'BadDefault': Field 'id' cannot set 'default' on oneof fields.
Schema 'BadMap': Field 'labels' cannot use 'oneof' with map fields.
