--TEST--
King proto oneof fields roundtrip across scalar and nested message variants
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
]));
var_dump(king_proto_define_schema('Envelope', [
    'child' => ['tag' => 1, 'type' => 'Child', 'oneof' => 'payload'],
    'label' => ['tag' => 2, 'type' => 'string', 'oneof' => 'payload'],
    'source' => ['tag' => 3, 'type' => 'string'],
]));

$childPayload = king_proto_encode('Envelope', [
    'child' => ['id' => 7],
    'source' => 'api',
]);
var_dump(bin2hex($childPayload));
var_dump(king_proto_decode('Envelope', $childPayload));
var_dump(king_proto_decode('Envelope', $childPayload, true));

$labelPayload = king_proto_encode('Envelope', [
    'label' => 'king',
    'source' => 'cli',
]);
var_dump(bin2hex($labelPayload));
var_dump(king_proto_decode('Envelope', $labelPayload));
?>
--EXPECTF--
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
object(stdClass)#%d (2) {
  ["child"]=>
  object(stdClass)#%d (1) {
    ["id"]=>
    int(7)
  }
  ["source"]=>
  string(3) "api"
}
string(22) "12046b696e671a03636c69"
array(2) {
  ["label"]=>
  string(4) "king"
  ["source"]=>
  string(3) "cli"
}
