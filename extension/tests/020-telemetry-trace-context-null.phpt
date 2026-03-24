--TEST--
King telemetry trace-context getter returns null in the current runtime
--FILE--
<?php
var_dump(king_telemetry_get_trace_context());
?>
--EXPECT--
NULL
