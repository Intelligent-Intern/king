--TEST--
King proto encode raises a stable schema-not-defined exception in the skeleton build
--FILE--
<?php
try {
    king_proto_encode('ExampleSchema', ['id' => 1]);
    echo "no exception\n";
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECT--
string(48) "Schema 'ExampleSchema' not defined for encoding."
