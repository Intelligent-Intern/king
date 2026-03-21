--TEST--
King MCP OO wrapper shares the local skeleton connection state
--FILE--
<?php
$mcp = new King\MCP('127.0.0.1', 8443);

try {
    $mcp->request('svc', 'ping', '{}');
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

$mcp->close();

try {
    $mcp->request('svc', 'ping', '{}');
    echo "no-exception-2\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECT--
string(25) "King\MCPProtocolException"
string(67) "MCP::request() is not available in the active skeleton MCP runtime."
string(21) "King\RuntimeException"
string(53) "MCP::request() cannot run on a closed MCP connection."
