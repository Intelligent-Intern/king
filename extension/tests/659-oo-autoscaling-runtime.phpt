--TEST--
King Autoscaling OO facade shares the same public autoscaling runtime contract
--FILE--
<?php
var_dump(King\Autoscaling::init([]));

$status = King\Autoscaling::getStatus();
var_dump($status['initialized']);
var_dump($status['monitoring_active']);

$metrics = King\Autoscaling::getMetrics();
var_dump(array_key_exists('cpu_utilization', $metrics));
var_dump(is_array(King\Autoscaling::getNodes()));

var_dump(King\Autoscaling::startMonitoring());
$status = king_autoscaling_get_status();
var_dump($status['monitoring_active']);

var_dump(King\Autoscaling::stopMonitoring());
$status = King\Autoscaling::getStatus();
var_dump($status['monitoring_active']);
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
