--TEST--
King HTTP/2 libcurl errors use the detail buffer to classify connection aborts
--FILE--
<?php
$root = dirname(__DIR__);
$http2 = (string) file_get_contents($root . '/src/client/http2.c');
$configure = (string) file_get_contents($root . '/src/client/http2/request/dispatch/configure.inc');
$response = (string) file_get_contents($root . '/src/client/http2/response.inc');
$dispatch = (string) file_get_contents($root . '/src/client/http2/request/dispatch/response.inc');
$multi = (string) file_get_contents($root . '/src/client/http2/request/multi/collect_and_attach.inc');

var_dump(str_contains($http2, 'char error_buffer[CURL_ERROR_SIZE];'));
var_dump(str_contains($configure, 'CURLOPT_ERRORBUFFER'));
var_dump(str_contains($response, 'king_http2_curl_detail_is_connection_abort'));
var_dump(str_contains($response, 'before end of the underlying stream'));
var_dump(str_contains($response, '#if defined(__APPLE__)'));
var_dump(str_contains($response, 'Error in the HTTP2 framing layer'));
var_dump(str_contains($response, 'king_http2_map_curl_exception_with_detail'));
var_dump(str_contains($dispatch, 'king_http2_curl_error_detail(curl_code, response)'));
var_dump(str_contains($dispatch, 'king_http2_map_curl_exception_with_detail(curl_code, curl_detail)'));
var_dump(str_contains($multi, 'king_http2_curl_error_detail('));
var_dump(str_contains($multi, 'king_http2_map_curl_exception_with_detail(transfer->curl_code, curl_detail)'));
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
