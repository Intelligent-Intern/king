--TEST--
King proto decode-time defaults populate missing optional scalar and enum fields
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));

var_dump(king_proto_define_schema('Job', [
    'count' => ['tag' => 1, 'type' => 'int32', 'default' => 7],
    'status' => ['tag' => 2, 'type' => 'Status', 'default' => 'ACTIVE'],
    'label' => ['tag' => 3, 'type' => 'string', 'default' => 'untitled'],
    'ratio' => ['tag' => 4, 'type' => 'float', 'default' => 0.5],
]));

var_dump(bin2hex(king_proto_encode('Job', [])));
var_dump(king_proto_decode('Job', ''));

$decoded = king_proto_decode('Job', '', true);
var_dump($decoded instanceof stdClass);
var_dump($decoded->count, $decoded->status, $decoded->label, $decoded->ratio);
?>
--EXPECT--
bool(true)
bool(true)
string(0) ""
array(4) {
  ["count"]=>
  int(7)
  ["status"]=>
  int(1)
  ["label"]=>
  string(8) "untitled"
  ["ratio"]=>
  float(0.5)
}
bool(true)
int(7)
int(1)
string(8) "untitled"
float(0.5)
