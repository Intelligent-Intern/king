--TEST--
King IIBIN schema compiler snapshots field metadata and decode defaults at definition time
--FILE--
<?php
$schemaName = 'IIBINCached240';
$schema = [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'label' => ['tag' => 2, 'type' => 'string', 'default' => 'compiled'],
];

var_dump(King\IIBIN::defineSchema($schemaName, $schema));

$schema['id']['tag'] = 3;
$schema['id']['required'] = false;
$schema['label']['default'] = 'mutated';
$schema['extra'] = ['tag' => 1, 'type' => 'string'];

$payload = King\IIBIN::encode($schemaName, ['id' => 7]);
var_dump(bin2hex($payload));
var_dump(King\IIBIN::decode($schemaName, $payload));

try {
    King\IIBIN::encode($schemaName, []);
} catch (King\Exception $e) {
    echo $e->getMessage(), "\n";
}

var_dump(king_proto_decode($schemaName, hex2bin('0807')));
?>
--EXPECT--
bool(true)
string(4) "0807"
array(2) {
  ["id"]=>
  int(7)
  ["label"]=>
  string(8) "compiled"
}
Encoding failed: Required field 'id' (tag 1) is missing or null.
array(2) {
  ["id"]=>
  int(7)
  ["label"]=>
  string(8) "compiled"
}
