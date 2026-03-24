--TEST--
King proto decode skips unknown fixed64 fields in the runtime primitive subset
--FILE--
<?php
var_dump(king_proto_define_schema('User', [
    'name' => ['tag' => 3, 'type' => 'string'],
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'enabled' => ['tag' => 2, 'type' => 'bool'],
]));

$decoded = king_proto_decode('User', hex2bin('0896015121436587a9cbed0f10011a046b696e67'));
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
