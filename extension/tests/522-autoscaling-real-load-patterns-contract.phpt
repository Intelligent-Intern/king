--TEST--
King autoscaling explains scale, hold, and cooldown decisions across real load patterns
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function king_autoscaling_set_real_load_pattern(
    float $cpu,
    float $memory,
    float $activeConnections,
    float $requestsPerSecond,
    float $responseTimeMs,
    float $queueDepth
): void {
    king_telemetry_record_metric('autoscaling.cpu_utilization', $cpu, null, 'gauge');
    king_telemetry_record_metric('autoscaling.memory_utilization', $memory, null, 'gauge');
    king_telemetry_record_metric('autoscaling.active_connections', $activeConnections, null, 'gauge');
    king_telemetry_record_metric('autoscaling.requests_per_second', $requestsPerSecond, null, 'gauge');
    king_telemetry_record_metric('autoscaling.response_time_ms', $responseTimeMs, null, 'gauge');
    king_telemetry_record_metric('autoscaling.queue_depth', $queueDepth, null, 'gauge');
}

var_dump(king_autoscaling_init([
    'max_nodes' => 6,
    'max_scale_step' => 2,
    'scale_up_policy' => 'add_nodes:2',
    'cooldown_period_sec' => 1,
    'scale_up_cpu_threshold_percent' => 75,
    'scale_down_cpu_threshold_percent' => 25,
]));

king_telemetry_init([]);

king_autoscaling_set_real_load_pattern(62.0, 58.0, 360.0, 210.0, 85.0, 3.0);
var_dump(king_autoscaling_start_monitoring());
$status = king_autoscaling_get_status();
var_dump($status['current_instances']);
var_dump($status['last_monitor_decision']);
var_dump($status['last_action_kind']);
var_dump($status['last_signal_source']);
var_dump($status['last_monitor_signal_snapshot']['cpu_utilization']);
var_dump($status['last_monitor_signal_snapshot']['queue_depth']);
var_dump($status['last_monitor_decision_details']['scale_up_signals']);
var_dump($status['last_monitor_decision_details']['scale_down_ready_signals']);
var_dump($status['last_monitor_decision_details']['hold_blockers']);
var_dump(str_contains($status['last_decision_reason'], 'hysteresis window'));

king_autoscaling_set_real_load_pattern(88.0, 72.0, 1300.0, 780.0, 310.0, 12.0);
var_dump(king_autoscaling_start_monitoring());
$status = king_autoscaling_get_status();
var_dump($status['current_instances']);
var_dump($status['active_managed_nodes']);
var_dump($status['last_monitor_decision']);
var_dump($status['last_action_kind']);
var_dump($status['last_monitor_decision_details']['scale_up_signals']);
var_dump($status['last_monitor_decision_details']['hold_blockers']);
var_dump(str_contains($status['last_decision_reason'], 'scale up decision'));

king_autoscaling_set_real_load_pattern(91.0, 74.0, 1400.0, 860.0, 330.0, 14.0);
var_dump(king_autoscaling_start_monitoring());
$status = king_autoscaling_get_status();
var_dump($status['current_instances']);
var_dump($status['last_monitor_decision']);
var_dump($status['last_action_kind']);
var_dump($status['last_monitor_decision_details']['blocked_by_cooldown']);
var_dump($status['last_monitor_decision_details']['scale_up_signals']);
var_dump(str_contains($status['last_warning'], 'cooldown'));

sleep(1);
king_autoscaling_set_real_load_pattern(8.0, 44.0, 40.0, 20.0, 12.0, 0.0);
var_dump(king_autoscaling_start_monitoring());
$status = king_autoscaling_get_status();
var_dump($status['current_instances']);
var_dump($status['active_managed_nodes']);
var_dump($status['last_monitor_decision']);
var_dump($status['last_action_kind']);
var_dump($status['last_monitor_signal_snapshot']['active_connections']);
var_dump($status['last_monitor_decision_details']['scale_down_ready_signals']);
var_dump($status['last_monitor_decision_details']['hold_blockers']);
var_dump(str_contains($status['last_decision_reason'], 'scale down decision'));
?>
--EXPECT--
bool(true)
bool(true)
int(1)
string(4) "hold"
string(12) "monitor_tick"
string(9) "telemetry"
float(62)
int(3)
array(0) {
}
array(1) {
  [0]=>
  string(6) "memory"
}
array(5) {
  [0]=>
  string(3) "cpu"
  [1]=>
  string(18) "active_connections"
  [2]=>
  string(19) "requests_per_second"
  [3]=>
  string(16) "response_time_ms"
  [4]=>
  string(11) "queue_depth"
}
bool(true)
bool(true)
int(3)
int(2)
string(8) "scale_up"
string(8) "scale_up"
array(5) {
  [0]=>
  string(3) "cpu"
  [1]=>
  string(18) "active_connections"
  [2]=>
  string(19) "requests_per_second"
  [3]=>
  string(16) "response_time_ms"
  [4]=>
  string(11) "queue_depth"
}
array(6) {
  [0]=>
  string(3) "cpu"
  [1]=>
  string(6) "memory"
  [2]=>
  string(18) "active_connections"
  [3]=>
  string(19) "requests_per_second"
  [4]=>
  string(16) "response_time_ms"
  [5]=>
  string(11) "queue_depth"
}
bool(true)
bool(true)
int(3)
string(8) "scale_up"
string(12) "monitor_tick"
bool(true)
array(3) {
  [0]=>
  string(3) "cpu"
  [1]=>
  string(16) "response_time_ms"
  [2]=>
  string(11) "queue_depth"
}
bool(true)
bool(true)
int(2)
int(1)
string(10) "scale_down"
string(10) "scale_down"
int(40)
array(6) {
  [0]=>
  string(3) "cpu"
  [1]=>
  string(6) "memory"
  [2]=>
  string(18) "active_connections"
  [3]=>
  string(19) "requests_per_second"
  [4]=>
  string(16) "response_time_ms"
  [5]=>
  string(11) "queue_depth"
}
array(0) {
}
bool(true)
