--TEST--
King proto packed field opt-in encodes repeated numeric and enum fields in packed form
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));
var_dump(king_proto_define_schema('Batch', [
    'ids' => ['tag' => 1, 'type' => 'repeated_int32', 'required' => true, 'packed' => true],
    'names' => ['tag' => 2, 'type' => 'repeated_string'],
    'states' => ['tag' => 3, 'type' => 'repeated_Status', 'packed' => true],
]));

$encoded = king_proto_encode('Batch', [
    'ids' => [1, 150],
    'names' => ['a', 'bc'],
    'states' => [2, 1],
]);

var_dump(bin2hex($encoded));
var_dump(king_proto_decode('Batch', $encoded));
?>
--EXPECT--
bool(true)
bool(true)
string(32) "0a03019601120161120262631a020201"
array(3) {
  ["ids"]=>
  array(2) {
    [0]=>
    int(1)
    [1]=>
    int(150)
  }
  ["names"]=>
  array(2) {
    [0]=>
    string(1) "a"
    [1]=>
    string(2) "bc"
  }
  ["states"]=>
  array(2) {
    [0]=>
    int(2)
    [1]=>
    int(1)
  }
}
