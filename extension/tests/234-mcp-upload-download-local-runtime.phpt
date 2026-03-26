--TEST--
King MCP procedural upload and download helpers exchange transfer bytes with a remote peer
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

    fwrite($source, "alpha-beta");
    rewind($source);

    var_dump(king_mcp_upload_from_stream($connection, 'svc', 'blob', 'asset-1', $source));
    var_dump(king_mcp_get_error());
    var_dump(ftell($source));

    var_dump(king_mcp_download_to_stream($connection, 'svc', 'blob', 'asset-1', $destination));
    var_dump(king_mcp_get_error());

    rewind($destination);
    var_dump(stream_get_contents($destination));
    var_dump(king_mcp_close($connection));
} finally {
    king_mcp_test_stop_server($server);
}
?>
--EXPECT--
bool(true)
string(0) ""
int(10)
bool(true)
string(0) ""
string(10) "alpha-beta"
bool(true)
