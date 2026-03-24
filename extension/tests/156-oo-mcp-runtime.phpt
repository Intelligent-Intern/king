--TEST--
King MCP OO wrapper shares the local runtime connection state
--FILE--
<?php
$mcp = new King\MCP('127.0.0.1', 8443);

var_dump($mcp->request('svc', 'ping', '{}'));

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
string(12) "{"res":"{}"}"
string(21) "King\RuntimeException"
string(53) "MCP::request() cannot run on a closed MCP connection."
