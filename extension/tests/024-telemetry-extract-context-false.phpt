--TEST--
King telemetry extract-context returns false in the current runtime
--FILE--
<?php
var_dump(king_telemetry_extract_context([]));
var_dump(king_telemetry_extract_context([
    'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
]));
?>
--EXPECT--
bool(false)
bool(false)
