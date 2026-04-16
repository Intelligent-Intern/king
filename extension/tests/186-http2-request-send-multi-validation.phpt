--TEST--
King HTTP/2 multi request leaf exposes stable validation contracts
--SKIPIF--
<?php
if (PHP_OS === 'Darwin') {
    die("skip HTTP/2 runtime requires libcurl.so (Linux) - not available on macOS");
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$pushDisabledCfg = king_new_config([
    'http2.enable_push' => false,
]);

var_dump(king_http2_request_send_multi([]));
var_dump(king_get_last_error());

var_dump(king_http2_request_send_multi([
    ['url' => 'http://127.0.0.1:80/one'],
    ['url' => 'https://127.0.0.1:443/two'],
]));
var_dump(king_get_last_error());

var_dump(king_http2_request_send_multi([
    ['url' => 'http://127.0.0.1:80/one'],
    'bad',
]));
var_dump(king_get_last_error());

var_dump(king_http2_request_send_multi([
    ['url' => 'http://127.0.0.1:80/one'],
], [
    'capture_push' => 'yes',
]));
var_dump(king_get_last_error());

var_dump(king_http2_request_send_multi([
    ['url' => 'http://127.0.0.1:80/one'],
], [
    'capture_push' => true,
    'connection_config' => $pushDisabledCfg,
]));
var_dump(king_get_last_error());
?>
--EXPECTF--
bool(false)
string(%d) "king_http2_request_send_multi() requires at least one request definition."
bool(false)
string(%d) "king_http2_request_send_multi() currently requires all requests to target the same HTTP/2 origin and TLS profile."
bool(false)
string(%d) "king_http2_request_send_multi() request #2 must be provided as an array."
bool(false)
string(%d) "king_http2_request_send_multi() option 'capture_push' must be provided as a boolean."
bool(false)
string(%d) "king_http2_request_send_multi() option 'capture_push' requires http2.enable_push to be enabled."
