--TEST--
King system status functions expose health, startup, and runtime lifecycle state
--FILE--
<?php
function king_test_wait_until_ready(int $maxSeconds = 8): array
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['lifecycle'] ?? null) === 'ready') {
            return $status;
        }

        sleep(1);
    }

    return king_system_get_status();
}

$health = king_system_health_check();
var_dump(array_keys($health));
var_dump($health['overall_healthy']);
var_dump(is_string($health['build']));
var_dump($health['version']);
var_dump($health['config_override_allowed']);

$status = king_system_get_status();
var_dump(array_keys($status));
var_dump($status['initialized']);
var_dump($status['lifecycle']);
var_dump($status['component_count']);
var_dump($status['drain_intent']['reason']);
var_dump($status['allowed_lifecycle_transitions']);
var_dump($status['startup']['catalog_component_count']);
var_dump($status['startup']['ordered_components']);
var_dump($status['startup']['ready_to_start_components']);
var_dump($status['startup']['components']['config']['ready_to_start']);
var_dump($status['startup']['components']['client']['pending_dependencies']);

var_dump(king_system_init(['component_timeout_seconds' => 1]));
$status = king_system_get_status();
var_dump(array_keys($status));
var_dump($status['initialized']);
var_dump($status['lifecycle']);
var_dump($status['component_count'] > 0);
var_dump($status['components_ready']);
var_dump($status['readiness_blocker_count']);
var_dump(count($status['readiness_blockers']));
var_dump($status['drain_intent']['requested']);
var_dump($status['drain_intent']['reason']);
var_dump($status['drain_intent']['target_component_count']);
var_dump($status['allowed_lifecycle_transitions']);
var_dump($status['startup']['catalog_component_count']);
var_dump($status['startup']['started_components']);
var_dump(count($status['startup']['pending_components']));
var_dump($status['startup']['ready_to_start_components']);
var_dump($status['admission']['process_requests']);
var_dump($status['admission']['http_listener_accepts']);
var_dump($status['admission']['remote_peer_dispatches']);
var_dump($status['components']['config']['status']);
var_dump($status['components']['config']['ready']);
var_dump($status['components']['config']['readiness_reason']);
var_dump($status['components']['config']['readiness_blocking']);
var_dump($status['components']['config']['startup_order']);
var_dump($status['components']['config']['startup_dependencies']);
var_dump($status['components']['config']['startup_pending_dependencies']);
var_dump($status['components']['config']['startup_ready_to_start']);

$status = king_test_wait_until_ready();
var_dump($status['lifecycle']);
var_dump($status['components_ready'] === $status['component_count']);
var_dump($status['readiness_blocker_count']);
var_dump(count($status['readiness_blockers']));
var_dump($status['admission']['process_requests']);
var_dump($status['admission']['http_listener_accepts']);
var_dump($status['admission']['remote_peer_dispatches']);
var_dump($status['components']['config']['status']);
var_dump($status['components']['config']['ready']);
var_dump($status['components']['config']['readiness_reason']);
var_dump($status['components']['config']['readiness_blocking']);

var_dump(king_system_shutdown());
$status = king_system_get_status();
var_dump($status['initialized']);
var_dump($status['lifecycle']);
var_dump($status['component_count']);
var_dump($status['drain_intent']['reason']);
var_dump($status['allowed_lifecycle_transitions']);
?>
--EXPECTF--
array(4) {
  [0]=>
  string(15) "overall_healthy"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(23) "config_override_allowed"
}
bool(true)
bool(true)
string(%d) "%s"
bool(false)
array(13) {
  [0]=>
  string(11) "initialized"
  [1]=>
  string(9) "lifecycle"
  [2]=>
  string(15) "component_count"
  [3]=>
  string(10) "components"
  [4]=>
  string(16) "components_ready"
  [5]=>
  string(19) "components_draining"
  [6]=>
  string(23) "readiness_blocker_count"
  [7]=>
  string(18) "readiness_blockers"
  [8]=>
  string(12) "drain_intent"
  [9]=>
  string(29) "allowed_lifecycle_transitions"
  [10]=>
  string(7) "startup"
  [11]=>
  string(9) "admission"
  [12]=>
  string(29) "health_check_interval_seconds"
}
bool(false)
string(7) "stopped"
int(0)
string(4) "none"
array(1) {
  [0]=>
  string(5) "ready"
}
int(12)
array(12) {
  [0]=>
  string(6) "config"
  [1]=>
  string(6) "client"
  [2]=>
  string(6) "server"
  [3]=>
  string(9) "telemetry"
  [4]=>
  string(12) "object_store"
  [5]=>
  string(5) "iibin"
  [6]=>
  string(3) "mcp"
  [7]=>
  string(12) "semantic_dns"
  [8]=>
  string(19) "router_loadbalancer"
  [9]=>
  string(12) "orchestrator"
  [10]=>
  string(3) "cdn"
  [11]=>
  string(11) "autoscaling"
}
array(1) {
  [0]=>
  string(6) "config"
}
bool(true)
array(1) {
  [0]=>
  string(6) "config"
}
bool(true)
array(13) {
  [0]=>
  string(11) "initialized"
  [1]=>
  string(9) "lifecycle"
  [2]=>
  string(15) "component_count"
  [3]=>
  string(10) "components"
  [4]=>
  string(16) "components_ready"
  [5]=>
  string(19) "components_draining"
  [6]=>
  string(23) "readiness_blocker_count"
  [7]=>
  string(18) "readiness_blockers"
  [8]=>
  string(12) "drain_intent"
  [9]=>
  string(29) "allowed_lifecycle_transitions"
  [10]=>
  string(7) "startup"
  [11]=>
  string(9) "admission"
  [12]=>
  string(29) "health_check_interval_seconds"
}
bool(true)
string(8) "starting"
bool(true)
int(0)
int(12)
int(12)
bool(false)
string(4) "none"
int(0)
array(4) {
  [0]=>
  string(5) "ready"
  [1]=>
  string(8) "draining"
  [2]=>
  string(6) "failed"
  [3]=>
  string(7) "stopped"
}
int(12)
array(1) {
  [0]=>
  string(6) "config"
}
int(11)
array(0) {
}
bool(false)
bool(false)
bool(false)
string(12) "initializing"
bool(false)
string(22) "component_initializing"
bool(true)
int(1)
array(0) {
}
array(0) {
}
bool(false)
string(5) "ready"
bool(true)
int(0)
int(0)
bool(true)
bool(true)
bool(true)
string(7) "running"
bool(true)
string(5) "ready"
bool(false)
bool(true)
bool(false)
string(7) "stopped"
int(0)
string(4) "none"
array(1) {
  [0]=>
  string(5) "ready"
}
