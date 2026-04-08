--TEST--
King system status exposes the canonical startup dependency graph and visibility before ordered startup transitions are implemented
--FILE--
<?php
$status = king_system_get_status();
var_dump($status['startup']['blocked_components']['router_loadbalancer']);
var_dump($status['startup']['blocked_components']['orchestrator']);
var_dump($status['startup']['components']['autoscaling']);

var_dump(king_system_init(['component_timeout_seconds' => 1]));
$status = king_system_get_status();
var_dump($status['startup']['started_components']);
var_dump($status['startup']['blocked_components']);
var_dump($status['startup']['components']['autoscaling']);
var_dump($status['components']['orchestrator']['startup_order']);
var_dump($status['components']['orchestrator']['startup_dependencies']);
var_dump($status['components']['orchestrator']['startup_pending_dependencies']);
var_dump($status['components']['orchestrator']['startup_ready_to_start']);

var_dump(king_system_shutdown());
?>
--EXPECT--
array(3) {
  [0]=>
  string(6) "config"
  [1]=>
  string(6) "client"
  [2]=>
  string(6) "server"
}
array(3) {
  [0]=>
  string(6) "config"
  [1]=>
  string(9) "telemetry"
  [2]=>
  string(12) "object_store"
}
array(6) {
  ["order"]=>
  int(12)
  ["dependencies"]=>
  array(4) {
    [0]=>
    string(6) "config"
    [1]=>
    string(9) "telemetry"
    [2]=>
    string(6) "server"
    [3]=>
    string(12) "semantic_dns"
  }
  ["pending_dependencies"]=>
  array(4) {
    [0]=>
    string(6) "config"
    [1]=>
    string(9) "telemetry"
    [2]=>
    string(6) "server"
    [3]=>
    string(12) "semantic_dns"
  }
  ["status_visible"]=>
  bool(false)
  ["started"]=>
  bool(false)
  ["ready_to_start"]=>
  bool(false)
}
bool(true)
array(1) {
  [0]=>
  string(6) "config"
}
array(11) {
  ["client"]=>
  array(1) {
    [0]=>
    string(6) "config"
  }
  ["server"]=>
  array(1) {
    [0]=>
    string(6) "config"
  }
  ["telemetry"]=>
  array(1) {
    [0]=>
    string(6) "config"
  }
  ["object_store"]=>
  array(1) {
    [0]=>
    string(6) "config"
  }
  ["iibin"]=>
  array(1) {
    [0]=>
    string(6) "config"
  }
  ["mcp"]=>
  array(2) {
    [0]=>
    string(6) "config"
    [1]=>
    string(6) "client"
  }
  ["semantic_dns"]=>
  array(2) {
    [0]=>
    string(6) "config"
    [1]=>
    string(6) "client"
  }
  ["router_loadbalancer"]=>
  array(3) {
    [0]=>
    string(6) "config"
    [1]=>
    string(6) "client"
    [2]=>
    string(6) "server"
  }
  ["orchestrator"]=>
  array(3) {
    [0]=>
    string(6) "config"
    [1]=>
    string(9) "telemetry"
    [2]=>
    string(12) "object_store"
  }
  ["cdn"]=>
  array(3) {
    [0]=>
    string(6) "config"
    [1]=>
    string(6) "server"
    [2]=>
    string(12) "object_store"
  }
  ["autoscaling"]=>
  array(4) {
    [0]=>
    string(6) "config"
    [1]=>
    string(9) "telemetry"
    [2]=>
    string(6) "server"
    [3]=>
    string(12) "semantic_dns"
  }
}
array(6) {
  ["order"]=>
  int(12)
  ["dependencies"]=>
  array(4) {
    [0]=>
    string(6) "config"
    [1]=>
    string(9) "telemetry"
    [2]=>
    string(6) "server"
    [3]=>
    string(12) "semantic_dns"
  }
  ["pending_dependencies"]=>
  array(4) {
    [0]=>
    string(6) "config"
    [1]=>
    string(9) "telemetry"
    [2]=>
    string(6) "server"
    [3]=>
    string(12) "semantic_dns"
  }
  ["status_visible"]=>
  bool(true)
  ["started"]=>
  bool(false)
  ["ready_to_start"]=>
  bool(false)
}
int(10)
array(3) {
  [0]=>
  string(6) "config"
  [1]=>
  string(9) "telemetry"
  [2]=>
  string(12) "object_store"
}
array(3) {
  [0]=>
  string(6) "config"
  [1]=>
  string(9) "telemetry"
  [2]=>
  string(12) "object_store"
}
bool(false)
bool(true)
