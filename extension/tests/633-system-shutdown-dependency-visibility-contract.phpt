--TEST--
King system status exposes the canonical shutdown dependency graph and stop visibility
--FILE--
<?php
function king_system_wait_until_ready_for_shutdown_visibility(int $maxSeconds = 8): array
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['lifecycle'] ?? null) === 'ready') {
            return $status;
        }

        sleep(1);
    }

    throw new RuntimeException('system did not become ready before shutdown visibility scenario');
}

$status = king_system_get_status();
var_dump($status['shutdown']['ordered_components']);
var_dump($status['shutdown']['inactive_components']);
var_dump($status['shutdown']['active_components']);
var_dump($status['shutdown']['ready_to_stop_components']);
var_dump($status['shutdown']['blocked_components']);
var_dump($status['shutdown']['components']['autoscaling']);
var_dump($status['shutdown']['components']['config']);

var_dump(king_system_init(['component_timeout_seconds' => 1]));
$status = king_system_wait_until_ready_for_shutdown_visibility();
var_dump($status['shutdown']['active_components']);
var_dump($status['shutdown']['inactive_components']);
var_dump($status['shutdown']['ready_to_stop_components']);
var_dump($status['shutdown']['blocked_components']['semantic_dns']);
var_dump($status['shutdown']['blocked_components']['telemetry']);
var_dump($status['shutdown']['blocked_components']['config']);
var_dump($status['shutdown']['components']['autoscaling']);
var_dump($status['shutdown']['components']['config']);
var_dump($status['components']['telemetry']['shutdown_dependents']);
var_dump($status['components']['telemetry']['shutdown_pending_dependents']);
var_dump($status['components']['telemetry']['shutdown_ready_to_stop']);

var_dump(king_system_shutdown());
?>
--EXPECT--
array(12) {
  [0]=>
  string(11) "autoscaling"
  [1]=>
  string(3) "cdn"
  [2]=>
  string(12) "orchestrator"
  [3]=>
  string(19) "router_loadbalancer"
  [4]=>
  string(12) "semantic_dns"
  [5]=>
  string(3) "mcp"
  [6]=>
  string(5) "iibin"
  [7]=>
  string(12) "object_store"
  [8]=>
  string(9) "telemetry"
  [9]=>
  string(6) "server"
  [10]=>
  string(6) "client"
  [11]=>
  string(6) "config"
}
array(12) {
  [0]=>
  string(11) "autoscaling"
  [1]=>
  string(3) "cdn"
  [2]=>
  string(12) "orchestrator"
  [3]=>
  string(19) "router_loadbalancer"
  [4]=>
  string(12) "semantic_dns"
  [5]=>
  string(3) "mcp"
  [6]=>
  string(5) "iibin"
  [7]=>
  string(12) "object_store"
  [8]=>
  string(9) "telemetry"
  [9]=>
  string(6) "server"
  [10]=>
  string(6) "client"
  [11]=>
  string(6) "config"
}
array(0) {
}
array(0) {
}
array(0) {
}
array(6) {
  ["order"]=>
  int(1)
  ["dependents"]=>
  array(0) {
  }
  ["pending_dependents"]=>
  array(0) {
  }
  ["status_visible"]=>
  bool(false)
  ["started"]=>
  bool(false)
  ["ready_to_stop"]=>
  bool(false)
}
array(6) {
  ["order"]=>
  int(12)
  ["dependents"]=>
  array(11) {
    [0]=>
    string(6) "client"
    [1]=>
    string(6) "server"
    [2]=>
    string(9) "telemetry"
    [3]=>
    string(12) "object_store"
    [4]=>
    string(5) "iibin"
    [5]=>
    string(3) "mcp"
    [6]=>
    string(12) "semantic_dns"
    [7]=>
    string(19) "router_loadbalancer"
    [8]=>
    string(12) "orchestrator"
    [9]=>
    string(3) "cdn"
    [10]=>
    string(11) "autoscaling"
  }
  ["pending_dependents"]=>
  array(0) {
  }
  ["status_visible"]=>
  bool(false)
  ["started"]=>
  bool(false)
  ["ready_to_stop"]=>
  bool(false)
}
bool(true)
array(12) {
  [0]=>
  string(11) "autoscaling"
  [1]=>
  string(3) "cdn"
  [2]=>
  string(12) "orchestrator"
  [3]=>
  string(19) "router_loadbalancer"
  [4]=>
  string(12) "semantic_dns"
  [5]=>
  string(3) "mcp"
  [6]=>
  string(5) "iibin"
  [7]=>
  string(12) "object_store"
  [8]=>
  string(9) "telemetry"
  [9]=>
  string(6) "server"
  [10]=>
  string(6) "client"
  [11]=>
  string(6) "config"
}
array(0) {
}
array(6) {
  [0]=>
  string(11) "autoscaling"
  [1]=>
  string(3) "cdn"
  [2]=>
  string(12) "orchestrator"
  [3]=>
  string(19) "router_loadbalancer"
  [4]=>
  string(3) "mcp"
  [5]=>
  string(5) "iibin"
}
array(1) {
  [0]=>
  string(11) "autoscaling"
}
array(2) {
  [0]=>
  string(12) "orchestrator"
  [1]=>
  string(11) "autoscaling"
}
array(11) {
  [0]=>
  string(6) "client"
  [1]=>
  string(6) "server"
  [2]=>
  string(9) "telemetry"
  [3]=>
  string(12) "object_store"
  [4]=>
  string(5) "iibin"
  [5]=>
  string(3) "mcp"
  [6]=>
  string(12) "semantic_dns"
  [7]=>
  string(19) "router_loadbalancer"
  [8]=>
  string(12) "orchestrator"
  [9]=>
  string(3) "cdn"
  [10]=>
  string(11) "autoscaling"
}
array(6) {
  ["order"]=>
  int(1)
  ["dependents"]=>
  array(0) {
  }
  ["pending_dependents"]=>
  array(0) {
  }
  ["status_visible"]=>
  bool(true)
  ["started"]=>
  bool(true)
  ["ready_to_stop"]=>
  bool(true)
}
array(6) {
  ["order"]=>
  int(12)
  ["dependents"]=>
  array(11) {
    [0]=>
    string(6) "client"
    [1]=>
    string(6) "server"
    [2]=>
    string(9) "telemetry"
    [3]=>
    string(12) "object_store"
    [4]=>
    string(5) "iibin"
    [5]=>
    string(3) "mcp"
    [6]=>
    string(12) "semantic_dns"
    [7]=>
    string(19) "router_loadbalancer"
    [8]=>
    string(12) "orchestrator"
    [9]=>
    string(3) "cdn"
    [10]=>
    string(11) "autoscaling"
  }
  ["pending_dependents"]=>
  array(11) {
    [0]=>
    string(6) "client"
    [1]=>
    string(6) "server"
    [2]=>
    string(9) "telemetry"
    [3]=>
    string(12) "object_store"
    [4]=>
    string(5) "iibin"
    [5]=>
    string(3) "mcp"
    [6]=>
    string(12) "semantic_dns"
    [7]=>
    string(19) "router_loadbalancer"
    [8]=>
    string(12) "orchestrator"
    [9]=>
    string(3) "cdn"
    [10]=>
    string(11) "autoscaling"
  }
  ["status_visible"]=>
  bool(true)
  ["started"]=>
  bool(true)
  ["ready_to_stop"]=>
  bool(false)
}
array(2) {
  [0]=>
  string(12) "orchestrator"
  [1]=>
  string(11) "autoscaling"
}
array(2) {
  [0]=>
  string(12) "orchestrator"
  [1]=>
  string(11) "autoscaling"
}
bool(false)
bool(true)
