--TEST--
King MCP request rejects unexpected remote MISS responses without crashing the PHP wrappers
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$server = king_mcp_test_start_server();

try {
    $connection = king_mcp_connect('127.0.0.1', $server['port'], null);
    var_dump(is_resource($connection));
    var_dump(king_mcp_request($connection, 'svc', 'miss-request', '{}'));
    var_dump(king_mcp_get_error());
    var_dump(king_mcp_close($connection));

    $mcp = new King\MCP('127.0.0.1', $server['port']);
    try {
        $mcp->request('svc', 'miss-request', '{}');
        echo "no-exception\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    } finally {
        $mcp->close();
    }
} finally {
    $capture = king_mcp_test_stop_server($server);
}

$missEvents = array_values(array_filter(
    $capture['events'] ?? [],
    static fn(array $event): bool => ($event['method'] ?? null) === 'miss-request'
        && (($event['remote_miss'] ?? false) === true)
));
var_dump(count($missEvents) === 2);
?>
--EXPECT--
bool(true)
bool(false)
string(85) "MCP request received an unexpected missing-payload response from the remote MCP peer."
bool(true)
string(25) "King\MCPProtocolException"
string(85) "MCP request received an unexpected missing-payload response from the remote MCP peer."
bool(true)
