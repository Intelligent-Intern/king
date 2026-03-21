--TEST--
King MCP upload and download helpers validate local transfer arguments and missing payloads
--FILE--
<?php
$connection = king_mcp_connect('127.0.0.1', 8443, null);
$source = fopen('php://temp', 'w+');
$destination = fopen('php://temp', 'w+');

var_dump(king_mcp_upload_from_stream($connection, '', 'blob', 'asset-1', $source));
var_dump(king_mcp_get_error());

var_dump(king_mcp_download_to_stream($connection, 'svc', 'blob', 'missing', $destination));
var_dump(king_mcp_get_error());

$mcp = new King\MCP('127.0.0.1', 8443);

try {
    $mcp->uploadFromStream('', 'blob', 'asset-1', fopen('php://temp', 'w+'));
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    $mcp->downloadToStream('svc', 'blob', 'missing', fopen('php://temp', 'w+'));
    echo "no-exception-2\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECTF--
bool(false)
string(%d) "king_mcp_upload_from_stream() requires a non-empty service name."
bool(false)
string(%d) "king_mcp_download_to_stream() could not find a local MCP transfer for the requested payload identifier."
string(24) "King\ValidationException"
string(%d) "MCP::uploadFromStream() requires a non-empty service name."
string(21) "King\MCPDataException"
string(%d) "MCP::downloadToStream() could not find a local MCP transfer for the requested payload identifier."
