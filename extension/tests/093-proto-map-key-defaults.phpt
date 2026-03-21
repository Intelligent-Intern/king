--TEST--
King proto map bool and 32-bit integer keys decode missing entry keys via scalar defaults
--FILE--
<?php
var_dump(king_proto_define_schema('Catalog', [
    'labels' => ['tag' => 1, 'type' => 'map<int32,string>'],
    'flags' => ['tag' => 2, 'type' => 'map<bool,string>'],
]));

var_dump(king_proto_decode('Catalog', hex2bin(
    '0a031201780a03120179120412026f6e'
)));
?>
--EXPECT--
bool(true)
array(2) {
  ["labels"]=>
  array(1) {
    [0]=>
    string(1) "y"
  }
  ["flags"]=>
  array(1) {
    [0]=>
    string(2) "on"
  }
}
