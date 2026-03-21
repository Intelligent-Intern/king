--TEST--
King proto decode materializes mapped top-level and nested classes while preserving stdClass fallback
--FILE--
<?php
final class ProtoChild245
{
    public int $id;
    public ?string $name = null;
}

final class ProtoParent245
{
    public $child;
    public bool $enabled;
}

var_dump(king_proto_define_schema('ProtoChild245', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'name' => ['tag' => 2, 'type' => 'string'],
]));
var_dump(king_proto_define_schema('ProtoParent245', [
    'child' => ['tag' => 1, 'type' => 'ProtoChild245', 'required' => true],
    'enabled' => ['tag' => 2, 'type' => 'bool'],
]));

$payload = hex2bin('0a060807120261621001');

$topLevelOnly = king_proto_decode('ProtoParent245', $payload, ProtoParent245::class);
var_dump(get_class($topLevelOnly));
var_dump(get_class($topLevelOnly->child));
var_dump($topLevelOnly->child->id);
var_dump($topLevelOnly->child->name);
var_dump($topLevelOnly->enabled);

$mapped = king_proto_decode('ProtoParent245', $payload, [
    'ProtoParent245' => ProtoParent245::class,
    'ProtoChild245' => ProtoChild245::class,
]);
var_dump(get_class($mapped));
var_dump(get_class($mapped->child));
var_dump($mapped->child->id);
var_dump($mapped->child->name);
var_dump($mapped->enabled);
?>
--EXPECT--
bool(true)
bool(true)
string(14) "ProtoParent245"
string(8) "stdClass"
int(7)
string(2) "ab"
bool(true)
string(14) "ProtoParent245"
string(13) "ProtoChild245"
int(7)
string(2) "ab"
bool(true)
