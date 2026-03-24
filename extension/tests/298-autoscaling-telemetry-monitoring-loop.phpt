--TEST--
King autoscaling monitoring consumes live telemetry with hysteresis and cooldown guards
--INI--
king.security_allow_config_override=1
--FILE--
<?php
var_dump(king_autoscaling_init([
    'max_nodes' => 5,
    'max_scale_step' => 2,
    'scale_up_policy' => 'add_nodes:3',
    'cooldown_period_sec' => 1,
    'scale_up_cpu_threshold_percent' => 75,
    'scale_down_cpu_threshold_percent' => 25,
]));

king_telemetry_init([]);
king_telemetry_record_metric('autoscaling.cpu_utilization', 96.0, null, 'gauge');
king_telemetry_record_metric('autoscaling.queue_depth', 12.0, null, 'gauge');
king_telemetry_record_metric('autoscaling.requests_per_second', 2400.0, null, 'gauge');

var_dump(king_autoscaling_start_monitoring());
$status = king_autoscaling_get_status();
var_dump($status['monitoring_active']);
var_dump($status['current_instances']);
var_dump($status['active_managed_nodes']);
var_dump($status['last_signal_source']);
var_dump(str_contains($status['last_decision_reason'], 'scale up decision'));
var_dump($status['last_action_kind']);

var_dump(king_autoscaling_start_monitoring());
$status = king_autoscaling_get_status();
var_dump($status['current_instances']);
var_dump(is_int($status['cooldown_remaining_sec']));
var_dump(str_contains($status['last_warning'], 'cooldown'));

sleep(1);

king_telemetry_record_metric('autoscaling.cpu_utilization', 5.0, null, 'gauge');
king_telemetry_record_metric('autoscaling.queue_depth', 0.0, null, 'gauge');
king_telemetry_record_metric('autoscaling.requests_per_second', 10.0, null, 'gauge');
king_telemetry_record_metric('autoscaling.response_time_ms', 9.0, null, 'gauge');
king_telemetry_record_metric('autoscaling.active_connections', 8.0, null, 'gauge');

var_dump(king_autoscaling_start_monitoring());
$status = king_autoscaling_get_status();
var_dump($status['current_instances']);
var_dump($status['active_managed_nodes']);
var_dump($status['last_action_kind']);
var_dump(str_contains($status['last_decision_reason'], 'scale down decision'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
int(3)
int(2)
string(16) "telemetry+system"
bool(true)
string(8) "scale_up"
bool(true)
int(3)
bool(true)
bool(true)
bool(true)
int(2)
int(1)
string(10) "scale_down"
bool(true)
