--TEST--
King\Config lifecycle returns a resource
--FILE--
<?php
$cfg = king_new_config();
var_dump(is_resource($cfg));
var_dump(get_resource_type($cfg));
?>
--EXPECT--
bool(true)
string(11) "King\Config"
