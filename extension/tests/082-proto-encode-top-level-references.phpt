--TEST--
King proto encode accepts top-level referenced scalar repeated and map values
--FILE--
<?php
var_dump(king_proto_define_schema('Batch', [
    'id' => ['tag' => 1, 'type' => 'int32'],
    'ids' => ['tag' => 2, 'type' => 'repeated_int32'],
    'labels' => ['tag' => 3, 'type' => 'map<string,string>'],
]));

$id = 7;
$ids = [1, 2];
$labels = ['env' => 'prod'];

$payload = king_proto_encode('Batch', [
    'id' => &$id,
    'ids' => &$ids,
    'labels' => &$labels,
]);

var_dump(bin2hex($payload));
var_dump(king_proto_decode('Batch', $payload));
?>
--EXPECT--
bool(true)
string(38) "0807100110021a0b0a03656e76120470726f64"
array(3) {
  ["id"]=>
  int(7)
  ["ids"]=>
  array(2) {
    [0]=>
    int(1)
    [1]=>
    int(2)
  }
  ["labels"]=>
  array(1) {
    ["env"]=>
    string(4) "prod"
  }
}
