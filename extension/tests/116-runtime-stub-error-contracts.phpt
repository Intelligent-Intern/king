--TEST--
King active websocket frames, MCP error aliases, and orchestrator runtime share a stable error contract
--FILE--
<?php
$websocket = king_client_websocket_connect('ws://127.0.0.1/');
var_dump(is_resource($websocket));
var_dump(king_client_websocket_send($websocket, 'ping'));
var_dump(king_get_last_error());

var_dump(king_pipeline_orchestrator_run([], []));
var_dump(king_get_last_error());
var_dump(king_mcp_get_error());
var_dump(king_client_websocket_get_last_error());
?>
--EXPECTF--
bool(true)
bool(true)
string(0) ""
array(0) {
}
string(0) ""
string(0) ""
string(0) ""
