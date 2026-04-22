--TEST--
King MCP end-to-end verification exchanges request and transfer data with a remote peer
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$server = king_mcp_test_start_server();
$capture = [];

try {
    $connection = king_mcp_connect('127.0.0.1', $server['port'], null);

    $source = fopen('php://temp', 'w+');
    fwrite($source, str_repeat("D", 100));
    rewind($source);

    var_dump(king_mcp_upload_from_stream($connection, 'cloud', 'push', 'e2e-1', $source));

    $dest = fopen('php://temp', 'w+');
    var_dump(king_mcp_download_to_stream($connection, 'cloud', 'push', 'e2e-1', $dest));
    rewind($dest);
    var_dump(strlen(stream_get_contents($dest)));

    var_dump(king_mcp_request($connection, 'svc', 'ping', 'probe') === '{"res":"probe"}');

    var_dump(king_mcp_close($connection));
} finally {
    $capture = king_mcp_test_stop_server($server);
}

$operations = array_map(
    static fn(array $event): string => $event['operation'],
    $capture['events'] ?? []
);

var_dump($operations);
var_dump(($capture['connections'][0]['command_count'] ?? null) === 3);
var_dump(($capture['events'][0]['payload_length'] ?? null) === 100);
var_dump(($capture['events'][1]['payload_length'] ?? null) === 100);
var_dump(($capture['events'][2]['response_length'] ?? null) === 15);
?>
--EXPECT--
bool(true)
bool(true)
int(100)
bool(true)
bool(true)
array(4) {
  [0]=>
  string(6) "upload"
  [1]=>
  string(8) "download"
  [2]=>
  string(7) "request"
  [3]=>
  string(4) "stop"
}
bool(true)
bool(true)
bool(true)
bool(true)
