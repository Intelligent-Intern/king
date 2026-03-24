--TEST--
King proto map<string, scalar|enum|message> fields roundtrip through the runtime subset
--FILE--
<?php
var_dump(king_proto_define_enum('Status', [
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));
var_dump(king_proto_define_schema('Child', [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'label' => ['tag' => 2, 'type' => 'string'],
]));
var_dump(king_proto_define_schema('Catalog', [
    'labels' => ['tag' => 1, 'type' => 'map<string,string>'],
    'counts' => ['tag' => 2, 'type' => 'map<string,int32>'],
    'states' => ['tag' => 3, 'type' => 'map<string,Status>'],
    'children' => ['tag' => 4, 'type' => 'map<string,Child>'],
]));

$payload = king_proto_encode('Catalog', [
    'labels' => ['env' => 'prod', 'role' => 'api'],
    'counts' => ['ok' => 1],
    'states' => ['primary' => 'DISABLED'],
    'children' => [
        'primary' => ['id' => 7, 'label' => 'alpha'],
        'backup' => (object) ['id' => 8],
    ],
]);

var_dump($payload !== '');
var_dump(king_proto_decode('Catalog', $payload));
var_dump(king_proto_decode('Catalog', $payload, true));
?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
bool(true)
array(4) {
  ["labels"]=>
  array(2) {
    ["env"]=>
    string(4) "prod"
    ["role"]=>
    string(3) "api"
  }
  ["counts"]=>
  array(1) {
    ["ok"]=>
    int(1)
  }
  ["states"]=>
  array(1) {
    ["primary"]=>
    int(2)
  }
  ["children"]=>
  array(2) {
    ["primary"]=>
    array(2) {
      ["id"]=>
      int(7)
      ["label"]=>
      string(5) "alpha"
    }
    ["backup"]=>
    array(1) {
      ["id"]=>
      int(8)
    }
  }
}
object(stdClass)#%d (4) {
  ["labels"]=>
  array(2) {
    ["env"]=>
    string(4) "prod"
    ["role"]=>
    string(3) "api"
  }
  ["counts"]=>
  array(1) {
    ["ok"]=>
    int(1)
  }
  ["states"]=>
  array(1) {
    ["primary"]=>
    int(2)
  }
  ["children"]=>
  array(2) {
    ["primary"]=>
    object(stdClass)#%d (2) {
      ["id"]=>
      int(7)
      ["label"]=>
      string(5) "alpha"
    }
    ["backup"]=>
    object(stdClass)#%d (1) {
      ["id"]=>
      int(8)
    }
  }
}
