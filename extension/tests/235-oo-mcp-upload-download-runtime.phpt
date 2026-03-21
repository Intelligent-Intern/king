--TEST--
King MCP OO wrapper exposes the active local upload and download transfer helpers
--FILE--
<?php
$mcp = new King\MCP('127.0.0.1', 8443);
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
?>
--EXPECTF--
string(10) "payload-42"
string(21) "King\RuntimeException"
string(%d) "MCP::downloadToStream() cannot run on a closed MCP connection."
