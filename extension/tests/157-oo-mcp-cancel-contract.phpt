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
var_dump($mcp->request('svc', 'ping', '{}', $pending) === '{"res":"{}"}');
?>
--EXPECT--
string(21) "King\RuntimeException"
string(66) "MCP::request() cancelled the active MCP operation via CancelToken."
bool(true)
