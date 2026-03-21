--TEST--
King\Config applies namespaced network overrides across tls quic http2 and tcp
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$cfg = king_new_config([
    'quic.cc_algorithm' => 'bbr',
    'tls.verify_peer' => false,
    'http2.enable' => false,
    'http2.max_concurrent_streams' => 17,
    'tcp.enable' => false,
    'tcp.tls_min_version_allowed' => 'TLSv1.3',
]);

$session = king_connect('127.0.0.1', 443, $cfg);
$stats = king_get_stats($session);

var_dump($stats['config_quic_cc_algorithm']);
var_dump($stats['config_tls_verify_peer']);
var_dump($stats['config_http2_enable']);
var_dump($stats['config_http2_max_concurrent_streams']);
var_dump($stats['config_tcp_enable']);
var_dump($stats['config_tcp_tls_min_version_allowed']);
var_dump($stats['config_option_count']);
?>
--EXPECT--
string(3) "bbr"
bool(false)
bool(false)
int(17)
bool(false)
string(7) "TLSv1.3"
int(6)
