--TEST--
King MCP OO wrapper keeps the active cancel-token contract explicit
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$server = king_mcp_test_start_server();
try {
    $mcp = new King\MCP('127.0.0.1', $server['port']);
    $cancelled = new King\CancelToken();
    $cancelled->cancel();

    try {
        $mcp->request('svc', 'ping', '{}', $cancelled);
        echo "no-exception-1\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    $pending = new King\CancelToken();
    var_dump($mcp->request('svc', 'ping', '{}', $pending) === '{"res":"{}"}');
    $mcp->close();
} finally {
    king_mcp_test_stop_server($server);
}
?>
--EXPECT--
string(21) "King\RuntimeException"
string(66) "MCP::request() cancelled the active MCP operation via CancelToken."
bool(true)
