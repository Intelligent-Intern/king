--TEST--
King autoscaling status exposes config-backed defaults
--FILE--
<?php
$status = king_autoscaling_get_status();
var_dump(array_keys($status));
var_dump($status['provider']);
var_dump($status['region']);
var_dump($status['min_nodes']);
var_dump($status['max_nodes']);
var_dump($status['scale_up_cpu_threshold_percent']);
var_dump($status['scale_down_cpu_threshold_percent']);
var_dump($status['scale_up_policy']);
var_dump($status['cooldown_period_sec']);
var_dump($status['idle_node_timeout_sec']);
var_dump($status['instance_type']);
var_dump($status['instance_image_id']);
var_dump($status['network_config']);
var_dump($status['instance_tags']);
?>
--EXPECT--
array(13) {
  [0]=>
  string(8) "provider"
  [1]=>
  string(6) "region"
  [2]=>
  string(9) "min_nodes"
  [3]=>
  string(9) "max_nodes"
  [4]=>
  string(30) "scale_up_cpu_threshold_percent"
  [5]=>
  string(32) "scale_down_cpu_threshold_percent"
  [6]=>
  string(15) "scale_up_policy"
  [7]=>
  string(19) "cooldown_period_sec"
  [8]=>
  string(21) "idle_node_timeout_sec"
  [9]=>
  string(13) "instance_type"
  [10]=>
  string(17) "instance_image_id"
  [11]=>
  string(14) "network_config"
  [12]=>
  string(13) "instance_tags"
}
string(0) ""
string(0) ""
int(1)
int(1)
int(80)
int(20)
string(11) "add_nodes:1"
int(300)
int(600)
string(0) ""
string(0) ""
string(0) ""
string(0) ""
