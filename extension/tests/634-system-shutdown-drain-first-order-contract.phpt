--TEST--
King coordinated shutdown drains first and stops components in canonical reverse dependency waves
--FILE--
<?php
function king_system_shutdown_wait_until_ready(int $maxSeconds = 8): array
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['lifecycle'] ?? null) === 'ready') {
            return $status;
        }

        sleep(1);
    }

    throw new RuntimeException('system did not become ready before shutdown order scenario');
}

var_dump(king_system_init(['component_timeout_seconds' => 1]));
$status = king_system_shutdown_wait_until_ready();
var_dump($status['lifecycle']);
var_dump($status['admission']['process_requests']);
var_dump(($status['shutdown']['ready_to_stop_components'] ?? null) === [
    'autoscaling',
    'cdn',
    'orchestrator',
    'router_loadbalancer',
    'mcp',
    'iibin',
]);

var_dump(king_system_shutdown());
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['drain_intent']['requested']);
var_dump($status['drain_intent']['reason']);
var_dump($status['drain_intent']['target_lifecycle']);
var_dump($status['drain_intent']['target_component_count']);
var_dump($status['allowed_lifecycle_transitions']);
var_dump($status['admission']['process_requests']);
var_dump(($status['components']['autoscaling']['status'] ?? null) === 'shutting_down');
var_dump(($status['components']['config']['status'] ?? null) === 'running');

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump(($status['components']['autoscaling']['status'] ?? null) === 'shutdown');
var_dump(($status['components']['orchestrator']['status'] ?? null) === 'shutdown');
var_dump(($status['components']['semantic_dns']['status'] ?? null) === 'shutting_down');
var_dump(($status['components']['object_store']['status'] ?? null) === 'shutting_down');
var_dump(($status['components']['telemetry']['status'] ?? null) === 'shutting_down');
var_dump(($status['components']['server']['status'] ?? null) === 'shutting_down');
var_dump(($status['components']['client']['status'] ?? null) === 'running');
var_dump(($status['components']['config']['status'] ?? null) === 'running');

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump(($status['components']['semantic_dns']['status'] ?? null) === 'shutdown');
var_dump(($status['components']['object_store']['status'] ?? null) === 'shutdown');
var_dump(($status['components']['telemetry']['status'] ?? null) === 'shutdown');
var_dump(($status['components']['server']['status'] ?? null) === 'shutdown');
var_dump(($status['components']['client']['status'] ?? null) === 'shutting_down');
var_dump(($status['components']['config']['status'] ?? null) === 'running');

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump(($status['components']['client']['status'] ?? null) === 'shutdown');
var_dump(($status['components']['config']['status'] ?? null) === 'shutting_down');

sleep(1);
$status = king_system_get_status();
var_dump($status['initialized']);
var_dump($status['lifecycle']);
var_dump($status['component_count']);
?>
--EXPECT--
bool(true)
string(5) "ready"
bool(true)
bool(true)
bool(true)
string(8) "draining"
bool(true)
string(15) "system_shutdown"
string(7) "stopped"
int(12)
array(2) {
  [0]=>
  string(6) "failed"
  [1]=>
  string(7) "stopped"
}
bool(false)
bool(true)
bool(true)
string(8) "draining"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(8) "draining"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(8) "draining"
bool(true)
bool(true)
bool(false)
string(7) "stopped"
int(0)
