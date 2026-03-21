--TEST--
King proto decode and King IIBIN decode expose the widened decode_as_object union contract
--FILE--
<?php
function describe_parameter(ReflectionFunctionAbstract $function, string $name): array
{
    $parameter = $function->getParameters()[2];
    $type = $parameter->getType();
    $types = [];

    if ($type instanceof ReflectionUnionType) {
        foreach ($type->getTypes() as $namedType) {
            $types[] = $namedType->getName();
        }
        sort($types);
    } elseif ($type instanceof ReflectionNamedType) {
        $types[] = $type->getName();
    }

    return [
        'name' => $name,
        'required' => $function->getNumberOfRequiredParameters(),
        'total' => $function->getNumberOfParameters(),
        'parameter_name' => $parameter->getName(),
        'types' => $types,
        'optional' => $parameter->isOptional(),
        'default' => var_export($parameter->getDefaultValue(), true),
    ];
}

var_export([
    describe_parameter(new ReflectionFunction('king_proto_decode'), 'king_proto_decode'),
    describe_parameter(new ReflectionMethod('King\\IIBIN', 'decode'), 'King\\IIBIN::decode'),
]);
echo "\n";
?>
--EXPECT--
array (
  0 => 
  array (
    'name' => 'king_proto_decode',
    'required' => 2,
    'total' => 3,
    'parameter_name' => 'decode_as_object',
    'types' => 
    array (
      0 => 'array',
      1 => 'bool',
      2 => 'string',
    ),
    'optional' => true,
    'default' => 'false',
  ),
  1 => 
  array (
    'name' => 'King\\IIBIN::decode',
    'required' => 2,
    'total' => 3,
    'parameter_name' => 'decodeAsObject',
    'types' => 
    array (
      0 => 'array',
      1 => 'bool',
      2 => 'string',
    ),
    'optional' => true,
    'default' => 'false',
  ),
)
