--TEST--
King client stream-cancel requires a session resource in the skeleton build
--FILE--
<?php
var_dump(king_client_stream_cancel(5));
var_dump(king_get_last_error());
?>
--EXPECT--
bool(false)
string(83) "king_client_stream_cancel() requires a King\Session resource in the skeleton build."
