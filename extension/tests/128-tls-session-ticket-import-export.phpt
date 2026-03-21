--TEST--
King TLS session tickets import and export through the active client runtime
--FILE--
<?php
$session = king_connect('127.0.0.1', 443);

var_dump(king_import_session_ticket($session, 'ticket-A'));
var_dump(bin2hex(king_export_session_ticket($session)));

$stats = king_get_stats($session);
var_dump($stats['tls_has_session_ticket']);
var_dump($stats['tls_session_ticket_length']);
var_dump($stats['tls_ticket_source']);
?>
--EXPECT--
bool(true)
string(16) "7469636b65742d41"
bool(true)
int(8)
string(8) "imported"
