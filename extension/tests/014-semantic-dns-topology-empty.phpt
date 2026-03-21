--TEST--
King semantic DNS topology exposes a stable empty snapshot in the skeleton build
--FILE--
<?php
$topology = king_semantic_dns_get_service_topology();
var_dump(array_keys($topology));
var_dump($topology['services']);
var_dump(array_is_list($topology['services']));
var_dump($topology['mother_nodes']);
var_dump(array_is_list($topology['mother_nodes']));
var_dump($topology['statistics']);
var_dump($topology['topology_generated_at'] > 0);
?>
--EXPECT--
array(4) {
  [0]=>
  string(8) "services"
  [1]=>
  string(12) "mother_nodes"
  [2]=>
  string(10) "statistics"
  [3]=>
  string(21) "topology_generated_at"
}
array(0) {
}
bool(true)
array(0) {
}
bool(true)
array(5) {
  ["total_services"]=>
  int(0)
  ["healthy_services"]=>
  int(0)
  ["degraded_services"]=>
  int(0)
  ["unhealthy_services"]=>
  int(0)
  ["mother_nodes"]=>
  int(0)
}
bool(true)
