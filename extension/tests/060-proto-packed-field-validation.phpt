--TEST--
King proto packed field option validates flag type and supported field shapes
--FILE--
<?php
foreach ([
    ['BadFlag', ['id' => ['tag' => 1, 'type' => 'repeated_int32', 'packed' => 'yes']]],
    ['NonRepeated', ['id' => ['tag' => 1, 'type' => 'int32', 'packed' => true]]],
    ['BadType', ['name' => ['tag' => 1, 'type' => 'repeated_string', 'packed' => true]]],
] as [$schema_name, $schema_definition]) {
    try {
        king_proto_define_schema($schema_name, $schema_definition);
    } catch (King\Exception $e) {
        var_dump($e->getMessage());
    }
}
?>
--EXPECT--
string(55) "Schema 'BadFlag': Field 'id' has invalid 'packed' flag."
string(74) "Schema 'NonRepeated': Field 'id' can only set 'packed' on repeated fields."
string(79) "Schema 'BadType': Field 'name' cannot use 'packed' with type 'repeated_string'."
