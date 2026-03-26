--TEST--
King MCP OO wrapper exposes the active remote upload and download transfer helpers
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$server = king_mcp_test_start_server();
try {
    $mcp = new King\MCP('127.0.0.1', $server['port']);
    $source = fopen('php://temp', 'w+');
    $destination = fopen('php://temp', 'w+');

    fwrite($source, "payload-42");
    rewind($source);

    $mcp->uploadFromStream('svc', 'blob', 'asset-2', $source);
    $mcp->downloadToStream('svc', 'blob', 'asset-2', $destination);

    rewind($destination);
    var_dump(stream_get_contents($destination));

    $mcp->close();

    try {
        $mcp->downloadToStream('svc', 'blob', 'asset-2', fopen('php://temp', 'w+'));
        echo "no-exception\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }
} finally {
    king_mcp_test_stop_server($server);
}
?>
--EXPECTF--
string(10) "payload-42"
string(21) "King\RuntimeException"
string(%d) "MCP::downloadToStream() cannot run on a closed MCP connection."
