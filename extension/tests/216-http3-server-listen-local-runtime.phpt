--TEST--
King HTTP/3 server leaf materializes a local request contract and auto-closes the captured session snapshot
--FILE--
<?php
$captured = [];
$session = null;

var_dump(king_http3_server_listen(
    '127.0.0.1',
    9443,
    null,
    static function (array $request) use (&$captured, &$session): array {
        $session = $request['session'];
        $stats = king_get_stats($session);

        $captured = [
            'protocol' => $request['protocol'],
            'scheme' => $request['scheme'],
            'authority' => $request['headers'][':authority'],
            'stream_id' => $request['stream_id'],
            'alpn_before' => $stats['alpn'],
            'transport_backend_before' => $stats['transport_backend'],
            'transport_socket_family_before' => $stats['transport_socket_family'],
            'transport_datagrams_enable_before' => $stats['transport_datagrams_enable'],
        ];

        return ['status' => 200, 'body' => 'ok'];
    }
));
var_dump(king_get_last_error());
var_dump($captured);

$stats = king_get_stats($session);
var_dump([
    'state' => $stats['state'],
    'alpn' => $stats['alpn'],
    'transport_backend' => $stats['transport_backend'],
    'transport_socket_family' => $stats['transport_socket_family'],
    'transport_datagrams_enable' => $stats['transport_datagrams_enable'],
    'transport_has_socket' => $stats['transport_has_socket'],
]);

var_dump(king_close($session));
var_dump(king_get_last_error());
?>
--EXPECT--
bool(true)
string(0) ""
array(8) {
  ["protocol"]=>
  string(6) "http/3"
  ["scheme"]=>
  string(5) "https"
  ["authority"]=>
  string(14) "127.0.0.1:9443"
  ["stream_id"]=>
  int(0)
  ["alpn_before"]=>
  string(2) "h3"
  ["transport_backend_before"]=>
  string(18) "server_http3_local"
  ["transport_socket_family_before"]=>
  string(3) "udp"
  ["transport_datagrams_enable_before"]=>
  bool(true)
}
array(6) {
  ["state"]=>
  string(6) "closed"
  ["alpn"]=>
  string(2) "h3"
  ["transport_backend"]=>
  string(18) "server_http3_local"
  ["transport_socket_family"]=>
  string(3) "udp"
  ["transport_datagrams_enable"]=>
  bool(true)
  ["transport_has_socket"]=>
  bool(false)
}
bool(true)
string(0) ""
