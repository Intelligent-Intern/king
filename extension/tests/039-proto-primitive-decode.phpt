--TEST--
King proto primitive schemas decode int32/bool/string fields and enforce required/wire checks
--FILE--
<?php
var_dump(king_proto_define_schema('User', [
    'name' => ['tag' => 3, 'type' => 'string'],
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'enabled' => ['tag' => 2, 'type' => 'bool'],
]));

$payload = hex2bin('08960110011a046b696e67');
$decoded_array = king_proto_decode('User', $payload);
var_dump($decoded_array);

$decoded_object = king_proto_decode('User', $payload, true);
var_dump($decoded_object instanceof stdClass);
var_dump((array) $decoded_object);

try {
    king_proto_decode('User', hex2bin('10011a046b696e67'));
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}

try {
    king_proto_decode('User', hex2bin('08960110011801'));
} catch (King\Exception $e) {
    var_dump($e->getMessage());
}
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
bool(true)
array(3) {
  ["id"]=>
  int(150)
  ["enabled"]=>
  bool(true)
  ["name"]=>
  string(4) "king"
}
string(83) "Decoding error: Required field 'id' (tag 1) not found in payload for schema 'User'."
string(104) "Schema 'User': Wire type mismatch for field 'name' (tag 3). Expected wire type 2, but got 0 on the wire."
