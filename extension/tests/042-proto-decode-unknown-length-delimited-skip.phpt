--TEST--
King proto decode skips unknown length-delimited fields in the skeleton primitive subset
--FILE--
<?php
var_dump(king_proto_define_schema('User', [
    'name' => ['tag' => 3, 'type' => 'string'],
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'enabled' => ['tag' => 2, 'type' => 'bool'],
]));

$decoded = king_proto_decode('User', hex2bin('0896017a0378797a10011a046b696e67'));
var_dump($decoded);
?>
--EXPECT--
bool(true)
array(3) {
  ["id"]=>
  int(150)
  ["enabled"]=>
  bool(true)
  ["name"]=>
  string(4) "king"
}
