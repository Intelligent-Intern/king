--TEST--
King TLS path setters feed the active client session snapshot
--FILE--
<?php
var_dump(king_set_ca_file(__FILE__));
var_dump(king_set_client_cert(__FILE__, __FILE__));

$session = king_connect('127.0.0.1', 443);
$stats = king_get_stats($session);

var_dump($stats['tls_default_ca_file'] === __FILE__);
var_dump($stats['tls_default_cert_file'] === __FILE__);
var_dump($stats['tls_default_key_file'] === __FILE__);
var_dump($stats['tls_enable_early_data']);
var_dump($stats['tls_session_ticket_lifetime_sec']);
var_dump($stats['tls_has_session_ticket']);
var_dump($stats['tls_ticket_source']);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
int(7200)
bool(false)
string(4) "none"
