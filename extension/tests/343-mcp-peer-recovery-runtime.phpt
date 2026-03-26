--TEST--
King MCP runtime recovers after peer disconnects and restart on the same host
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$firstCapture = [];
$secondCapture = [];
$server = king_mcp_test_start_server();
$port = $server['port'];
$connection = king_mcp_connect('127.0.0.1', $port, null);

var_dump(king_mcp_request($connection, 'svc', 'ping', 'before-drop') === '{"res":"before-drop"}');

var_dump(king_mcp_request($connection, 'svc', 'drop-request', 'x') === false);
var_dump(king_mcp_get_error() !== '');
var_dump(king_mcp_request($connection, 'svc', 'ping', 'after-drop') === '{"res":"after-drop"}');

$source = fopen('php://temp', 'w+');
fwrite($source, 'drop-upload-payload');
rewind($source);
var_dump(king_mcp_upload_from_stream($connection, 'svc', 'drop-upload', 'asset-drop', $source) === false);
var_dump(king_mcp_get_error() !== '');
fclose($source);

$source = fopen('php://temp', 'w+');
fwrite($source, 'stable-upload-payload');
rewind($source);
var_dump(king_mcp_upload_from_stream($connection, 'svc', 'blob', 'asset-stable', $source));
fclose($source);

$downloadSeed = fopen('php://temp', 'w+');
fwrite($downloadSeed, 'drop-download-payload');
rewind($downloadSeed);
var_dump(king_mcp_upload_from_stream($connection, 'svc', 'drop-download', 'asset-drop-download', $downloadSeed));
fclose($downloadSeed);

$destination = fopen('php://temp', 'w+');
var_dump(king_mcp_download_to_stream($connection, 'svc', 'drop-download', 'asset-drop-download', $destination) === false);
var_dump(king_mcp_get_error() !== '');
fclose($destination);

$destination = fopen('php://temp', 'w+');
var_dump(king_mcp_download_to_stream($connection, 'svc', 'blob', 'asset-stable', $destination));
rewind($destination);
var_dump(stream_get_contents($destination) === 'stable-upload-payload');
fclose($destination);

$firstCapture = king_mcp_test_crash_server($server);
$server = king_mcp_test_start_server($port);

var_dump(king_mcp_request($connection, 'svc', 'ping', 'after-restart') === '{"res":"after-restart"}');
var_dump(king_mcp_close($connection));

$secondCapture = king_mcp_test_stop_server($server);

$firstErrors = array_column(
    array_filter(
        $firstCapture['events'] ?? [],
        static fn(array $event): bool => isset($event['remote_error'])
    ),
    'remote_error'
);
$secondOperations = array_map(
    static fn(array $event): string => $event['operation'],
    $secondCapture['events'] ?? []
);

var_dump(in_array('forced_request_disconnect', $firstErrors, true));
var_dump(in_array('forced_upload_disconnect', $firstErrors, true));
var_dump(in_array('forced_download_disconnect', $firstErrors, true));
var_dump($secondOperations);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
array(2) {
  [0]=>
  string(7) "request"
  [1]=>
  string(4) "stop"
}
