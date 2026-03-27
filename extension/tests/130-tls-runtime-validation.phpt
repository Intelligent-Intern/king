--TEST--
King TLS runtime validates file paths and session-ticket contracts
--FILE--
<?php
var_dump(king_client_tls_set_ca_file('/definitely/missing/king-ca.pem'));
var_dump(king_get_last_error());

$session = king_connect('127.0.0.1', 443);
var_dump(king_import_session_ticket($session, ''));
var_dump(king_get_last_error());

var_dump(king_close($session));
var_dump(king_client_tls_import_session_ticket($session, 'abc'));
var_dump(king_get_last_error());

$openSession = king_connect('127.0.0.1', 443);
var_dump(king_client_tls_import_session_ticket($openSession, str_repeat('a', 4097)));
var_dump(king_get_last_error());
?>
--EXPECTF--
bool(false)
string(%d) "king_client_tls_set_ca_file() CA path is not accessible or does not exist."
bool(false)
string(%d) "king_import_session_ticket() ticket length must be between 1 and 4096 bytes."
bool(true)
bool(false)
string(%d) "king_client_tls_import_session_ticket() cannot import a ticket into a closed session."
bool(false)
string(%d) "king_client_tls_import_session_ticket() ticket length must be between 1 and 4096 bytes."
