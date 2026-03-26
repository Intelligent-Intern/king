--TEST--
King retires the empty WebSocket Server placeholder from the exported v1 OO surface
--FILE--
<?php
var_dump(class_exists('King\\WebSocket\\Server', false));
var_dump(class_exists('King\\WebSocket\\Connection', false));
?>
--EXPECT--
bool(false)
bool(true)
