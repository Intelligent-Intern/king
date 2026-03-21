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

stream_set_blocking($server, true);
stream_set_timeout($server, 1);

$serverName = stream_socket_get_name($server, false);
[$serverHost, $serverPort] = explode(':', $serverName, 2);

$cfg = king_new_config([
    'quic.ping_interval_ms' => 0,
]);

$session = king_connect($serverHost, (int) $serverPort, $cfg);
$before = king_get_stats($session);

var_dump($before['transport_backend']);
var_dump($before['transport_state']);
var_dump($before['transport_has_socket']);
var_dump($before['transport_socket_family']);
var_dump($before['transport_peer_address']);
var_dump($before['transport_peer_port'] === (int) $serverPort);
var_dump($before['transport_local_address']);
var_dump($before['transport_local_port'] > 0);
var_dump($before['transport_tx_datagram_count']);
var_dump($before['transport_rx_datagram_count']);

var_dump(king_poll($session, 50));
$probe = stream_socket_recvfrom($server, 16, 0, $peer);
var_dump(is_string($probe));
var_dump(strlen($probe));

$afterSend = king_get_stats($session);
var_dump($afterSend['transport_tx_datagram_count']);
var_dump($afterSend['transport_tx_bytes']);

stream_socket_sendto(
    $server,
    'pong',
    0,
    $afterSend['transport_local_address'] . ':' . $afterSend['transport_local_port']
);

var_dump(king_poll($session, 50));
$afterReceive = king_get_stats($session);
var_dump($afterReceive['transport_rx_datagram_count']);
var_dump($afterReceive['transport_rx_bytes']);
var_dump($afterReceive['transport_last_poll_result'] >= 0);
var_dump($afterReceive['transport_last_errno']);
var_dump($afterReceive['transport_error_scope']);
?>
--EXPECT--
string(10) "udp_socket"
string(9) "connected"
bool(true)
string(4) "ipv4"
string(9) "127.0.0.1"
bool(true)
string(9) "127.0.0.1"
bool(true)
int(0)
int(0)
bool(true)
bool(true)
int(1)
int(1)
int(1)
bool(true)
int(1)
int(4)
bool(true)
int(0)
string(4) "none"
