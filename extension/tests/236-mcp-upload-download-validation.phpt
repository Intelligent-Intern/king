--TEST--
King MCP upload and download helpers validate remote transfer arguments and missing payloads
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$server = king_mcp_test_start_server();
try {
    $connection = king_mcp_connect('127.0.0.1', $server['port'], null);
    $source = fopen('php://temp', 'w+');
    $destination = fopen('php://temp', 'w+');

    var_dump(king_mcp_upload_from_stream($connection, '', 'blob', 'asset-1', $source));
    var_dump(king_mcp_get_error());

    var_dump(king_mcp_download_to_stream($connection, 'svc', 'blob', 'missing', $destination));
    var_dump(king_mcp_get_error());

    var_dump(king_mcp_upload_from_stream($connection, '../svc', 'blob', 'asset-1', $source));
    var_dump(king_mcp_get_error());

    var_dump(king_mcp_upload_from_stream($connection, 'svc', 'blob', '../asset-1', $source));
    var_dump(king_mcp_get_error());

    king_mcp_close($connection);

    $mcp = new King\MCP('127.0.0.1', $server['port']);

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

    try {
        $mcp->uploadFromStream('../svc', 'blob', 'asset-1', fopen('php://temp', 'w+'));
        echo "no-exception-3\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    try {
        $mcp->uploadFromStream('svc', 'blob', '../asset-1', fopen('php://temp', 'w+'));
        echo "no-exception-4\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    var_dump(king_mcp_close($connection));
    $mcp->close();
} finally {
    king_mcp_test_stop_server($server);
}
?>
--EXPECTF--
bool(false)
string(%d) "king_mcp_upload_from_stream() requires a non-empty service name."
bool(false)
string(%d) "king_mcp_download_to_stream() could not find a remote MCP transfer for the requested payload identifier."
bool(false)
string(%d) "king_mcp_upload_from_stream() service name must not contain path separator characters."
bool(false)
string(%d) "king_mcp_upload_from_stream() stream identifier must not contain path separator characters."
string(24) "King\ValidationException"
string(%d) "MCP::uploadFromStream() requires a non-empty service name."
string(21) "King\MCPDataException"
string(%d) "MCP::downloadToStream() could not find a remote MCP transfer for the requested payload identifier."
string(24) "King\ValidationException"
string(%d) "MCP::uploadFromStream() service name must not contain path separator characters."
string(24) "King\ValidationException"
string(%d) "MCP::uploadFromStream() stream identifier must not contain path separator characters."
bool(true)
