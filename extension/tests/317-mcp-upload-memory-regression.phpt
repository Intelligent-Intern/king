--TEST--
King MCP repeated upload teardown stays bounded under a low memory limit
--INI--
king.security_allow_config_override=1
memory_limit=16M
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$payload = str_repeat('a', 512 * 1024);
$server = king_mcp_test_start_server();

for ($i = 0; $i < 48; $i++) {
    $connection = king_mcp_connect('127.0.0.1', $server['port'], null);
    $source = fopen('php://temp', 'w+');
    fwrite($source, $payload);
    rewind($source);

    $uploaded = king_mcp_upload_from_stream($connection, 'svc', 'blob', 'asset-1', $source);
    fclose($source);
    king_mcp_close($connection);
    unset($source, $connection);
    gc_collect_cycles();

    if (!$uploaded) {
        echo "upload-failed-$i\n";
        break;
    }
}

echo "done\n";
king_mcp_test_stop_server($server);
?>
--EXPECT--
done
