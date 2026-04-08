--TEST--
Repo-local Flow PHP MCP sink keeps explicit replay state across upload failure and retry
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';
require_once __DIR__ . '/../../userland/flow-php/src/StreamingSink.php';

use King\Flow\McpByteSink;
use King\Flow\SinkCursor;

$server = king_mcp_test_start_server();
try {
    $connection = king_mcp_connect('127.0.0.1', $server['port'], null);
    $adapter = new McpByteSink($connection, 'svc', 'blob', 'asset-flow-sink');

    var_dump($adapter->write('alpha')->failure());
    var_dump($adapter->write('-beta')->failure());
    var_dump(king_mcp_close($connection));

    $failed = $adapter->complete('-omega');
    var_dump($failed->complete());
    var_dump($failed->failure() !== null);
    var_dump($failed->failure()?->partialFailure());
    var_dump($failed->cursor()->toArray()['resume_strategy']);
    var_dump(is_file($failed->cursor()->toArray()['state']['spool_path']));

    $retryConnection = king_mcp_connect('127.0.0.1', $server['port'], null);
    $resumed = new McpByteSink(
        $retryConnection,
        'svc',
        'blob',
        'asset-flow-sink',
        SinkCursor::fromArray($failed->cursor()->toArray())
    );
    $completed = $resumed->complete();

    $download = fopen('php://temp', 'w+');
    var_dump($completed->complete());
    var_dump($completed->transportCommitted());
    var_dump(king_mcp_download_to_stream($retryConnection, 'svc', 'blob', 'asset-flow-sink', $download));
    rewind($download);
    var_dump(stream_get_contents($download));
    fclose($download);
    var_dump(king_mcp_close($retryConnection));
} finally {
    king_mcp_test_stop_server($server);
}
?>
--EXPECT--
NULL
NULL
bool(true)
bool(false)
bool(true)
bool(true)
string(18) "replay_local_spool"
bool(true)
bool(true)
bool(true)
bool(true)
string(16) "alpha-beta-omega"
bool(true)
