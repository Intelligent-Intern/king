--TEST--
King\Config snapshot lifecycle does not disturb module-global config surfaces
--FILE--
<?php
$cfg = king_new_config([]);
var_dump(is_resource($cfg));
var_dump(get_resource_type($cfg));

unset($cfg);

$telemetry = king_system_get_component_info('telemetry');
var_dump($telemetry['implementation']);
var_dump(is_bool($telemetry['configuration']['enabled']));
var_dump(is_string($telemetry['configuration']['service_name']));
?>
--EXPECT--
bool(true)
string(11) "King\Config"
string(13) "config_backed"
bool(true)
bool(true)
