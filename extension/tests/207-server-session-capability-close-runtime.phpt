--TEST--
King server-session capability and server-initiated close paths ride on the active King\Session runtime
--FILE--
<?php
$session = king_connect('127.0.0.1', 443);
$stats = king_get_stats($session);
$capability = $stats['server_session_capability'];

var_dump(is_resource($session));
var_dump(is_int($capability));
var_dump($capability > 0);
var_dump($stats['server_close_initiated']);
var_dump($stats['server_close_error_code']);
var_dump($stats['server_close_reason']);
var_dump($stats['server_peer_cert_subject']);
var_dump(king_session_get_peer_cert_subject($session, $capability));
var_dump(king_session_close_server_initiated($session, $capability, 42, 'server-close'));

$after = king_get_stats($session);
var_dump($after['state']);
var_dump($after['server_close_initiated']);
var_dump($after['server_close_error_code']);
var_dump($after['server_close_reason']);
var_dump($after['server_session_capability'] !== $capability);
var_dump(king_session_close_server_initiated($session, $after['server_session_capability']));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
int(0)
string(0) ""
NULL
NULL
bool(true)
string(6) "closed"
bool(true)
int(42)
string(12) "server-close"
bool(true)
bool(false)
