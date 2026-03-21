--TEST--
King proto oneof decode keeps only the last member seen on the wire
--FILE--
<?php
var_dump(king_proto_define_schema('Envelope', [
    'id' => ['tag' => 1, 'type' => 'int32', 'oneof' => 'payload'],
    'label' => ['tag' => 2, 'type' => 'string', 'oneof' => 'payload'],
    'source' => ['tag' => 3, 'type' => 'string'],
]));

var_dump(king_proto_decode('Envelope', hex2bin('080712046b696e671a03617069')));
var_dump(king_proto_decode('Envelope', hex2bin('12046b696e6708091a03617069')));
?>
--EXPECT--
bool(true)
array(2) {
  ["label"]=>
  string(4) "king"
  ["source"]=>
  string(3) "api"
}
array(2) {
  ["id"]=>
  int(9)
  ["source"]=>
  string(3) "api"
}
