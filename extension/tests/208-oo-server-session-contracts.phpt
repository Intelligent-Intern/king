--TEST--
King server-session helpers accept King\Session objects and reject stale capabilities
--FILE--
<?php
$session = new King\Session('127.0.0.1', 443);
$stats = $session->stats();
$capability = $stats['server_session_capability'];

try {
    king_session_get_peer_cert_subject($session, $capability + 1);
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

var_dump(king_session_close_server_initiated($session, $capability, 7, 'obj-close'));

$after = $session->stats();
var_dump($after['state']);
var_dump($after['server_close_error_code']);
var_dump($after['server_close_reason']);
var_dump(king_session_get_peer_cert_subject($session, $after['server_session_capability']));
?>
--EXPECT--
string(21) "King\RuntimeException"
string(70) "Session guard check failed (cross-process/thread or stale capability)."
bool(true)
string(6) "closed"
int(7)
string(9) "obj-close"
NULL
