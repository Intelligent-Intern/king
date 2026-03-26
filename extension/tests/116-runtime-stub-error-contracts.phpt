--TEST--
King active websocket frames, MCP error aliases, and orchestrator runtime share a stable error contract
--FILE--
<?php
require __DIR__ . '/websocket_test_helper.inc';

$server = king_websocket_test_start_server();

try {
    $websocket = king_client_websocket_connect('ws://127.0.0.1:' . $server['port'] . '/');
    var_dump(is_resource($websocket));
    var_dump(king_client_websocket_send($websocket, 'ping'));
    var_dump(king_get_last_error());

    var_dump(king_pipeline_orchestrator_run([], []));
    var_dump(king_get_last_error());
    var_dump(king_mcp_get_error());
    var_dump(king_client_websocket_get_last_error());

    king_client_websocket_close($websocket, 1000, 'done');
} finally {
    king_websocket_test_stop_server($server);
}

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
