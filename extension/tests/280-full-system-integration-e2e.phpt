--TEST--
King System Integration: Full E2E harness for Telemetry, Autoscaling, and Lifecycle
--FILE--
<?php
function king_system_wait_until_ready_for_e2e(int $maxSeconds = 8): array
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

function king_system_wait_until_stopped_for_e2e(int $maxSeconds = 8): array
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

// 1. Initialize the entire system
var_dump(king_system_init([
    'environment' => 'development',
    'component_timeout_seconds' => 1,
]));

$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['initialized']);
var_dump($status['component_count'] > 0);
var_dump($status['readiness_blocker_count'] > 0);
var_dump($status['admission']['process_requests']);

$status = king_system_wait_until_ready_for_e2e();
var_dump($status['lifecycle']);
var_dump($status['readiness_blocker_count']);
var_dump($status['admission']['process_requests']);

// 2. Telemetry integration check
king_telemetry_init([]);
king_telemetry_record_metric('system.heartbeat', 1.0, null, 'counter');
$metrics = king_telemetry_get_metrics();
var_dump(isset($metrics['system.heartbeat']));

// 3. Autoscaling integration check
king_autoscaling_init([]);
king_autoscaling_scale_up(2);
$as_status = king_autoscaling_get_status();
var_dump($as_status['current_instances']);

// 4. System processing simulation
var_dump(king_system_process_request(['action' => 'test']));

// 5. Clean shutdown
var_dump(king_system_shutdown());
$status = king_system_wait_until_stopped_for_e2e();
var_dump($status['initialized']);
?>
--EXPECT--
bool(true)
string(8) "starting"
bool(true)
bool(true)
bool(true)
bool(false)
string(5) "ready"
int(0)
bool(true)
bool(true)
int(3)
bool(true)
bool(true)
bool(false)
