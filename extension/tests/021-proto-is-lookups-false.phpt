--TEST--
King proto registry lookup helpers return false in the skeleton build
--FILE--
<?php
var_dump(king_proto_is_defined('Example'));
var_dump(king_proto_is_schema_defined('ExampleSchema'));
var_dump(king_proto_is_enum_defined('ExampleEnum'));
?>
--EXPECT--
bool(false)
bool(false)
bool(false)
