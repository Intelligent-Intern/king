--TEST--
King proto map<key, scalar|enum|message> schema validation rejects unsupported combinations
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32'],
]));

try {
    king_proto_define_schema('BadRequired', [
        'labels' => ['tag' => 1, 'type' => 'map<string,string>', 'required' => true],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadRepeated', [
        'labels' => ['tag' => 1, 'type' => 'repeated_map<string,string>'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadKey', [
        'labels' => ['tag' => 1, 'type' => 'map<int64,string>'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadValue', [
        'children' => ['tag' => 1, 'type' => 'map<string,map<string,string>>'],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadPacked', [
        'labels' => ['tag' => 1, 'type' => 'map<string,string>', 'packed' => true],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    king_proto_define_schema('BadDefault', [
        'labels' => ['tag' => 1, 'type' => 'map<string,string>', 'default' => []],
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
Schema 'BadRequired': Field 'labels' cannot mark map fields as 'required'.
Schema 'BadRepeated': Field 'labels' cannot use 'repeated_' with map types.
Schema 'BadKey': Field 'labels' map key type 'int64' is not supported; only 'string', 'bool', and 32-bit integer key types are allowed.
Schema 'BadValue': Field 'children' map value type 'map<string,string>' is not a primitive, defined message, or defined enum.
Schema 'BadPacked': Field 'labels' cannot use 'packed' with type 'map<string,string>'.
Schema 'BadDefault': Field 'labels' cannot set 'default' on map fields.
