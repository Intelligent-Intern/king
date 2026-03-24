--TEST--
King MCP procedural upload and download helpers share the active local transfer store
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$storagePath = sys_get_temp_dir() . '/king_mcp_tests_' . getmypid();
king_object_store_init(['storage_root_path' => $storagePath]);
$connection = king_mcp_connect('127.0.0.1', 8443, null);
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
bool(true)
string(0) ""
int(10)
bool(true)
string(0) ""
string(10) "alpha-beta"
