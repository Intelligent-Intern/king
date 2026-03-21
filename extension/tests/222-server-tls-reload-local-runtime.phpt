--TEST--
King server TLS reload exposes a local lifecycle snapshot on live sessions
--FILE--
<?php
$session = king_connect('127.0.0.1', 443);

$stats = king_get_stats($session);
var_dump([
    'active' => $stats['server_tls_active'],
    'apply_count' => $stats['server_tls_apply_count'],
    'reload_count' => $stats['server_tls_reload_count'],
    'cert_empty' => $stats['server_last_tls_cert_file'] === '',
    'key_empty' => $stats['server_last_tls_key_file'] === '',
    'ticket_key_empty' => $stats['server_last_tls_ticket_key_file'] === '',
    'ticket_key_loaded' => $stats['server_last_tls_ticket_key_loaded'],
]);

var_dump(king_server_reload_tls_config($session, __FILE__, __FILE__));
var_dump(king_get_last_error());

$stats = king_get_stats($session);
var_dump([
    'active' => $stats['server_tls_active'],
    'apply_count' => $stats['server_tls_apply_count'],
    'reload_count' => $stats['server_tls_reload_count'],
    'cert_matches' => $stats['server_last_tls_cert_file'] === __FILE__,
    'key_matches' => $stats['server_last_tls_key_file'] === __FILE__,
    'ticket_key_empty' => $stats['server_last_tls_ticket_key_file'] === '',
    'ticket_key_loaded' => $stats['server_last_tls_ticket_key_loaded'],
    'tls_cert_matches' => $stats['tls_default_cert_file'] === __FILE__,
    'tls_key_matches' => $stats['tls_default_key_file'] === __FILE__,
]);

var_dump(king_server_reload_tls_config($session, __FILE__, __FILE__));
var_dump(king_get_last_error());

$stats = king_get_stats($session);
var_dump([
    'active' => $stats['server_tls_active'],
    'apply_count' => $stats['server_tls_apply_count'],
    'reload_count' => $stats['server_tls_reload_count'],
]);
?>
--EXPECT--
array(7) {
  ["active"]=>
  bool(false)
  ["apply_count"]=>
  int(0)
  ["reload_count"]=>
  int(0)
  ["cert_empty"]=>
  bool(true)
  ["key_empty"]=>
  bool(true)
  ["ticket_key_empty"]=>
  bool(true)
  ["ticket_key_loaded"]=>
  bool(false)
}
bool(true)
string(0) ""
array(9) {
  ["active"]=>
  bool(true)
  ["apply_count"]=>
  int(1)
  ["reload_count"]=>
  int(0)
  ["cert_matches"]=>
  bool(true)
  ["key_matches"]=>
  bool(true)
  ["ticket_key_empty"]=>
  bool(true)
  ["ticket_key_loaded"]=>
  bool(false)
  ["tls_cert_matches"]=>
  bool(true)
  ["tls_key_matches"]=>
  bool(true)
}
bool(true)
string(0) ""
array(3) {
  ["active"]=>
  bool(true)
  ["apply_count"]=>
  int(2)
  ["reload_count"]=>
  int(1)
}
