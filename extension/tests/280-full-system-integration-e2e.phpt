--TEST--
King System Integration: Full E2E harness for Telemetry, Autoscaling, and Lifecycle
--FILE--
<?php
// 1. Initialize the entire system
var_dump(king_system_init(['environment' => 'development']));

// 2. Component Status verification
$status = king_system_get_status();
var_dump($status['initialized']);
var_dump($status['component_count'] > 0);
var_dump($status['readiness_blocker_count']);
var_dump($status['admission']['process_requests']);

// 3. Telemetry integration check
king_telemetry_init([]);
king_telemetry_record_metric('system.heartbeat', 1.0, null, 'counter');
$metrics = king_telemetry_get_metrics();
var_dump(isset($metrics['system.heartbeat']));

// 4. Autoscaling integration check
king_autoscaling_init([]);
king_autoscaling_scale_up(2);
$as_status = king_autoscaling_get_status();
var_dump($as_status['current_instances']);

// 5. System processing simulation
var_dump(king_system_process_request(['action' => 'test']));

// 6. Clean shutdown
var_dump(king_system_shutdown());
$status = king_system_get_status();
var_dump($status['initialized']);

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
int(3)
bool(true)
bool(true)
bool(false)
