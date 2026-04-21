--TEST--
King client transport runtime opens a real UDP socket and tracks send receive stats
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
if ($server === false) {
    die("failed to create UDP server: $errstr\n");
}

stream_set_blocking($server, false);
stream_set_timeout($server, 1);

$serverName = stream_socket_get_name($server, false);
[$serverHost, $serverPort] = explode(':', $serverName, 2);

$cfg = king_new_config([
    'quic.ping_interval_ms' => 0,
    'quic.datagrams_enable' => false,
]);

$session = king_connect($serverHost, (int) $serverPort, $cfg);
$before = king_get_stats($session);

var_dump($before['transport_backend'] === 'udp_socket');
var_dump($before['transport_state'] === 'connected');
var_dump($before['transport_has_socket'] === true);
var_dump($before['transport_socket_family'] === 'ipv4');
var_dump($before['transport_peer_address'] === '127.0.0.1');
var_dump($before['transport_peer_port'] === (int) $serverPort);
var_dump($before['transport_local_address'] === '127.0.0.1');
var_dump($before['transport_local_port'] > 0);
var_dump($before['transport_tx_datagram_count'] === 0);
var_dump($before['transport_rx_datagram_count'] === 0);

var_dump(king_poll($session, 10));
$afterPoll = king_get_stats($session);
var_dump($afterPoll['transport_last_poll_result'] >= 0);

fclose($server);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)