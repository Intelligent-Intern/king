--TEST--
King IIBIN schema compiler validation rejects invalid schemas without leaking partial registry state
--FILE--
<?php
$childName = 'IIBINChild241';
$badName = 'IIBINBad241';

var_dump(King\IIBIN::defineSchema($childName, [
    'id' => ['tag' => 1, 'type' => 'int32'],
]));

var_export([
    'before_defined' => King\IIBIN::isSchemaDefined($badName),
    'before_inventory' => King\IIBIN::getDefinedSchemas(),
]);
echo "\n";

try {
    King\IIBIN::defineSchema($badName, [
        'children' => ['tag' => 1, 'type' => 'repeated_' . $childName, 'packed' => true],
    ]);
} catch (King\ValidationException $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}

var_export([
    'after_defined' => King\IIBIN::isSchemaDefined($badName),
    'after_inventory' => King\IIBIN::getDefinedSchemas(),
    'procedural_defined' => king_proto_is_schema_defined($badName),
]);
echo "\n";
?>
--EXPECT--
bool(true)
array (
  'before_defined' => false,
  'before_inventory' => 
  array (
    0 => 'IIBINChild241',
  ),
)
King\ValidationException
Schema 'IIBINBad241': Field 'children' cannot use 'packed' with type 'repeated_IIBINChild241'.
array (
  'after_defined' => false,
  'after_inventory' => 
  array (
    0 => 'IIBINChild241',
  ),
  'procedural_defined' => false,
)
