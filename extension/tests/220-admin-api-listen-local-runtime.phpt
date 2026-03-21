--TEST--
King admin api listen exposes a local listener snapshot and reload contract on live sessions
--FILE--
<?php
$session = king_connect('127.0.0.1', 443);

var_dump(king_admin_api_listen($session, [
    'enable' => true,
    'bind_host' => '::1',
    'port' => 2025,
    'auth_mode' => 'mtls',
    'ca_file' => __FILE__,
    'cert_file' => __FILE__,
    'key_file' => __FILE__,
]));
var_dump(king_get_last_error());
$stats = king_get_stats($session);
var_dump([
    'active' => $stats['server_admin_api_active'],
    'listen_count' => $stats['server_admin_api_listen_count'],
    'reload_count' => $stats['server_admin_api_reload_count'],
    'bind_host' => $stats['server_last_admin_api_bind_host'],
    'port' => $stats['server_last_admin_api_port'],
    'auth_mode' => $stats['server_last_admin_api_auth_mode'],
    'mtls_ready' => $stats['server_last_admin_api_mtls_ready'],
]);

var_dump(king_admin_api_listen($session, [
    'enable' => true,
    'bind_host' => '127.0.0.1',
    'port' => 3030,
    'auth_mode' => 'mtls',
    'ca_file' => __FILE__,
    'cert_file' => __FILE__,
    'key_file' => __FILE__,
]));
var_dump(king_get_last_error());
$stats = king_get_stats($session);
var_dump([
    'active' => $stats['server_admin_api_active'],
    'listen_count' => $stats['server_admin_api_listen_count'],
    'reload_count' => $stats['server_admin_api_reload_count'],
    'bind_host' => $stats['server_last_admin_api_bind_host'],
    'port' => $stats['server_last_admin_api_port'],
    'auth_mode' => $stats['server_last_admin_api_auth_mode'],
    'mtls_ready' => $stats['server_last_admin_api_mtls_ready'],
]);
?>
--EXPECT--
bool(true)
string(0) ""
array(7) {
  ["active"]=>
  bool(true)
  ["listen_count"]=>
  int(1)
  ["reload_count"]=>
  int(0)
  ["bind_host"]=>
  string(3) "::1"
  ["port"]=>
  int(2025)
  ["auth_mode"]=>
  string(4) "mtls"
  ["mtls_ready"]=>
  bool(true)
}
bool(true)
string(0) ""
array(7) {
  ["active"]=>
  bool(true)
  ["listen_count"]=>
  int(2)
  ["reload_count"]=>
  int(1)
  ["bind_host"]=>
  string(9) "127.0.0.1"
  ["port"]=>
  int(3030)
  ["auth_mode"]=>
  string(4) "mtls"
  ["mtls_ready"]=>
  bool(true)
}
