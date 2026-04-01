--TEST--
King MCP rejects invalid peer targets before opening transport
--FILE--
<?php
var_dump(king_mcp_connect("127.0.0.1\npeer", 7001, null));
var_dump(king_mcp_get_error());

var_dump(king_mcp_connect('127.0.0.1', 0, null));
var_dump(king_mcp_get_error());

try {
    new King\MCP('bad/host', 7001);
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECTF--
bool(false)
string(%d) "MCP peer host contains unsupported whitespace or control characters."
bool(false)
string(%d) "MCP peer port must be between 1 and 65535."
string(24) "King\ValidationException"
string(%d) "MCP peer host contains unsupported characters."
