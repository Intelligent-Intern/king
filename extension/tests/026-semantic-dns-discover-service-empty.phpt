--TEST--
King semantic DNS discovery exposes a stable empty snapshot in the current runtime
--FILE--
<?php
$result = king_semantic_dns_discover_service('any');
var_dump(array_keys($result));
var_dump($result['services']);
var_dump($result['service_type']);
var_dump($result['service_count']);
var_dump(is_int($result['discovered_at']));

$pipeline = king_semantic_dns_discover_service('pipeline_orchestrator', []);
var_dump(array_keys($pipeline));
var_dump($pipeline['services']);
var_dump($pipeline['service_type']);
var_dump($pipeline['service_count']);
var_dump(is_int($pipeline['discovered_at']));
?>
--EXPECT--
array(4) {
  [0]=>
  string(8) "services"
  [1]=>
  string(12) "service_type"
  [2]=>
  string(13) "discovered_at"
  [3]=>
  string(13) "service_count"
}
array(0) {
}
string(3) "any"
int(0)
bool(true)
array(4) {
  [0]=>
  string(8) "services"
  [1]=>
  string(12) "service_type"
  [2]=>
  string(13) "discovered_at"
  [3]=>
  string(13) "service_count"
}
array(0) {
}
string(21) "pipeline_orchestrator"
int(0)
bool(true)
