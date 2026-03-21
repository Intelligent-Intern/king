--TEST--
King proto map<string, message> decode defaults missing values to empty nested messages
--FILE--
<?php
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32'],
    'name' => ['tag' => 2, 'type' => 'string'],
]));
var_dump(king_proto_define_schema('Directory', [
    'children' => ['tag' => 1, 'type' => 'map<string,Child>'],
]));

$payload = hex2bin('0a090a077072696d617279');

var_dump(king_proto_decode('Directory', $payload));
var_dump(king_proto_decode('Directory', $payload, true));
?>
--EXPECTF--
bool(true)
bool(true)
array(1) {
  ["children"]=>
  array(1) {
    ["primary"]=>
    array(0) {
    }
  }
}
object(stdClass)#%d (1) {
  ["children"]=>
  array(1) {
    ["primary"]=>
    object(stdClass)#%d (0) {
    }
  }
}
