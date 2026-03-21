--TEST--
King proto decode raises a stable schema-not-defined exception in the skeleton build
--FILE--
<?php
try {
    king_proto_decode('ExampleSchema', "\x01\x02", true);
    echo "no exception\n";
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECT--
string(48) "Schema 'ExampleSchema' not defined for decoding."
