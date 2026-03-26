--TEST--
King MCP can target an IPv6 host-port peer without a same-host-only transport assumption
--SKIPIF--
<?php
$probe = @stream_socket_server('tcp://[::1]:0', $errno, $errstr);
if ($probe === false) {
    echo "skip IPv6 loopback unavailable: $errstr";
    return;
}
fclose($probe);
?>
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$server = king_mcp_test_start_server(bindHost: '::1');
try {
    $conn = king_mcp_connect('::1', $server['port'], null);
    var_dump(is_resource($conn));
    var_dump(king_mcp_request($conn, 'svc', 'ping', '{}'));
    var_dump(king_mcp_get_error());
    var_dump(king_mcp_close($conn));
} finally {
    king_mcp_test_stop_server($server);
}
?>
--EXPECT--
bool(true)
string(12) "{"res":"{}"}"
string(0) ""
bool(true)
