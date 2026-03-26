--TEST--
King MCP large remote payloads survive request and transfer roundtrips
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$payload = str_repeat(str_repeat('0123456789abcdef', 256), 256);
$payloadHash = hash('sha256', $payload);
$payloadLength = strlen($payload);

$server = king_mcp_test_start_server();
$capture = [];

try {
    $connection = king_mcp_connect('127.0.0.1', $server['port'], null);

    $requestPayload = king_mcp_request($connection, 'svc', 'ping', $payload);
    $requestData = json_decode($requestPayload, true);

    $source = fopen('php://temp', 'w+');
    fwrite($source, $payload);
    rewind($source);
    $uploaded = king_mcp_upload_from_stream($connection, 'svc', 'blob', 'asset-large', $source);
    fclose($source);

    $destination = fopen('php://temp', 'w+');
    $downloaded = king_mcp_download_to_stream($connection, 'svc', 'blob', 'asset-large', $destination);
    rewind($destination);
    $downloadedPayload = stream_get_contents($destination);
    fclose($destination);

    var_dump(strlen($requestPayload) > $payloadLength);
    var_dump(is_array($requestData));
    var_dump(($requestData['res'] ?? null) !== null);
    var_dump(hash('sha256', $requestData['res']) === $payloadHash);
    var_dump($uploaded);
    var_dump($downloaded);
    var_dump(hash('sha256', $downloadedPayload) === $payloadHash);
    var_dump(king_mcp_close($connection));
} finally {
    $capture = king_mcp_test_stop_server($server);
}

$operations = array_map(
    static fn(array $event): string => $event['operation'],
    $capture['events'] ?? []
);

var_dump($payloadLength === 1048576);
var_dump($operations);
var_dump(($capture['events'][0]['payload_length'] ?? null) === $payloadLength);
var_dump(($capture['events'][0]['response_length'] ?? 0) > $payloadLength);
var_dump(($capture['events'][1]['payload_length'] ?? null) === $payloadLength);
var_dump(($capture['events'][2]['payload_length'] ?? null) === $payloadLength);
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
array(4) {
  [0]=>
  string(7) "request"
  [1]=>
  string(6) "upload"
  [2]=>
  string(8) "download"
  [3]=>
  string(4) "stop"
}
bool(true)
bool(true)
bool(true)
bool(true)
