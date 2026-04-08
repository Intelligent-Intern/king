--TEST--
Repo-local Flow PHP MCP source streams bytes with replay-and-skip resume
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
require_once __DIR__ . '/../../userland/flow-php/src/StreamingSource.php';

use King\Flow\McpByteSource;
use King\Flow\SourceCursor;

$server = king_mcp_test_start_server();
try {
    $connection = king_mcp_connect('127.0.0.1', $server['port'], null);
    $upload = fopen('php://temp', 'w+');
    fwrite($upload, 'alpha-beta-gamma');
    rewind($upload);

    var_dump(king_mcp_upload_from_stream($connection, 'svc', 'blob', 'asset-flow-source', $upload));

    $adapter = new McpByteSource($connection, 'svc', 'blob', 'asset-flow-source', 5);
    $firstChunks = [];
    $firstResult = $adapter->pumpBytes(
        function (string $chunk, SourceCursor $cursor) use (&$firstChunks): bool {
            $firstChunks[] = [$chunk, $cursor->bytesConsumed()];

            return count($firstChunks) < 2;
        }
    );

    $cursor = SourceCursor::fromArray($firstResult->cursor()->toArray());
    $secondChunks = [];
    $secondResult = $adapter->pumpBytes(
        function (string $chunk, SourceCursor $cursor) use (&$secondChunks): bool {
            $secondChunks[] = [$chunk, $cursor->bytesConsumed()];

            return true;
        },
        $cursor
    );

    var_dump($firstResult->complete());
    var_dump($firstChunks);
    var_dump($secondResult->complete());
    var_dump($secondChunks);
    var_dump($secondResult->cursor()->toArray()['resume_strategy']);
    var_dump($secondResult->cursor()->toArray()['state']['next_offset']);
    var_dump(king_mcp_close($connection));
} finally {
    king_mcp_test_stop_server($server);
}
?>
--EXPECT--
bool(true)
bool(false)
array(2) {
  [0]=>
  array(2) {
    [0]=>
    string(5) "alpha"
    [1]=>
    int(5)
  }
  [1]=>
  array(2) {
    [0]=>
    string(5) "-beta"
    [1]=>
    int(10)
  }
}
bool(true)
array(2) {
  [0]=>
  array(2) {
    [0]=>
    string(5) "-gamm"
    [1]=>
    int(15)
  }
  [1]=>
  array(2) {
    [0]=>
    string(1) "a"
    [1]=>
    int(16)
  }
}
string(15) "replay_and_skip"
int(16)
bool(true)
