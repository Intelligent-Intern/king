--TEST--
King MCP repeated upload teardown stays bounded under a low memory limit
--INI--
king.security_allow_config_override=1
memory_limit=16M
--FILE--
<?php
$storagePath = sys_get_temp_dir() . '/king_mcp_memory_regression_' . getmypid();
$payload = str_repeat('a', 512 * 1024);

king_object_store_init(['storage_root_path' => $storagePath]);

for ($i = 0; $i < 48; $i++) {
    $connection = king_mcp_connect('127.0.0.1', 8443, null);
    $source = fopen('php://temp', 'w+');
    fwrite($source, $payload);
    rewind($source);

    if (!king_mcp_upload_from_stream($connection, 'svc', 'blob', 'asset-1', $source)) {
        echo "upload-failed-$i\n";
        break;
    }

    fclose($source);
    unset($source, $connection);
    gc_collect_cycles();
}

echo "done\n";

if (is_dir($storagePath)) {
    foreach (scandir($storagePath) as $file) {
        if ($file !== '.' && $file !== '..') {
            @unlink($storagePath . '/' . $file);
        }
    }
    @rmdir($storagePath);
}
?>
--EXPECT--
done
