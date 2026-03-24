--TEST--
King proto enum definitions populate the active runtime registry
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));
var_dump(king_proto_is_defined('Status'));
var_dump(king_proto_is_enum_defined('Status'));
var_dump(king_proto_is_schema_defined('Status'));
var_dump(king_proto_get_defined_enums());

try {
    king_proto_define_schema('Status', []);
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
bool(false)
array(1) {
  [0]=>
  string(6) "Status"
}
string(%d) "Schema or Enum name 'Status' already defined."
