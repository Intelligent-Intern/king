--TEST--
King IIBIN static class mirrors the active proto runtime and registry
--FILE--
<?php
$class = new ReflectionClass('King\\IIBIN');
$methods = [];

foreach ([
    'defineEnum',
    'defineSchema',
    'encode',
    'decode',
    'isDefined',
    'isSchemaDefined',
    'isEnumDefined',
    'getDefinedSchemas',
    'getDefinedEnums',
] as $method) {
    $methods[$method] = $class->getMethod($method)->isStatic();
}

$enumName = 'IIBINStatus237';
$schemaName = 'IIBINJob237';

var_export([
    'internal' => $class->isInternal(),
    'final' => $class->isFinal(),
    'methods' => $methods,
    'define_enum' => King\IIBIN::defineEnum($enumName, [
        'ACTIVE' => 1,
        'PAUSED' => 2,
    ]),
    'define_schema' => King\IIBIN::defineSchema($schemaName, [
        'id' => ['type' => 'int32', 'tag' => 1],
        'status' => ['type' => $enumName, 'tag' => 2, 'optional' => true],
    ]),
    'predicates' => [
        'defined' => King\IIBIN::isDefined($schemaName),
        'schema' => King\IIBIN::isSchemaDefined($schemaName),
        'enum' => King\IIBIN::isEnumDefined($enumName),
        'schemas' => King\IIBIN::getDefinedSchemas(),
        'enums' => King\IIBIN::getDefinedEnums(),
    ],
    'encoded' => bin2hex(King\IIBIN::encode($schemaName, [
        'id' => 7,
        'status' => 'ACTIVE',
    ])),
    'decoded_array' => King\IIBIN::decode($schemaName, hex2bin('08071001')),
    'decoded_object_class' => get_class(King\IIBIN::decode($schemaName, hex2bin('08071001'), true)),
    'decoded_object_id' => King\IIBIN::decode($schemaName, hex2bin('08071001'), true)->id,
    'decoded_object_status' => King\IIBIN::decode($schemaName, hex2bin('08071001'), true)->status,
]);
echo "\n";
?>
--EXPECT--
array (
  'internal' => true,
  'final' => true,
  'methods' => 
  array (
    'defineEnum' => true,
    'defineSchema' => true,
    'encode' => true,
    'decode' => true,
    'isDefined' => true,
    'isSchemaDefined' => true,
    'isEnumDefined' => true,
    'getDefinedSchemas' => true,
    'getDefinedEnums' => true,
  ),
  'define_enum' => true,
  'define_schema' => true,
  'predicates' => 
  array (
    'defined' => true,
    'schema' => true,
    'enum' => true,
    'schemas' => 
    array (
      0 => 'IIBINJob237',
    ),
    'enums' => 
    array (
      0 => 'IIBINStatus237',
    ),
  ),
  'encoded' => '08071001',
  'decoded_array' => 
  array (
    'id' => 7,
    'status' => 1,
  ),
  'decoded_object_class' => 'stdClass',
  'decoded_object_id' => 7,
  'decoded_object_status' => 1,
)
