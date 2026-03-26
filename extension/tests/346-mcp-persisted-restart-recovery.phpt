--TEST--
King MCP reconnects to a restarted remote peer and can read transfers rehydrated from persisted remote state
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$transferStatePath = sys_get_temp_dir() . '/king_mcp_transfer_state_' . getmypid() . '.json';
$firstCapture = [];
$secondCapture = [];
$server = king_mcp_test_start_persisted_server($transferStatePath);
$port = $server['port'];
$connection = king_mcp_connect('127.0.0.1', $port, null);

$source = fopen('php://temp', 'w+');
fwrite($source, 'persisted-before-restart');
rewind($source);
var_dump(king_mcp_upload_from_stream($connection, 'svc', 'blob', 'asset-persisted', $source));
fclose($source);

var_dump(king_mcp_request($connection, 'svc', 'ping', 'before-restart') === '{"res":"before-restart"}');

$firstCapture = king_mcp_test_crash_server($server);
$server = king_mcp_test_start_persisted_server($transferStatePath, $port);

$destination = fopen('php://temp', 'w+');
var_dump(king_mcp_download_to_stream($connection, 'svc', 'blob', 'asset-persisted', $destination));
rewind($destination);
var_dump(stream_get_contents($destination) === 'persisted-before-restart');
fclose($destination);

var_dump(king_mcp_request($connection, 'svc', 'ping', 'after-restart') === '{"res":"after-restart"}');

$source = fopen('php://temp', 'w+');
fwrite($source, 'persisted-after-restart');
rewind($source);
var_dump(king_mcp_upload_from_stream($connection, 'svc', 'blob', 'asset-after', $source));
fclose($source);

$destination = fopen('php://temp', 'w+');
var_dump(king_mcp_download_to_stream($connection, 'svc', 'blob', 'asset-after', $destination));
rewind($destination);
var_dump(stream_get_contents($destination) === 'persisted-after-restart');
fclose($destination);

var_dump(king_mcp_close($connection));
$secondCapture = king_mcp_test_stop_server($server);

$firstOperations = array_map(
    static fn(array $event): string => $event['operation'],
    $firstCapture['events'] ?? []
);
$secondOperations = array_map(
    static fn(array $event): string => $event['operation'],
    $secondCapture['events'] ?? []
);

var_dump($firstOperations);
var_dump($secondOperations);

@unlink($transferStatePath);
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
array(2) {
  [0]=>
  string(6) "upload"
  [1]=>
  string(7) "request"
}
array(5) {
  [0]=>
  string(8) "download"
  [1]=>
  string(7) "request"
  [2]=>
  string(6) "upload"
  [3]=>
  string(8) "download"
  [4]=>
  string(4) "stop"
}
