--TEST--
King error accessors expose the shared runtime error buffer
--FILE--
<?php
var_dump(king_get_last_error());
var_dump(king_client_websocket_get_last_error());
var_dump(king_mcp_get_error());
?>
--EXPECT--
string(0) ""
string(0) ""
string(0) ""
