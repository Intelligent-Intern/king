--TEST--
King IIBIN class covers map and nested-message runtime parity over the shared backend
--FILE--
<?php
$enumName = 'IIBINStatus238';
$childName = 'IIBINChild238';
$schemaName = 'IIBINCatalog238';

var_dump(King\IIBIN::defineEnum($enumName, [
    'ACTIVE' => 1,
    'DISABLED' => 2,
]));
var_dump(King\IIBIN::defineSchema($childName, [
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'label' => ['tag' => 2, 'type' => 'string'],
]));
var_dump(King\IIBIN::defineSchema($schemaName, [
    'labels' => ['tag' => 1, 'type' => 'map<string,string>'],
    'counts' => ['tag' => 2, 'type' => 'map<string,int32>'],
    'states' => ['tag' => 3, 'type' => 'map<string,' . $enumName . '>'],
    'children' => ['tag' => 4, 'type' => 'map<string,' . $childName . '>'],
]));

$payload = King\IIBIN::encode($schemaName, [
    'labels' => ['env' => 'prod', 'role' => 'api'],
    'counts' => ['ok' => 1],
    'states' => ['primary' => 'DISABLED'],
    'children' => [
        'primary' => ['id' => 7, 'label' => 'alpha'],
        'backup' => (object) ['id' => 8],
    ],
]);

var_dump($payload !== '');
var_dump(King\IIBIN::decode($schemaName, $payload));
var_dump(king_proto_decode($schemaName, $payload, true));
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
