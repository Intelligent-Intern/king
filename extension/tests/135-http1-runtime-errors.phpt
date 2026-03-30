--TEST--
King HTTP runtimes expose stable validation and protocol error contracts
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http_tls_mismatch_test_helper.inc';

try {
    king_http1_request_send('https://127.0.0.1/');
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_starts_with(
        $e->getMessage(),
        'king_http1_request_send() HTTPS requests are not available'
    ));
}

$cfg = king_new_config([
    'tcp.enable' => false,
]);

var_dump(king_http1_request_send(
    'http://127.0.0.1:80/',
    'GET',
    null,
    null,
    ['connection_config' => $cfg]
));
var_dump(king_get_last_error());

$http2DisabledCfg = king_new_config([
    'http2.enable' => false,
]);

var_dump(king_http2_request_send(
    'http://127.0.0.1:80/',
    'GET',
    null,
    null,
    ['connection_config' => $http2DisabledCfg]
));
var_dump(king_get_last_error());

$tlsMismatchServer = king_http_tls_mismatch_start_server();

try {
    king_client_send_request(
        'https://127.0.0.1:' . $tlsMismatchServer[2] . '/',
        'GET',
        null,
        null,
        ['preferred_protocol' => 'http2']
    );
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_starts_with(
        $e->getMessage(),
        'king_client_send_request() libcurl HTTP/2 transfer failed:'
    ));
    var_dump(str_starts_with(
        king_get_last_error(),
        'king_client_send_request() libcurl HTTP/2 transfer failed:'
    ));
} finally {
    king_http_tls_mismatch_stop_server($tlsMismatchServer);
}

try {
    king_client_send_request(
        'http://127.0.0.1:80/',
        'GET',
        null,
        null,
        [
            'preferred_protocol' => 'http2',
            'response_stream' => true,
        ]
    );
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_starts_with(
        $e->getMessage(),
        'HTTP/1 response_stream mode is not available on the active HTTP/2 runtime.'
    ));
}

try {
    king_receive_response(fopen('php://memory', 'r'));
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'King\HttpRequestContext'));
}
?>
--EXPECTF--
string(17) "King\TlsException"
bool(true)
bool(false)
string(%d) "king_http1_request_send() cannot issue an HTTP/1 request while tcp.enable is disabled."
bool(false)
string(%d) "king_http2_request_send() cannot issue an HTTP/2 request while http2.enable is disabled."
string(17) "King\TlsException"
bool(true)
bool(true)
string(22) "King\ProtocolException"
bool(true)
string(9) "TypeError"
bool(true)
