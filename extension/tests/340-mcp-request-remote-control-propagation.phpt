--TEST--
King MCP request propagates timeout, deadline, and cancellation controls across the real remote peer
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

    var_dump(king_mcp_request($connection, 'svc', 'sleep', '200', ['timeout_ms' => 50]));
    var_dump(king_mcp_get_error());

    $deadlineMs = (int) (hrtime(true) / 1000000) + 50;
    var_dump(king_mcp_request($connection, 'svc', 'sleep', '200', ['deadline_ms' => $deadlineMs]));
    var_dump(king_mcp_get_error());

    $cancel = new King\CancelToken();
    $cancelPid = king_mcp_schedule_cancel_signal($cancel, 100000);
    try {
        var_dump(king_mcp_request($connection, 'svc', 'sleep', '2000', ['cancel' => $cancel]));
        var_dump(king_mcp_get_error());
    } finally {
        king_mcp_wait_cancel_signal($cancelPid);
    }

    var_dump(king_mcp_close($connection));
} finally {
    $capture = king_mcp_test_stop_server($server);
}

var_dump(count($capture['events']));
var_dump($capture['events'][0]['timeout_budget_ms'] > 0);
var_dump($capture['events'][0]['peer_budget_exhausted']);
var_dump($capture['events'][1]['deadline_budget_ms'] > 0);
var_dump($capture['events'][1]['peer_budget_exhausted']);
var_dump($capture['events'][2]['peer_observed_cancel']);
var_dump($capture['events'][2]['peer_cancel_reason']);
var_dump($capture['events'][3]['operation']);
?>
--EXPECTF--
bool(false)
string(%d) "king_mcp_request() exceeded the active MCP timeout budget."
bool(false)
string(%d) "king_mcp_request() exceeded the active MCP deadline budget."
bool(false)
string(%d) "king_mcp_request() cancelled the active MCP operation via CancelToken."
bool(true)
int(4)
bool(true)
string(7) "timeout"
bool(true)
string(8) "deadline"
bool(true)
string(10) "disconnect"
string(4) "stop"
