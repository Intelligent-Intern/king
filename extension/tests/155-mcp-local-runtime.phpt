--TEST--
King MCP procedural runtime exchanges requests with a remote peer and preserves closed-connection errors
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$server = king_mcp_test_start_server();
try {
    $conn = king_mcp_connect('127.0.0.1', $server['port'], null);
    var_dump(is_resource($conn));

    var_dump(king_mcp_request($conn, 'svc', 'ping', '{}'));
    var_dump(king_mcp_get_error());

    var_dump(king_mcp_close($conn));
    var_dump(king_mcp_request($conn, 'svc', 'ping', '{}'));
    var_dump(king_mcp_get_error());
} finally {
    king_mcp_test_stop_server($server);
}
?>
--EXPECT--
bool(true)
string(12) "{"res":"{}"}"
string(0) ""
bool(true)
bool(false)
string(57) "king_mcp_request() cannot run on a closed MCP connection."
