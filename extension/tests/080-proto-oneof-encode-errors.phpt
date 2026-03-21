--TEST--
King proto oneof encode rejects payloads that set multiple fields from the same group
--FILE--
<?php
var_dump(king_proto_define_schema('Envelope', [
    'id' => ['tag' => 1, 'type' => 'int32', 'oneof' => 'payload'],
    'label' => ['tag' => 2, 'type' => 'string', 'oneof' => 'payload'],
]));

try {
    king_proto_encode('Envelope', [
        'id' => 7,
        'label' => 'king',
    ]);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
Encoding failed: oneof group 'payload' in schema 'Envelope' has multiple fields set ('id' and 'label').
