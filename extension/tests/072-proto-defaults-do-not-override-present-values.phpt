--TEST--
King proto defaults do not override explicit falsy values already present on the wire
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
    'enabled' => ['tag' => 4, 'type' => 'bool', 'default' => true],
]));

$payload = king_proto_encode('Job', [
    'count' => 0,
    'status' => 'DISABLED',
    'label' => '',
    'enabled' => false,
]);

var_dump(bin2hex($payload));
var_dump(king_proto_decode('Job', $payload));
?>
--EXPECT--
bool(true)
bool(true)
string(16) "080010021a002000"
array(4) {
  ["count"]=>
  int(0)
  ["status"]=>
  int(2)
  ["label"]=>
  string(0) ""
  ["enabled"]=>
  bool(false)
}
