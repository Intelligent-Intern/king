--TEST--
King Config OO parity exposes active snapshot keys and propagates explicit overrides
--INI--
king.security_allow_config_override=1
king.transport_cc_algorithm=cubic
king.tls_verify_peer=1
king.http2_max_concurrent_streams=32
king.storage_default_redundancy_mode=replication
--FILE--
<?php
$config = new King\Config([
    'quic.cc_algorithm' => 'bbr',
    'tls.verify_peer' => false,
    'quic.pacing_enable' => true,
]);

var_dump($config->get('quic.cc_algorithm'));
var_dump($config->get('tls_verify_peer'));
var_dump($config->get('quic.pacing_enable'));

$config->set('http2.max_concurrent_streams', 17);
$config->set('otel.service_name', 'king_oo_config');
$config->set('storage.enable', true);

$snapshot = $config->toArray();

var_dump($snapshot['quic.cc_algorithm']);
var_dump($snapshot['tls.verify_peer']);
var_dump($snapshot['quic.pacing_enable']);
var_dump($snapshot['http2.max_concurrent_streams']);
var_dump($snapshot['otel.service_name']);
var_dump($snapshot['storage.enable']);

$session = king_connect('127.0.0.1', 443, $config);
$stats = king_get_stats($session);

var_dump($stats['config_binding']);
var_dump($stats['config_option_count']);
var_dump($stats['config_quic_cc_algorithm']);
var_dump($stats['config_tls_verify_peer']);
var_dump($stats['config_http2_max_concurrent_streams']);
var_dump($stats['config_otel_service_name']);
var_dump($stats['config_storage_enable']);

try {
    $config->set('tcp.enable', false);
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECTF--
string(3) "bbr"
bool(false)
bool(true)
string(3) "bbr"
bool(false)
bool(true)
int(17)
string(14) "king_oo_config"
bool(true)
string(8) "resource"
int(6)
string(3) "bbr"
bool(false)
int(17)
string(14) "king_oo_config"
bool(true)
string(24) "King\ValidationException"
string(%d) "King\Config::set() cannot modify a frozen King\Config snapshot."
