--TEST--
King direct server listener leaves accept current callable and config forms across active HTTP/1 HTTP/2 and HTTP/3 leaves
--FILE--
<?php
function king_server_listener_string_handler(array $request): array
{
    $GLOBALS['king_server_listener_http1'] = [
        'protocol' => $request['protocol'],
        'method' => $request['method'],
        'uri' => $request['uri'],
        'version' => $request['version'],
        'host' => $request['host'],
        'stream_id' => $request['stream_id'],
        'session_is_resource' => is_resource($request['session']),
        'capability_is_int' => is_int($request['server_session_capability']),
    ];

    return ['status' => 204, 'body' => ''];
}

final class KingServerListenerInvokable
{
    public array $captured = [];

    public function __invoke(array $request): array
    {
        $stats = king_get_stats($request['session']);
        $this->captured = [
            'protocol' => $request['protocol'],
            'version' => $request['version'],
            'stream_id' => $request['stream_id'],
            'authority' => $request['headers'][':authority'],
            'alpn' => $stats['alpn'],
            'transport_backend' => $stats['transport_backend'],
        ];

        return ['status' => 202, 'body' => 'h3'];
    }
}

$config = new King\Config();
$http2Request = null;
$closure = static function (array $request) use (&$http2Request): array {
    $http2Request = [
        'protocol' => $request['protocol'],
        'method' => $request['method'],
        'uri' => $request['uri'],
        'version' => $request['version'],
        'host' => $request['host'],
        'stream_id' => $request['stream_id'],
        'session_is_resource' => is_resource($request['session']),
        'authority' => $request['headers'][':authority'],
    ];

    return ['status' => 200, 'body' => 'ok'];
};
$invokable = new KingServerListenerInvokable();

var_dump(king_http1_server_listen('127.0.0.1', 8443, null, 'king_server_listener_string_handler'));
var_dump(king_get_last_error());
var_dump($GLOBALS['king_server_listener_http1']);

var_dump(king_http2_server_listen('127.0.0.1', 8443, [], $closure));
var_dump(king_get_last_error());
var_dump($http2Request);

var_dump(king_http3_server_listen('127.0.0.1', 8443, $config, $invokable));
var_dump(king_get_last_error());
var_dump($invokable->captured);
?>
--EXPECTF--
bool(true)
string(0) ""
array(8) {
  ["protocol"]=>
  string(8) "http/1.1"
  ["method"]=>
  string(3) "GET"
  ["uri"]=>
  string(1) "/"
  ["version"]=>
  string(8) "HTTP/1.1"
  ["host"]=>
  string(14) "127.0.0.1:8443"
  ["stream_id"]=>
  int(0)
  ["session_is_resource"]=>
  bool(true)
  ["capability_is_int"]=>
  bool(true)
}
bool(true)
string(0) ""
array(8) {
  ["protocol"]=>
  string(6) "http/2"
  ["method"]=>
  string(3) "GET"
  ["uri"]=>
  string(1) "/"
  ["version"]=>
  string(6) "HTTP/2"
  ["host"]=>
  string(14) "127.0.0.1:8443"
  ["stream_id"]=>
  int(1)
  ["session_is_resource"]=>
  bool(true)
  ["authority"]=>
  string(14) "127.0.0.1:8443"
}
bool(true)
string(0) ""
array(6) {
  ["protocol"]=>
  string(6) "http/3"
  ["version"]=>
  string(6) "HTTP/3"
  ["stream_id"]=>
  int(0)
  ["authority"]=>
  string(14) "127.0.0.1:8443"
  ["alpn"]=>
  string(2) "h3"
  ["transport_backend"]=>
  string(18) "server_http3_local"
}
