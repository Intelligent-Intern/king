--TEST--
King system status exposes explicit drain intent and allowed lifecycle transitions for the coordinated runtime
--FILE--
<?php
function king_system_wait_until_ready_for_drain_intent(int $maxSeconds = 8): void
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['lifecycle'] ?? null) === 'ready') {
            return;
        }

        sleep(1);
    }

    throw new RuntimeException('system did not become ready before drain intent scenario');
}

$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['drain_intent']['requested']);
var_dump($status['drain_intent']['active']);
var_dump($status['drain_intent']['reason']);
var_dump($status['drain_intent']['requested_at']);
var_dump($status['drain_intent']['target_lifecycle']);
var_dump($status['drain_intent']['target_component_count']);
var_dump($status['drain_intent']['target_components']);
var_dump($status['allowed_lifecycle_transitions']);

var_dump(king_system_init(['component_timeout_seconds' => 1]));
king_system_wait_until_ready_for_drain_intent();
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['drain_intent']['requested']);
var_dump($status['drain_intent']['active']);
var_dump($status['drain_intent']['reason']);
var_dump($status['drain_intent']['requested_at']);
var_dump($status['drain_intent']['target_lifecycle']);
var_dump($status['drain_intent']['target_component_count']);
var_dump($status['drain_intent']['target_components']);
var_dump($status['allowed_lifecycle_transitions']);

var_dump(king_system_restart_component('telemetry'));
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['drain_intent']['requested']);
var_dump($status['drain_intent']['active']);
var_dump($status['drain_intent']['reason']);
var_dump($status['drain_intent']['requested_at'] > 0);
var_dump($status['drain_intent']['target_lifecycle']);
var_dump($status['drain_intent']['target_component_count']);
var_dump($status['drain_intent']['target_components']);
var_dump($status['allowed_lifecycle_transitions']);

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['drain_intent']['requested']);
var_dump($status['drain_intent']['active']);
var_dump($status['drain_intent']['reason']);
var_dump($status['drain_intent']['requested_at']);
var_dump($status['drain_intent']['target_lifecycle']);
var_dump($status['drain_intent']['target_component_count']);
var_dump($status['drain_intent']['target_components']);
var_dump($status['allowed_lifecycle_transitions']);

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['drain_intent']['requested']);
var_dump($status['drain_intent']['active']);
var_dump($status['drain_intent']['reason']);
var_dump($status['drain_intent']['requested_at']);
var_dump($status['drain_intent']['target_lifecycle']);
var_dump($status['drain_intent']['target_component_count']);
var_dump($status['drain_intent']['target_components']);
var_dump($status['allowed_lifecycle_transitions']);

var_dump(king_system_shutdown());
?>
--EXPECT--
string(7) "stopped"
bool(false)
bool(false)
string(4) "none"
int(0)
NULL
int(0)
array(0) {
}
array(1) {
  [0]=>
  string(5) "ready"
}
bool(true)
string(5) "ready"
bool(false)
bool(false)
string(4) "none"
int(0)
NULL
int(0)
array(0) {
}
array(3) {
  [0]=>
  string(8) "draining"
  [1]=>
  string(6) "failed"
  [2]=>
  string(7) "stopped"
}
bool(true)
string(8) "draining"
bool(true)
bool(true)
string(17) "component_restart"
bool(true)
string(8) "starting"
int(1)
array(1) {
  [0]=>
  string(9) "telemetry"
}
array(3) {
  [0]=>
  string(8) "starting"
  [1]=>
  string(6) "failed"
  [2]=>
  string(7) "stopped"
}
string(8) "starting"
bool(false)
bool(false)
string(4) "none"
int(0)
NULL
int(0)
array(0) {
}
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
string(5) "ready"
bool(false)
bool(false)
string(4) "none"
int(0)
NULL
int(0)
array(0) {
}
array(3) {
  [0]=>
  string(8) "draining"
  [1]=>
  string(6) "failed"
  [2]=>
  string(7) "stopped"
}
bool(true)
