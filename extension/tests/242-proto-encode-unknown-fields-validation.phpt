--TEST--
King proto encode rejects unexpected top-level array and object fields via compiled schema validation
--FILE--
<?php
var_dump(king_proto_define_schema('UserValidateTopLevel', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'name' => ['tag' => 2, 'type' => 'string'],
]));

$payloads = [
    ['id' => 7, 'ghost' => 9],
    (object) ['id' => 7, 'ghost' => 9],
    [0 => 7, 'id' => 7],
];

foreach ($payloads as $payload) {
    try {
        king_proto_encode('UserValidateTopLevel', $payload);
        echo "NO-ERROR\n";
    } catch (Throwable $e) {
        echo get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
bool(true)
King\ValidationException
Encoding failed: Schema 'UserValidateTopLevel' does not define a field named 'ghost'.
King\ValidationException
Encoding failed: Schema 'UserValidateTopLevel' does not define a field named 'ghost'.
King\ValidationException
Encoding failed: Schema 'UserValidateTopLevel' received unexpected numeric field key 0.
