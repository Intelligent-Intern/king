--TEST--
King TLS ticket ring seeds new sessions from previously imported tickets
--FILE--
<?php
$sessionA = king_connect('127.0.0.1', 443);
var_dump(king_client_tls_import_session_ticket($sessionA, 'ring-ticket'));

$sessionB = king_connect('127.0.0.1', 443);
var_dump(bin2hex(king_client_tls_export_session_ticket($sessionB)));

$stats = king_get_stats($sessionB);
var_dump($stats['tls_has_session_ticket']);
var_dump($stats['tls_ticket_source']);
var_dump($stats['tls_session_ticket_length']);
?>
--EXPECT--
bool(true)
string(22) "72696e672d7469636b6574"
bool(true)
string(4) "ring"
int(11)
