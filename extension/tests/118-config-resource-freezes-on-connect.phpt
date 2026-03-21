--TEST--
King\Config freezes and carries supported overrides when consumed by king_connect
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$cfg = king_new_config([
    'tls_verify_peer' => false,
    'cc_algorithm' => 'bbr',
]);

$session = king_connect('127.0.0.1', 443, $cfg);
$stats = king_get_stats($session);

var_dump(is_resource($session));
var_dump($stats['config_binding']);
var_dump($stats['config_is_frozen']);
var_dump($stats['config_userland_overrides_applied']);
var_dump($stats['config_option_count']);
var_dump($stats['config_tls_verify_peer']);
var_dump($stats['config_quic_cc_algorithm']);
?>
--EXPECT--
bool(true)
string(8) "resource"
bool(true)
bool(true)
int(2)
bool(false)
string(3) "bbr"
