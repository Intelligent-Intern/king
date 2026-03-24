--TEST--
King health exposes the active runtime inventory without residual stub groups
--FILE--
<?php
$health = king_health();

var_dump($health['active_runtime_count']);
var_dump($health['active_runtimes']);
var_dump($health['stubbed_api_group_count']);
var_dump($health['stubbed_api_groups']);
?>
--EXPECT--
int(30)
array(30) {
  [0]=>
  string(6) "config"
  [1]=>
  string(22) "client_session_runtime"
  [2]=>
  string(18) "client_tls_runtime"
  [3]=>
  string(15) "tls_ticket_ring"
  [4]=>
  string(20) "client_http1_runtime"
  [5]=>
  string(20) "client_http2_runtime"
  [6]=>
  string(20) "client_http3_runtime"
  [7]=>
  string(24) "client_websocket_runtime"
  [8]=>
  string(22) "server_session_runtime"
  [9]=>
  string(20) "server_index_runtime"
  [10]=>
  string(20) "server_http1_runtime"
  [11]=>
  string(20) "server_http2_runtime"
  [12]=>
  string(20) "server_http3_runtime"
  [13]=>
  string(21) "server_cancel_runtime"
  [14]=>
  string(26) "server_early_hints_runtime"
  [15]=>
  string(32) "server_websocket_upgrade_runtime"
  [16]=>
  string(24) "server_admin_api_runtime"
  [17]=>
  string(18) "server_tls_runtime"
  [18]=>
  string(19) "server_cors_runtime"
  [19]=>
  string(29) "server_open_telemetry_runtime"
  [20]=>
  string(11) "iibin_proto"
  [21]=>
  string(21) "semantic_dns_registry"
  [22]=>
  string(27) "semantic_dns_server_runtime"
  [23]=>
  string(21) "object_store_registry"
  [24]=>
  string(18) "cdn_cache_registry"
  [25]=>
  string(11) "mcp_runtime"
  [26]=>
  string(29) "pipeline_orchestrator_runtime"
  [27]=>
  string(17) "telemetry_runtime"
  [28]=>
  string(19) "autoscaling_runtime"
  [29]=>
  string(26) "system_integration_runtime"
}
int(0)
array(0) {
}
