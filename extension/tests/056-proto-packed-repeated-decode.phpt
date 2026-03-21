--TEST--
King proto decode accepts packed repeated numeric and enum payloads in the skeleton runtime subset
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));
var_dump(king_proto_define_schema('Batch', [
    'ids' => ['tag' => 1, 'type' => 'repeated_int32', 'required' => true],
    'names' => ['tag' => 2, 'type' => 'repeated_string'],
    'states' => ['tag' => 3, 'type' => 'repeated_Status'],
]));

$payload = hex2bin('0a03019601120161120262631a020201');
$decoded_array = king_proto_decode('Batch', $payload);
var_dump($decoded_array);

$decoded_object = king_proto_decode('Batch', $payload, true);
var_dump($decoded_object instanceof stdClass);
var_dump((array) $decoded_object);
?>
--EXPECT--
bool(true)
bool(true)
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
bool(true)
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
