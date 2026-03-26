--TEST--
King MCP transfer helpers propagate timeout, deadline, cancellation, and remote failures across the real remote peer
--SKIPIF--
<?php
if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
    echo "skip pcntl and posix are required for MCP remote cancel propagation";
}
?>
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
    fwrite($source, 'timeout-payload');
    rewind($source);
    var_dump(king_mcp_upload_from_stream(
        $connection,
        'svc',
        'slow-upload',
        'asset-timeout',
        $source,
        ['timeout_ms' => 50]
    ));
    var_dump(king_mcp_get_error());

    $seed = fopen('php://temp', 'w+');
    fwrite($seed, 'download-payload');
    rewind($seed);
    var_dump(king_mcp_upload_from_stream(
        $connection,
        'svc',
        'slow-download',
        'asset-deadline',
        $seed
    ));
    var_dump(king_mcp_get_error());

    $destination = fopen('php://temp', 'w+');
    $deadlineMs = (int) (hrtime(true) / 1000000) + 50;
    var_dump(king_mcp_download_to_stream(
        $connection,
        'svc',
        'slow-download',
        'asset-deadline',
        $destination,
        ['deadline_ms' => $deadlineMs]
    ));
    var_dump(king_mcp_get_error());

    $mcp = new King\MCP('127.0.0.1', $server['port']);
    $cancel = new King\CancelToken();
    $cancelSource = fopen('php://temp', 'w+');
    fwrite($cancelSource, 'cancel-payload');
    rewind($cancelSource);
    $cancelPid = king_mcp_schedule_cancel_signal($cancel, 100000);
    try {
        $mcp->uploadFromStream(
            'svc',
            'slow-upload',
            'asset-cancel',
            $cancelSource,
            ['cancel' => $cancel]
        );
        echo "no-exception-1\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    } finally {
        king_mcp_wait_cancel_signal($cancelPid);
    }

    try {
        $mcp->downloadToStream(
            'svc',
            'fail-download',
            'asset-fail',
            fopen('php://temp', 'w+')
        );
        echo "no-exception-2\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    $mcp->close();
    var_dump(king_mcp_close($connection));
} finally {
    $capture = king_mcp_test_stop_server($server);
}

var_dump(count($capture['events']));
var_dump($capture['events'][0]['peer_budget_exhausted']);
var_dump($capture['events'][1]['identifier']);
var_dump($capture['events'][2]['peer_budget_exhausted']);
var_dump($capture['events'][3]['peer_observed_cancel']);
var_dump($capture['events'][3]['peer_cancel_reason']);
var_dump($capture['events'][4]['remote_error']);
var_dump($capture['events'][5]['operation']);
?>
--EXPECTF--
bool(false)
string(%d) "king_mcp_upload_from_stream() exceeded the active MCP timeout budget."
bool(true)
string(0) ""
bool(false)
string(%d) "king_mcp_download_to_stream() exceeded the active MCP deadline budget."
string(21) "King\MCPDataException"
string(%d) "MCP::uploadFromStream() cancelled the active MCP operation via CancelToken."
string(21) "King\MCPDataException"
string(40) "Remote MCP peer forced download failure."
bool(true)
int(6)
string(7) "timeout"
string(14) "asset-deadline"
string(8) "deadline"
bool(true)
string(10) "disconnect"
string(23) "forced_download_failure"
string(4) "stop"
