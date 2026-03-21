--TEST--
King IIBIN class covers defaults and oneof runtime parity over the shared backend
--FILE--
<?php
$enumName = 'IIBINStatus239';
$jobName = 'IIBINJob239';
$childName = 'IIBINChild239';
$envelopeName = 'IIBINEnvelope239';

var_dump(king_proto_define_enum($enumName, [
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));
var_dump(king_proto_define_schema($jobName, [
    'count' => ['tag' => 1, 'type' => 'int32', 'default' => 7],
    'status' => ['tag' => 2, 'type' => $enumName, 'default' => 'ACTIVE'],
    'label' => ['tag' => 3, 'type' => 'string', 'default' => 'untitled'],
]));
var_dump(King\IIBIN::decode($jobName, ''));

var_dump(King\IIBIN::defineSchema($childName, [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));
var_dump(King\IIBIN::defineSchema($envelopeName, [
    'child' => ['tag' => 1, 'type' => $childName, 'oneof' => 'payload'],
    'label' => ['tag' => 2, 'type' => 'string', 'oneof' => 'payload'],
    'source' => ['tag' => 3, 'type' => 'string'],
]));

$payload = King\IIBIN::encode($envelopeName, [
    'child' => ['id' => 7],
    'source' => 'api',
]);

var_dump(bin2hex($payload));
var_dump(King\IIBIN::decode($envelopeName, $payload));
$decodedObject = King\IIBIN::decode($envelopeName, $payload, true);
var_dump($decodedObject instanceof stdClass);
var_dump($decodedObject->child->id, $decodedObject->source);
?>
--EXPECTF--
bool(true)
bool(true)
array(3) {
  ["count"]=>
  int(7)
  ["status"]=>
  int(1)
  ["label"]=>
  string(8) "untitled"
}
bool(true)
bool(true)
string(18) "0a0208071a03617069"
array(2) {
  ["child"]=>
  array(1) {
    ["id"]=>
    int(7)
  }
  ["source"]=>
  string(3) "api"
}
bool(true)
int(7)
string(3) "api"
