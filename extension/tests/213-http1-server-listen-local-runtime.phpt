--TEST--
King HTTP/1 server leaf materializes a local request contract over the active King\Session runtime
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$captured = [];

var_dump(king_http1_server_listen(
    '127.0.0.1',
    8080,
    ['http2.enable' => false],
    static function (array $request) use (&$captured): array {
        $statsBefore = king_get_stats($request['session']);

        $captured = [
            'protocol' => $request['protocol'],
            'scheme' => $request['scheme'],
            'local_address' => $request['local_address'],
            'local_port' => $request['local_port'],
            'remote_address' => $request['remote_address'],
            'remote_port' => $request['remote_port'],
            'peer_cert' => king_session_get_peer_cert_subject(
                $request['session'],
                $request['server_session_capability']
            ),
            'stats_before' => [
                'state' => $statsBefore['state'],
                'transport_backend' => $statsBefore['transport_backend'],
                'transport_has_socket' => $statsBefore['transport_has_socket'],
                'server_close_initiated' => $statsBefore['server_close_initiated'],
            ],
            'close_result' => king_session_close_server_initiated(
                $request['session'],
                $request['server_session_capability'],
                11,
                'listener-finished'
            ),
        ];

        $statsAfter = king_get_stats($request['session']);
        $captured['stats_after'] = [
            'state' => $statsAfter['state'],
            'transport_backend' => $statsAfter['transport_backend'],
            'transport_has_socket' => $statsAfter['transport_has_socket'],
            'server_close_initiated' => $statsAfter['server_close_initiated'],
            'server_close_error_code' => $statsAfter['server_close_error_code'],
            'server_close_reason' => $statsAfter['server_close_reason'],
        ];

        return ['status' => 204, 'headers' => ['X-Mode' => 'http1'], 'body' => ''];
    }
));
var_dump(king_get_last_error());
var_dump($captured);
?>
--EXPECT--
bool(true)
string(0) ""
array(10) {
  ["protocol"]=>
  string(8) "http/1.1"
  ["scheme"]=>
  string(4) "http"
  ["local_address"]=>
  string(9) "127.0.0.1"
  ["local_port"]=>
  int(8080)
  ["remote_address"]=>
  string(12) "local-client"
  ["remote_port"]=>
  int(0)
  ["peer_cert"]=>
  NULL
  ["stats_before"]=>
  array(4) {
    ["state"]=>
    string(4) "open"
    ["transport_backend"]=>
    string(18) "server_http1_local"
    ["transport_has_socket"]=>
    bool(false)
    ["server_close_initiated"]=>
    bool(false)
  }
  ["close_result"]=>
  bool(true)
  ["stats_after"]=>
  array(6) {
    ["state"]=>
    string(6) "closed"
    ["transport_backend"]=>
    string(18) "server_http1_local"
    ["transport_has_socket"]=>
    bool(false)
    ["server_close_initiated"]=>
    bool(true)
    ["server_close_error_code"]=>
    int(11)
    ["server_close_reason"]=>
    string(17) "listener-finished"
  }
}
