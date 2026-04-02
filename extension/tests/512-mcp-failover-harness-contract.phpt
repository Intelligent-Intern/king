--TEST--
King MCP failover harness proves peer loss, rejoin, and partial-topology breakage across named remote peers
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_failover_harness.inc';

$harness = king_mcp_failover_harness_create(['alpha', 'beta']);
$captures = [];
$alphaCrash = [];
$alphaConnection = null;
$betaConnection = null;

try {
    $alphaConnection = king_mcp_failover_harness_connect_peer($harness, 'alpha');
    $betaConnection = king_mcp_failover_harness_connect_peer($harness, 'beta');

    $source = fopen('php://temp', 'w+');
    fwrite($source, 'alpha-persisted-before-crash');
    rewind($source);
    var_dump(king_mcp_upload_from_stream($alphaConnection, 'svc', 'blob', 'asset-alpha', $source));
    fclose($source);

    $source = fopen('php://temp', 'w+');
    fwrite($source, 'beta-remains-available');
    rewind($source);
    var_dump(king_mcp_upload_from_stream($betaConnection, 'svc', 'blob', 'asset-beta', $source));
    fclose($source);

    var_dump(king_mcp_request($alphaConnection, 'svc', 'ping', 'alpha-before-crash') === '{"res":"alpha-before-crash"}');
    var_dump(king_mcp_request($betaConnection, 'svc', 'ping', 'beta-before-crash') === '{"res":"beta-before-crash"}');

    $alphaCrash = king_mcp_failover_harness_crash_peer($harness, 'alpha');

    var_dump(king_mcp_request($alphaConnection, 'svc', 'ping', 'alpha-while-down') === false);
    var_dump(king_mcp_get_error() !== '');

    var_dump(king_mcp_request($betaConnection, 'svc', 'ping', 'beta-after-alpha-crash') === '{"res":"beta-after-alpha-crash"}');

    $destination = fopen('php://temp', 'w+');
    var_dump(king_mcp_download_to_stream($betaConnection, 'svc', 'blob', 'asset-beta', $destination));
    rewind($destination);
    var_dump(stream_get_contents($destination) === 'beta-remains-available');
    fclose($destination);

    king_mcp_failover_harness_restart_peer($harness, 'alpha');

    $destination = fopen('php://temp', 'w+');
    var_dump(king_mcp_download_to_stream($alphaConnection, 'svc', 'blob', 'asset-alpha', $destination));
    rewind($destination);
    var_dump(stream_get_contents($destination) === 'alpha-persisted-before-crash');
    fclose($destination);

    var_dump(king_mcp_request($alphaConnection, 'svc', 'ping', 'alpha-after-rejoin') === '{"res":"alpha-after-rejoin"}');
    var_dump(king_mcp_close($alphaConnection));
    var_dump(king_mcp_close($betaConnection));
} finally {
    $captures = king_mcp_failover_harness_shutdown($harness);
    king_mcp_failover_harness_destroy($harness);
}

$alphaHistory = $captures['alpha'] ?? [];
$betaHistory = $captures['beta'] ?? [];

$alphaFirstOperations = array_map(
    static fn(array $event): string => (string) $event['operation'],
    ($alphaCrash['capture']['events'] ?? [])
);
$alphaSecondOperations = array_map(
    static fn(array $event): string => (string) $event['operation'],
    ($alphaHistory[1]['capture']['events'] ?? [])
);
$betaOperations = array_map(
    static fn(array $event): string => (string) $event['operation'],
    ($betaHistory[0]['capture']['events'] ?? [])
);

var_dump($alphaCrash['termination']);
var_dump(in_array('upload', $alphaFirstOperations, true));
var_dump(in_array('request', $alphaFirstOperations, true));
var_dump($alphaCrash['capture']['connections'][0]['command_count'] >= 2);
var_dump($alphaHistory[1]['termination']);
var_dump($alphaSecondOperations);
var_dump($betaHistory[0]['termination']);
var_dump($betaOperations);
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
string(5) "crash"
bool(true)
bool(true)
bool(true)
string(4) "stop"
array(3) {
  [0]=>
  string(8) "download"
  [1]=>
  string(7) "request"
  [2]=>
  string(4) "stop"
}
string(4) "stop"
array(5) {
  [0]=>
  string(6) "upload"
  [1]=>
  string(7) "request"
  [2]=>
  string(7) "request"
  [3]=>
  string(8) "download"
  [4]=>
  string(4) "stop"
}
