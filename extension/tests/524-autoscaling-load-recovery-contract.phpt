--TEST--
King autoscaling keeps load-signal decisions stable across partial-state restart and recovery
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function king_autoscaling_set_load_pattern(
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

$statePath = tempnam(sys_get_temp_dir(), 'king-autoscale-load-recovery-');
$config = [
    'state_path' => $statePath,
    'max_nodes' => 6,
    'min_nodes' => 1,
    'max_scale_step' => 2,
    'scale_up_policy' => 'add_nodes:2',
    'scale_up_cpu_threshold_percent' => 70,
    'scale_down_cpu_threshold_percent' => 20,
    'cooldown_period_sec' => 0,
];

try {
    king_telemetry_init([]);
    var_dump(king_autoscaling_init($config));

    king_autoscaling_set_load_pattern(82.0, 92.0, 960.0, 510.0, 360.0, 12.0);
    var_dump(king_autoscaling_start_monitoring());
    $status = king_autoscaling_get_status();
    var_dump($status['state_load_incomplete'] === false);
    var_dump($status['current_instances']);
    var_dump($status['managed_nodes']);
    var_dump($status['last_monitor_decision']);
    var_dump($status['last_signal_source']);
    var_dump((count($status['last_monitor_decision_details']['scale_up_signals']) === 6));
    var_dump((count($status['last_monitor_decision_details']['live_signals']) === 6));

    var_dump(king_autoscaling_init($config));
    $recovered = king_autoscaling_get_status();
    var_dump($recovered['state_load_incomplete'] === false);
    var_dump($recovered['current_instances']);
    var_dump($recovered['managed_nodes']);

    $lines = file($statePath, FILE_IGNORE_NEW_LINES) ?: [];
    if (count($lines) > 3) {
        file_put_contents($statePath, implode("\n", array_slice($lines, 0, 3)) . "\n");
    }

    var_dump(king_autoscaling_init($config));
    $partial = king_autoscaling_get_status();
    var_dump($partial['state_load_incomplete']);
    var_dump($partial['managed_nodes']);
    var_dump($partial['active_managed_nodes']);

    king_autoscaling_set_load_pattern(12.0, 49.0, 40.0, 40.0, 15.0, 0.0);
    var_dump(king_autoscaling_start_monitoring());
    $scaledDown = king_autoscaling_get_status();
    var_dump($scaledDown['state_load_incomplete']);
    var_dump($scaledDown['last_monitor_decision']);
    var_dump(($scaledDown['current_instances'] === 1));
    var_dump((count($scaledDown['last_monitor_decision_details']['scale_down_ready_signals']) === 6));
    var_dump((int) ($scaledDown['active_managed_nodes']) === 0);
    var_dump($scaledDown['last_monitor_signal_snapshot']['active_connections']);
    var_dump(($scaledDown['last_signal_source'] === 'telemetry'));
} finally {
    @unlink($statePath);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
int(3)
int(2)
string(7) "scale_up"
string(9) "telemetry"
bool(true)
bool(true)
bool(true)
bool(true)
int(3)
int(2)
bool(true)
int(1)
int(1)
bool(true)
bool(true)
string(10) "scale_down"
bool(true)
bool(true)
bool(true)
int(40)
bool(true)
