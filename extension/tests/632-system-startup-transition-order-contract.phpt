--TEST--
King system startup transitions honor the declared dependency waves before the coordinated runtime becomes ready
--FILE--
<?php
var_dump(king_system_init(['component_timeout_seconds' => 1]));

$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['components']['config']['status']);
var_dump($status['components']['client']['status']);
var_dump($status['startup']['started_components']);
var_dump(isset($status['startup']['blocked_components']['client']));
var_dump(isset($status['startup']['blocked_components']['orchestrator']));

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['components']['config']['status']);
var_dump($status['components']['client']['status']);
var_dump($status['components']['server']['status']);
var_dump($status['components']['telemetry']['status']);
var_dump($status['components']['object_store']['status']);
var_dump($status['components']['iibin']['status']);
var_dump($status['components']['mcp']['status']);

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['components']['client']['status']);
var_dump($status['components']['server']['status']);
var_dump($status['components']['telemetry']['status']);
var_dump($status['components']['object_store']['status']);
var_dump($status['components']['iibin']['status']);
var_dump($status['components']['mcp']['status']);
var_dump($status['components']['semantic_dns']['status']);
var_dump($status['components']['router_loadbalancer']['status']);
var_dump($status['components']['orchestrator']['status']);
var_dump($status['components']['cdn']['status']);
var_dump($status['components']['autoscaling']['status']);

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['components']['mcp']['status']);
var_dump($status['components']['semantic_dns']['status']);
var_dump($status['components']['router_loadbalancer']['status']);
var_dump($status['components']['orchestrator']['status']);
var_dump($status['components']['cdn']['status']);
var_dump($status['components']['autoscaling']['status']);

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['components_ready']);
var_dump($status['readiness_blocker_count']);
var_dump($status['admission']['process_requests']);
var_dump($status['components']['autoscaling']['status']);

var_dump(king_system_shutdown());
?>
--EXPECT--
bool(true)
string(8) "starting"
string(12) "initializing"
string(13) "uninitialized"
array(1) {
  [0]=>
  string(6) "config"
}
bool(true)
bool(true)
string(8) "starting"
string(7) "running"
string(12) "initializing"
string(12) "initializing"
string(12) "initializing"
string(12) "initializing"
string(12) "initializing"
string(13) "uninitialized"
string(8) "starting"
string(7) "running"
string(7) "running"
string(7) "running"
string(7) "running"
string(7) "running"
string(12) "initializing"
string(12) "initializing"
string(12) "initializing"
string(12) "initializing"
string(12) "initializing"
string(13) "uninitialized"
string(8) "starting"
string(7) "running"
string(7) "running"
string(7) "running"
string(7) "running"
string(7) "running"
string(12) "initializing"
string(5) "ready"
int(12)
int(0)
bool(true)
string(7) "running"
bool(true)
