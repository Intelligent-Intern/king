--TEST--
King client HTTP/3 has a real LSQUIC loader contract
--FILE--
<?php
$root = dirname(__DIR__, 2);
$client = (string) file_get_contents($root . '/extension/src/client/http3.c');
$loaderPath = $root . '/extension/src/client/http3/lsquic_loader.inc';
$loader = (string) file_get_contents($loaderPath);

var_dump(file_exists($loaderPath));
var_dump(str_contains($client, '#include "http3/lsquic_loader.inc"'));
var_dump(str_contains($client, 'king_http3_lsquic_api_t'));
var_dump(str_contains($loader, 'dlopen('));
var_dump(str_contains($loader, 'dlsym('));
var_dump(str_contains($loader, 'KING_LSQUIC_LIBRARY'));
var_dump(str_contains($loader, 'lsquic_global_init'));
var_dump(str_contains($loader, 'KING_LSQUIC_GLOBAL_CLIENT'));
var_dump(str_contains($loader, 'lsquic_engine_new'));
var_dump(str_contains($loader, 'lsquic_engine_connect'));
var_dump(str_contains($loader, 'lsquic_engine_process_conns'));
var_dump(str_contains($loader, 'lsquic_engine_send_unsent_packets'));
var_dump(str_contains($loader, 'lsquic_conn_make_stream'));
var_dump(str_contains($loader, 'lsquic_stream_write'));
var_dump(str_contains($loader, 'lsquic_stream_read'));
var_dump(str_contains($loader, 'lsquic_stream_flush'));
var_dump(str_contains($loader, 'king_http3_lsquic.load_error'));
var_dump(str_contains($loader, 'stub'));
var_dump(str_contains($loader, 'fake'));
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
