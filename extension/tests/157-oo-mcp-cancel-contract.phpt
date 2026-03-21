--TEST--
King MCP OO wrapper keeps the active cancel-token contract explicit
--FILE--
<?php
$mcp = new King\MCP('127.0.0.1', 8443);
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

try {
    $mcp->request('svc', 'ping', '{}', $pending);
    echo "no-exception-2\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECT--
string(21) "King\RuntimeException"
string(64) "MCP::request() received a CancelToken that is already cancelled."
string(25) "King\MCPProtocolException"
string(82) "MCP::request() cancel tokens are not yet wired in the active skeleton MCP runtime."
