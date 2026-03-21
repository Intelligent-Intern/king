--TEST--
King HTTP/2 server leaf materializes a local request contract and auto-closes the captured session snapshot
--FILE--
<?php
$captured = [];
$session = null;

var_dump(king_http2_server_listen(
    '127.0.0.1',
    8443,
    new King\Config(),
    static function (array $request) use (&$captured, &$session): array {
        $session = $request['session'];
        $stats = king_get_stats($session);

        $captured = [
            'protocol' => $request['protocol'],
            'scheme' => $request['scheme'],
            'authority' => $request['headers'][':authority'],
            'alpn_before' => $stats['alpn'],
            'transport_backend_before' => $stats['transport_backend'],
            'server_close_initiated_before' => $stats['server_close_initiated'],
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
    'transport_has_socket' => $stats['transport_has_socket'],
    'server_close_initiated' => $stats['server_close_initiated'],
]);

var_dump(king_close($session));
var_dump(king_get_last_error());
?>
--EXPECT--
bool(true)
string(0) ""
array(6) {
  ["protocol"]=>
  string(6) "http/2"
  ["scheme"]=>
  string(5) "https"
  ["authority"]=>
  string(14) "127.0.0.1:8443"
  ["alpn_before"]=>
  string(2) "h2"
  ["transport_backend_before"]=>
  string(18) "server_http2_local"
  ["server_close_initiated_before"]=>
  bool(false)
}
array(5) {
  ["state"]=>
  string(6) "closed"
  ["alpn"]=>
  string(2) "h2"
  ["transport_backend"]=>
  string(18) "server_http2_local"
  ["transport_has_socket"]=>
  bool(false)
  ["server_close_initiated"]=>
  bool(false)
}
bool(true)
string(0) ""
