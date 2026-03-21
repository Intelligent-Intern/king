--TEST--
King proto validation paths expose ValidationException instead of generic base exceptions
--FILE--
<?php
$results = [];

try {
    king_proto_define_schema('Envelope', [
        'id' => ['tag' => 1, 'type' => 'int32', 'oneof' => 'payload'],
        'label' => ['tag' => 2, 'type' => 'string', 'oneof' => 'payload'],
    ]);
    king_proto_encode('Envelope', [
        'id' => 7,
        'label' => 'king',
    ]);
} catch (Throwable $e) {
    $results['encode_oneof'] = [
        'class' => get_class($e),
        'is_validation' => $e instanceof King\ValidationException,
        'is_base' => $e instanceof King\Exception,
    ];
}

try {
    king_proto_define_schema('I32', [
        'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    ]);
    king_proto_decode('I32', hex2bin('088080808010'));
} catch (Throwable $e) {
    $results['decode_range'] = [
        'class' => get_class($e),
        'is_validation' => $e instanceof King\ValidationException,
        'is_base' => $e instanceof King\Exception,
    ];
}

try {
    king_proto_define_schema('BadDefault', [
        'label' => ['tag' => 1, 'type' => 'string', 'default' => []],
    ]);
} catch (Throwable $e) {
    $results['schema_default'] = [
        'class' => get_class($e),
        'is_validation' => $e instanceof King\ValidationException,
        'is_base' => $e instanceof King\Exception,
    ];
}

var_export($results);
echo "\n";
?>
--EXPECT--
array (
  'encode_oneof' => 
  array (
    'class' => 'King\\ValidationException',
    'is_validation' => true,
    'is_base' => true,
  ),
  'decode_range' => 
  array (
    'class' => 'King\\ValidationException',
    'is_validation' => true,
    'is_base' => true,
  ),
  'schema_default' => 
  array (
    'class' => 'King\\ValidationException',
    'is_validation' => true,
    'is_base' => true,
  ),
)
