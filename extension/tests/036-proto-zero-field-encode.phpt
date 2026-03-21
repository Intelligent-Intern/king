--TEST--
King proto zero-field schemas encode empty payloads but reject unexpected fields
--FILE--
<?php
var_dump(king_proto_define_schema('EmptyMessage', []));
var_dump(king_proto_encode('EmptyMessage', []));

try {
    king_proto_encode('EmptyMessage', (object) ['ignored' => true]);
} catch (King\ValidationException $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_encode('EmptyMessage', 123);
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(true)
string(0) ""
string(79) "Encoding failed: Schema 'EmptyMessage' does not define a field named 'ignored'."
string(64) "Data for message type 'EmptyMessage' must be an array or object."
