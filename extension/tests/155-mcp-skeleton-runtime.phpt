--TEST--
King MCP procedural skeleton runtime exposes local connection lifecycle and stable unavailable errors
--FILE--
<?php
$conn = king_mcp_connect('127.0.0.1', 8443, null);
var_dump(is_resource($conn));

var_dump(king_mcp_request($conn, 'svc', 'ping', '{}'));
var_dump(king_mcp_get_error());

var_dump(king_mcp_close($conn));
var_dump(king_mcp_request($conn, 'svc', 'ping', '{}'));
var_dump(king_mcp_get_error());
?>
--EXPECT--
bool(true)
bool(false)
string(71) "king_mcp_request() is not available in the active skeleton MCP runtime."
bool(true)
bool(false)
string(57) "king_mcp_request() cannot run on a closed MCP connection."
