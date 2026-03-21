--TEST--
King IIBIN decode materializes mapped message classes while map containers stay arrays
--FILE--
<?php
final class ProtoItem246
{
    public int $id;
}

final class ProtoCatalog246
{
    public array $children;
}

var_dump(King\IIBIN::defineSchema('ProtoItem246', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));
var_dump(King\IIBIN::defineSchema('ProtoCatalog246', [
    'children' => ['tag' => 1, 'type' => 'map<string,ProtoItem246>'],
]));

$payload = hex2bin('0a070a0161120208010a070a016212020802');
$decoded = King\IIBIN::decode('ProtoCatalog246', $payload, [
    'ProtoCatalog246' => ProtoCatalog246::class,
    'ProtoItem246' => ProtoItem246::class,
]);

var_dump(get_class($decoded));
var_dump(is_array($decoded->children));
var_dump(get_class($decoded->children['a']));
var_dump($decoded->children['a']->id);
var_dump(get_class($decoded->children['b']));
var_dump($decoded->children['b']->id);
?>
--EXPECT--
bool(true)
bool(true)
string(15) "ProtoCatalog246"
bool(true)
string(12) "ProtoItem246"
int(1)
string(12) "ProtoItem246"
int(2)
