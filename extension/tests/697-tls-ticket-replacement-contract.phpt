--TEST--
King TLS ticket replacement keeps only the latest ticket across session and ring state
--FILE--
<?php
$sessionA = king_connect('127.0.0.1', 443);
var_dump(king_client_tls_import_session_ticket($sessionA, 'ticket-A'));
var_dump(bin2hex(king_client_tls_export_session_ticket($sessionA)));

var_dump(king_client_tls_import_session_ticket($sessionA, 'ticket-BB'));
var_dump(bin2hex(king_client_tls_export_session_ticket($sessionA)));

$statsA = king_get_stats($sessionA);
var_dump($statsA['tls_has_session_ticket']);
var_dump($statsA['tls_ticket_source']);
var_dump($statsA['tls_session_ticket_length']);

$sessionB = king_connect('127.0.0.1', 443);
var_dump(bin2hex(king_client_tls_export_session_ticket($sessionB)));

$statsB = king_get_stats($sessionB);
var_dump($statsB['tls_has_session_ticket']);
var_dump($statsB['tls_ticket_source']);
var_dump($statsB['tls_session_ticket_length']);
?>
--EXPECT--
bool(true)
string(16) "7469636b65742d41"
bool(true)
string(18) "7469636b65742d4242"
bool(true)
string(8) "imported"
int(9)
string(18) "7469636b65742d4242"
bool(true)
string(4) "ring"
int(9)
