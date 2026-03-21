--TEST--
King proto decode payload and wire validation paths expose ValidationException
--FILE--
<?php
$results = [];

king_proto_define_schema('User', [
    'name' => ['tag' => 3, 'type' => 'string'],
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'enabled' => ['tag' => 2, 'type' => 'bool'],
]);

foreach ([
    'malformed_tag' => "\x80",
    'required_missing' => hex2bin('10011a046b696e67'),
    'wire_mismatch' => hex2bin('08960110011801'),
] as $label => $payload) {
    try {
        king_proto_decode('User', $payload);
    } catch (Throwable $e) {
        $results[$label] = [
            'class' => get_class($e),
            'is_validation' => $e instanceof King\ValidationException,
            'is_base' => $e instanceof King\Exception,
        ];
    }
}

var_export($results);
echo "\n";
?>
--EXPECT--
array (
  'malformed_tag' => 
  array (
    'class' => 'King\\ValidationException',
    'is_validation' => true,
    'is_base' => true,
  ),
  'required_missing' => 
  array (
    'class' => 'King\\ValidationException',
    'is_validation' => true,
    'is_base' => true,
  ),
  'wire_mismatch' => 
  array (
    'class' => 'King\\ValidationException',
    'is_validation' => true,
    'is_base' => true,
  ),
)
