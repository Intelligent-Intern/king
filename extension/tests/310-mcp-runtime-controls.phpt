--TEST--
King MCP runtime controls enforce deadline and cancel budgets across request and transfer helpers
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$server = king_mcp_test_start_server();
$connection = king_mcp_connect('127.0.0.1', $server['port'], null);
$cancelled = new King\CancelToken();
$cancelled->cancel();

$source = fopen('php://temp', 'w+');
fwrite($source, 'payload');
rewind($source);

var_dump(king_mcp_request($connection, 'svc', 'ping', '{}', ['deadline_ms' => 1]));
var_dump(king_mcp_get_error());

var_dump(king_mcp_upload_from_stream(
    $connection,
    'svc',
    'blob',
    'asset-controls',
    $source,
    ['cancel' => $cancelled]
));
var_dump(king_mcp_get_error());

$source = fopen('php://temp', 'w+');
fwrite($source, 'payload');
rewind($source);
var_dump(king_mcp_upload_from_stream(
    $connection,
    'svc',
    'blob',
    'asset-controls',
    $source
));

$destination = fopen('php://temp', 'w+');
var_dump(king_mcp_download_to_stream(
    $connection,
    'svc',
    'blob',
    'asset-controls',
    $destination,
    ['deadline_ms' => 1]
));
var_dump(king_mcp_get_error());

king_mcp_close($connection);

$mcp = new King\MCP('127.0.0.1', $server['port']);
$pending = new King\CancelToken();
var_dump($mcp->request('svc', 'ping', '{}', $pending, ['timeout_ms' => 500]) === '{"res":"{}"}');

try {
    $mcp->uploadFromStream(
        'svc',
        'blob',
        'asset-controls-oo',
        fopen('php://temp', 'w+'),
        ['cancel' => $cancelled]
    );
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    $mcp->downloadToStream(
        'svc',
        'blob',
        'asset-controls',
        fopen('php://temp', 'w+'),
        ['deadline_ms' => 1]
    );
    echo "no-exception-2\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

var_dump(king_mcp_close($connection));
$mcp->close();
king_mcp_test_stop_server($server);
?>
--EXPECTF--
bool(false)
string(%d) "king_mcp_request() exceeded the active MCP deadline budget."
bool(false)
string(%d) "king_mcp_upload_from_stream() cancelled the active MCP operation via CancelToken."
bool(true)
bool(false)
string(%d) "king_mcp_download_to_stream() exceeded the active MCP deadline budget."
bool(true)
string(21) "King\MCPDataException"
string(%d) "MCP::uploadFromStream() cancelled the active MCP operation via CancelToken."
string(24) "King\MCPTimeoutException"
string(%d) "MCP::downloadToStream() exceeded the active MCP deadline budget."
bool(true)
