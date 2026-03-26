--TEST--
King MCP OO wrapper shares the remote MCP runtime connection state
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$server = king_mcp_test_start_server();
try {
    $mcp = new King\MCP('127.0.0.1', $server['port']);

    var_dump($mcp->request('svc', 'ping', '{}'));

    $mcp->close();

    try {
        $mcp->request('svc', 'ping', '{}');
        echo "no-exception-2\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }
} finally {
    king_mcp_test_stop_server($server);
}
?>
--EXPECT--
string(12) "{"res":"{}"}"
string(21) "King\RuntimeException"
string(53) "MCP::request() cannot run on a closed MCP connection."
