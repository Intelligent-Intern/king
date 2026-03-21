--TEST--
King proto encode rejects unexpected nested message fields via the compiled schema cache
--FILE--
<?php
var_dump(king_proto_define_schema('ChildValidateNested', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));
var_dump(king_proto_define_schema('ParentValidateNested', [
    'child' => ['tag' => 1, 'type' => 'ChildValidateNested', 'required' => true],
]));

try {
    king_proto_encode('ParentValidateNested', [
        'child' => [
            'id' => 7,
            'ghost' => 9,
        ],
    ]);
    echo "NO-ERROR\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
bool(true)
King\ValidationException
Encoding failed: Schema 'ChildValidateNested' does not define a field named 'ghost'.
