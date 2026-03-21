--TEST--
King proto decode validates decode_as_object class targets and hydration failures
--FILE--
<?php
abstract class ProtoAbstract247
{
}

final class ProtoCtor247
{
    public int $id;

    public function __construct()
    {
    }
}

final class ProtoPrivate247
{
    private int $id;
}

var_dump(king_proto_define_schema('ProtoUser247', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));

$payload = hex2bin('0807');
$results = [];

foreach ([
    'type_error' => static function () use ($payload): void {
        king_proto_decode('ProtoUser247', $payload, 42);
    },
    'missing_class' => static function () use ($payload): void {
        king_proto_decode('ProtoUser247', $payload, 'MissingProto247');
    },
    'abstract_class' => static function () use ($payload): void {
        king_proto_decode('ProtoUser247', $payload, ProtoAbstract247::class);
    },
    'constructor_class' => static function () use ($payload): void {
        king_proto_decode('ProtoUser247', $payload, ProtoCtor247::class);
    },
    'private_property' => static function () use ($payload): void {
        king_proto_decode('ProtoUser247', $payload, ProtoPrivate247::class);
    },
    'numeric_schema_key' => static function () use ($payload): void {
        king_proto_decode('ProtoUser247', $payload, [0 => ProtoPrivate247::class]);
    },
] as $label => $decode) {
    try {
        $decode();
        $results[$label] = 'no-exception';
    } catch (Throwable $e) {
        $results[$label] = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
        ];
    }
}

var_export($results);
echo "\n";
?>
--EXPECT--
bool(true)
array (
  'type_error' => 
  array (
    'class' => 'TypeError',
    'message' => 'king_proto_decode(): Argument #3 ($decode_as_object) must be of type bool|string|array, int given',
  ),
  'missing_class' => 
  array (
    'class' => 'King\\ValidationException',
    'message' => 'Decoding failed: decode_as_object class \'MissingProto247\' for schema \'ProtoUser247\' was not found.',
  ),
  'abstract_class' => 
  array (
    'class' => 'King\\ValidationException',
    'message' => 'Decoding failed: decode_as_object class \'ProtoAbstract247\' for schema \'ProtoUser247\' must be stdClass or a concrete userland class.',
  ),
  'constructor_class' => 
  array (
    'class' => 'King\\ValidationException',
    'message' => 'Decoding failed: decode_as_object class \'ProtoCtor247\' for schema \'ProtoUser247\' must not declare a constructor.',
  ),
  'private_property' => 
  array (
    'class' => 'King\\ValidationException',
    'message' => 'Decoding failed: Schema \'ProtoUser247\' could not hydrate property \'id\' on class \'ProtoPrivate247\'.',
  ),
  'numeric_schema_key' => 
  array (
    'class' => 'King\\ValidationException',
    'message' => 'Decoding failed: decode_as_object maps must use non-empty schema names as string keys.',
  ),
)
