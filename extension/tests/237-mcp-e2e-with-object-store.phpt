--TEST--
King MCP end-to-end verification: ensure connections use Object Store persistence for stream data
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$storage_path = sys_get_temp_dir() . '/king_e2e_mcp_' . getmypid();
king_object_store_init(['storage_root_path' => $storage_path]);

$connection = king_mcp_connect('127.0.0.1', 8443, null);

$source = fopen('php://temp', 'w+');
fwrite($source, str_repeat("D", 100));
rewind($source);

// 1. Upload
var_dump(king_mcp_upload_from_stream($connection, 'cloud', 'push', 'e2e-1', $source));

// 2. Verify it exists in the Object Store manually
$expected_file = $storage_path . '/mcp-cloud:push:e2e-1';
var_dump(file_exists($expected_file));
var_dump(filesize($expected_file));

// 3. Download
$dest = fopen('php://temp', 'w+');
var_dump(king_mcp_download_to_stream($connection, 'cloud', 'push', 'e2e-1', $dest));
rewind($dest);
var_dump(strlen(stream_get_contents($dest)));

king_mcp_close($connection);

if (is_dir($storage_path)) {
    foreach (scandir($storage_path) as $file) {
        if ($file !== '.' && $file !== '..') {
            @unlink($storage_path . '/' . $file);
        }
    }
    @rmdir($storage_path);
}
?>
--EXPECT--
bool(true)
bool(true)
int(100)
bool(true)
int(100)
