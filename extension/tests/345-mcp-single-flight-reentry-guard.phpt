--TEST--
King MCP rejects reentrant use of the same connection handle while a remote operation is already active
--EXTENSIONS--
pcntl
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$server = king_mcp_test_start_server();
$connection = king_mcp_connect('127.0.0.1', $server['port'], null);
$reentrantResult = null;
$reentrantError = null;

pcntl_async_signals(true);
pcntl_signal(SIGUSR1, static function () use ($connection, &$reentrantResult, &$reentrantError): void {
    $reentrantResult = king_mcp_request($connection, 'svc', 'ping', 'reentrant');
    $reentrantError = king_mcp_get_error();
});

$pid = pcntl_fork();
if ($pid === 0) {
    usleep(50000);
    posix_kill(posix_getppid(), SIGUSR1);
    exit(0);
}

$outerResult = king_mcp_request($connection, 'svc', 'sleep', '200');
pcntl_waitpid($pid, $status);
pcntl_signal(SIGUSR1, SIG_DFL);

var_dump($outerResult === '{"status":"ok","remote":true,"service":"svc","method":"sleep","payload":"200"}');
var_dump($reentrantResult === false);
var_dump(str_contains((string) $reentrantError, 'already active on this connection'));
var_dump(king_mcp_request($connection, 'svc', 'ping', 'after') === '{"res":"after"}');

king_mcp_close($connection);
king_mcp_test_stop_server($server);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
