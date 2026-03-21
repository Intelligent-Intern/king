--TEST--
King proto map<string, scalar|enum> decode keeps the last value for duplicate keys
--FILE--
<?php
var_dump(king_proto_define_schema('Catalog', [
    'labels' => ['tag' => 1, 'type' => 'map<string,string>'],
]));

var_dump(king_proto_decode('Catalog', hex2bin(
    '0a0b0a03656e76120470726f640a0c0a03656e7612057374616765'
)));
?>
--EXPECT--
bool(true)
array(1) {
  ["labels"]=>
  array(1) {
    ["env"]=>
    string(5) "stage"
  }
}
