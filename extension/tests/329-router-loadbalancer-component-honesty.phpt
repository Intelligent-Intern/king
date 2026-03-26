--TEST--
King router/loadbalancer is exposed as an explicit config-backed system component
--FILE--
<?php
var_dump(king_system_init([]));
$status = king_system_get_status();
var_dump(isset($status['components']['router_loadbalancer']));
var_dump($status['components']['router_loadbalancer']['status']);
var_dump($status['components']['router_loadbalancer']['ready']);

$router = king_system_get_component_info('router');
$loadbalancer = king_system_get_component_info('loadbalancer');
$combined = king_system_get_component_info('router_loadbalancer');

var_dump($router['name']);
var_dump($loadbalancer['name']);
var_dump($combined['name']);
var_dump($combined['implementation']);
var_dump($combined['configuration']['forwarding_contract']);
var_dump(array_keys($combined['configuration']));

var_dump(king_system_shutdown());
?>
--EXPECT--
bool(true)
bool(true)
string(7) "running"
bool(true)
string(19) "router_loadbalancer"
string(19) "router_loadbalancer"
string(19) "router_loadbalancer"
string(13) "config_backed"
string(11) "config_only"
array(8) {
  [0]=>
  string(18) "router_mode_enable"
  [1]=>
  string(17) "hashing_algorithm"
  [2]=>
  string(22) "backend_discovery_mode"
  [3]=>
  string(19) "backend_static_list"
  [4]=>
  string(20) "backend_mcp_endpoint"
  [5]=>
  string(29) "backend_mcp_poll_interval_sec"
  [6]=>
  string(18) "max_forwarding_pps"
  [7]=>
  string(19) "forwarding_contract"
}
bool(true)
