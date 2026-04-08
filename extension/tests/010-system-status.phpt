--TEST--
King system status functions expose health, startup, shutdown, and runtime lifecycle state
--FILE--
<?php
function king_test_wait_until_ready(int $maxSeconds = 12): array
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

function king_test_wait_until_stopped(int $maxSeconds = 12): array
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['initialized'] ?? true) === false) {
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
var_dump($status['recovery']['reason']);
var_dump($status['recovery']['coordinator_state_status']);
var_dump($status['recovery']['coordinator_state_present']);
var_dump($status['startup']['catalog_component_count']);
var_dump(($status['startup']['ordered_components'][0] ?? null) === 'config');
var_dump(($status['startup']['ordered_components'][11] ?? null) === 'autoscaling');
var_dump($status['startup']['ready_to_start_components']);
var_dump($status['startup']['components']['config']['ready_to_start']);
var_dump($status['startup']['components']['client']['pending_dependencies']);
var_dump($status['shutdown']['catalog_component_count']);
var_dump(($status['shutdown']['ordered_components'][0] ?? null) === 'autoscaling');
var_dump(($status['shutdown']['ordered_components'][11] ?? null) === 'config');
var_dump($status['shutdown']['drain_first_required']);
var_dump(count($status['shutdown']['ready_to_stop_components']));
var_dump(($status['shutdown']['components']['autoscaling']['ready_to_stop'] ?? null) === false);

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
var_dump($status['recovery']['active']);
var_dump($status['recovery']['recovered']);
var_dump($status['recovery']['reason']);
var_dump($status['recovery']['coordinator_state_status']);
var_dump($status['startup']['catalog_component_count']);
var_dump($status['startup']['started_components']);
var_dump(count($status['startup']['pending_components']));
var_dump($status['startup']['ready_to_start_components']);
var_dump(($status['shutdown']['catalog_component_count'] ?? null) === 12);
var_dump(($status['shutdown']['drain_first_required'] ?? null) === true);
var_dump($status['admission']['process_requests']);
var_dump($status['admission']['http_listener_accepts']);
var_dump($status['admission']['remote_peer_dispatches']);
var_dump($status['recovery']['active']);
var_dump($status['recovery']['recovered']);
var_dump($status['recovery']['reason']);
var_dump($status['components']['config']['status']);
var_dump($status['components']['config']['ready']);
var_dump($status['components']['config']['readiness_reason']);
var_dump($status['components']['config']['readiness_blocking']);
var_dump($status['components']['config']['startup_order']);
var_dump($status['components']['config']['startup_dependencies']);
var_dump($status['components']['config']['startup_pending_dependencies']);
var_dump($status['components']['config']['startup_ready_to_start']);
var_dump(($status['components']['autoscaling']['shutdown_order'] ?? null) === 1);
var_dump(($status['components']['autoscaling']['shutdown_dependents'] ?? []) === []);
var_dump(($status['components']['autoscaling']['shutdown_pending_dependents'] ?? []) === []);
var_dump(($status['components']['autoscaling']['shutdown_ready_to_stop'] ?? null) === false);

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
var_dump(($status['shutdown']['ready_to_stop_components'] ?? null) === [
    'autoscaling',
    'cdn',
    'orchestrator',
    'router_loadbalancer',
    'mcp',
    'iibin',
]);
var_dump(($status['components']['autoscaling']['shutdown_ready_to_stop'] ?? null) === true);
var_dump(($status['components']['config']['shutdown_order'] ?? null) === 12);
var_dump(($status['components']['config']['shutdown_ready_to_stop'] ?? null) === false);

var_dump(king_system_shutdown());
$status = king_test_wait_until_stopped();
var_dump($status['initialized']);
var_dump($status['lifecycle']);
var_dump($status['component_count']);
var_dump($status['drain_intent']['reason']);
var_dump($status['allowed_lifecycle_transitions']);
var_dump($status['recovery']['reason']);
var_dump($status['recovery']['coordinator_state_status']);
var_dump($status['recovery']['coordinator_state_present']);
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
array(15) {
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
  string(8) "recovery"
  [11]=>
  string(7) "startup"
  [12]=>
  string(8) "shutdown"
  [13]=>
  string(9) "admission"
  [14]=>
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
string(4) "none"
string(8) "inactive"
bool(false)
int(12)
bool(true)
bool(true)
array(1) {
  [0]=>
  string(6) "config"
}
bool(true)
array(1) {
  [0]=>
  string(6) "config"
}
int(12)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
array(15) {
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
  string(8) "recovery"
  [11]=>
  string(7) "startup"
  [12]=>
  string(8) "shutdown"
  [13]=>
  string(9) "admission"
  [14]=>
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
bool(false)
bool(false)
string(4) "none"
string(8) "inactive"
int(12)
array(1) {
  [0]=>
  string(6) "config"
}
int(11)
array(0) {
}
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
string(4) "none"
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
bool(true)
bool(true)
bool(true)
bool(true)
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
string(7) "stopped"
int(0)
string(4) "none"
array(1) {
  [0]=>
  string(5) "ready"
}
string(4) "none"
string(8) "inactive"
bool(false)
