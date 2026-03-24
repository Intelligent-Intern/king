--TEST--
King autoscaling status exposes runtime lifecycle defaults
--FILE--
<?php
var_dump(king_autoscaling_init([]));
$status = king_autoscaling_get_status();
var_dump(array_keys($status));
var_dump($status['initialized']);
var_dump($status['monitoring_active']);
var_dump($status['current_instances']);
?>
--EXPECT--
bool(true)
array(21) {
  [0]=>
  string(11) "initialized"
  [1]=>
  string(17) "monitoring_active"
  [2]=>
  string(17) "current_instances"
  [3]=>
  string(8) "provider"
  [4]=>
  string(13) "provider_mode"
  [5]=>
  string(27) "controller_token_configured"
  [6]=>
  string(13) "managed_nodes"
  [7]=>
  string(20) "active_managed_nodes"
  [8]=>
  string(25) "provisioned_managed_nodes"
  [9]=>
  string(24) "registered_managed_nodes"
  [10]=>
  string(22) "draining_managed_nodes"
  [11]=>
  string(22) "cooldown_remaining_sec"
  [12]=>
  string(20) "last_monitor_tick_at"
  [13]=>
  string(12) "action_count"
  [14]=>
  string(12) "api_endpoint"
  [15]=>
  string(10) "state_path"
  [16]=>
  string(16) "last_action_kind"
  [17]=>
  string(18) "last_signal_source"
  [18]=>
  string(20) "last_decision_reason"
  [19]=>
  string(10) "last_error"
  [20]=>
  string(12) "last_warning"
}
bool(true)
bool(false)
int(1)
