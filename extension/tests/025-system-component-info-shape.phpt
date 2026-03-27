--TEST--
King system component info exposes stable runtime descriptors
--FILE--
<?php
$telemetry = king_system_get_component_info('telemetry');
var_dump(array_keys($telemetry));
var_dump($telemetry['name']);
var_dump($telemetry['implementation']);
var_dump(array_keys($telemetry['configuration']));
var_dump(is_bool($telemetry['configuration']['enabled']));
var_dump(is_int($telemetry['info_generated_at']));

$client = king_system_get_component_info('client');
var_dump($client['implementation']);
var_dump($client['configuration']);

$server = king_system_get_component_info('server');
var_dump($server['implementation']);
var_dump($server['configuration']);

var_dump(king_system_get_component_info('missing_component'));
?>
--EXPECT--
array(6) {
  [0]=>
  string(4) "name"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(14) "implementation"
  [4]=>
  string(13) "configuration"
  [5]=>
  string(17) "info_generated_at"
}
string(9) "telemetry"
string(13) "config_backed"
array(10) {
  [0]=>
  string(7) "enabled"
  [1]=>
  string(12) "service_name"
  [2]=>
  string(17) "exporter_endpoint"
  [3]=>
  string(17) "exporter_protocol"
  [4]=>
  string(14) "metrics_enable"
  [5]=>
  string(11) "logs_enable"
  [6]=>
  string(17) "delivery_contract"
  [7]=>
  string(17) "queue_persistence"
  [8]=>
  string(14) "restart_replay"
  [9]=>
  string(14) "drain_behavior"
}
bool(true)
bool(true)
string(13) "local_runtime"
array(0) {
}
string(13) "local_runtime"
array(0) {
}
bool(false)
