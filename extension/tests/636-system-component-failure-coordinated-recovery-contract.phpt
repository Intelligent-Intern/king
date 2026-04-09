--TEST--
King system coordinates component failure recovery through one visible failed to draining to starting to ready path
--FILE--
<?php
function king_system_wait_until_ready_for_component_recovery(int $maxSeconds = 12): array
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['lifecycle'] ?? null) === 'ready') {
            return $status;
        }

        sleep(1);
    }

    throw new RuntimeException('system did not become ready before component recovery scenario');
}

function king_system_wait_for_recovery_path(int $maxSeconds = 20): array
{
    $draining = null;
    $ready = null;
    $seen = [];
    $starting = null;

    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        $lifecycle = (string) ($status['lifecycle'] ?? '');

        if ($lifecycle !== '' && ($seen === [] || $seen[count($seen) - 1] !== $lifecycle)) {
            $seen[] = $lifecycle;
        }

        if ($lifecycle === 'draining' && $draining === null) {
            $draining = $status;
        }

        if ($lifecycle === 'starting' && $starting === null) {
            $starting = $status;
        }

        if ($lifecycle === 'ready') {
            $ready = $status;
            break;
        }

        sleep(1);
    }

    if ($draining === null || $starting === null || $ready === null) {
        throw new RuntimeException('system did not expose the full coordinated recovery path');
    }

    return [
        'draining' => $draining,
        'ready' => $ready,
        'seen' => $seen,
        'starting' => $starting,
    ];
}

function king_system_wait_until_stopped_after_component_recovery(int $maxSeconds = 12): array
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['initialized'] ?? true) === false) {
            return $status;
        }

        sleep(1);
    }

    throw new RuntimeException('system did not stop after component recovery scenario');
}

var_dump(king_system_init(['component_timeout_seconds' => 1]));
king_system_wait_until_ready_for_component_recovery();

var_dump(king_system_fail_component('telemetry'));
$failed = king_system_get_status();
var_dump($failed['lifecycle']);
var_dump($failed['components']['telemetry']['status']);
var_dump($failed['components']['telemetry']['readiness_reason']);
var_dump($failed['components']['telemetry']['errors_encountered']);
var_dump($failed['readiness_blocker_count']);
var_dump($failed['recovery']['mode']);
var_dump($failed['drain_intent']['reason']);
var_dump($failed['admission']['process_requests']);
var_dump(king_system_process_request([]));
var_dump(str_contains(king_get_last_error(), "lifecycle is 'failed'"));
var_dump($failed['allowed_lifecycle_transitions']);

var_dump(king_system_recover());
$recovery = king_system_wait_for_recovery_path();
$draining = $recovery['draining'];
$starting = $recovery['starting'];
$ready = $recovery['ready'];

var_dump($recovery['seen']);
var_dump($draining['drain_intent']['requested']);
var_dump($draining['drain_intent']['active']);
var_dump($draining['drain_intent']['reason']);
var_dump($draining['drain_intent']['target_lifecycle']);
var_dump(in_array('telemetry', $draining['drain_intent']['target_components'], true));
var_dump($draining['admission']['process_requests']);
var_dump($draining['allowed_lifecycle_transitions']);
var_dump($draining['recovery']['mode']);
var_dump((bool) $draining['recovery']['plan_id']);
var_dump(str_starts_with($draining['recovery']['plan_id'], 'component_failure:'));
var_dump($draining['recovery']['plan_window_seconds']);

var_dump($starting['lifecycle']);
var_dump($starting['drain_intent']['reason']);
var_dump($starting['admission']['process_requests']);
var_dump($starting['recovery']['mode']);
var_dump($starting['recovery']['plan_requested_at'] > 0);
var_dump($starting['recovery']['plan_window_seconds']);
var_dump($starting['recovery']['active']);

var_dump($ready['lifecycle']);
var_dump($ready['components']['telemetry']['status']);
var_dump($ready['components']['telemetry']['readiness_reason']);
var_dump($ready['components']['telemetry']['errors_encountered']);
var_dump($ready['components_ready'] === $ready['component_count']);
var_dump($ready['readiness_blocker_count']);
var_dump($ready['admission']['process_requests']);
var_dump($ready['admission']['remote_peer_dispatches']);
var_dump($ready['recovery']['active']);
var_dump($ready['recovery']['mode']);

var_dump(king_system_shutdown());
$stopped = king_system_wait_until_stopped_after_component_recovery();
var_dump($stopped['initialized']);
var_dump($stopped['lifecycle']);
?>
--EXPECT--
bool(true)
bool(true)
string(6) "failed"
string(5) "error"
string(16) "component_failed"
int(1)
string(4) "none"
int(1)
string(4) "none"
bool(false)
bool(false)
bool(true)
array(2) {
  [0]=>
  string(8) "draining"
  [1]=>
  string(7) "stopped"
}
bool(true)
array(3) {
  [0]=>
  string(8) "draining"
  [1]=>
  string(8) "starting"
  [2]=>
  string(5) "ready"
}
bool(true)
bool(true)
string(18) "component_recovery"
string(5) "ready"
bool(true)
bool(false)
array(3) {
  [0]=>
  string(8) "starting"
  [1]=>
  string(6) "failed"
  [2]=>
  string(7) "stopped"
}
string(17) "component_failure"
bool(true)
bool(true)
int(15)
string(8) "starting"
string(18) "component_recovery"
bool(false)
string(17) "component_failure"
bool(true)
int(15)
bool(true)
string(5) "ready"
string(7) "running"
string(5) "ready"
int(1)
bool(true)
int(0)
bool(true)
bool(false)
string(17) "component_failure"
bool(true)
bool(false)
string(7) "stopped"
