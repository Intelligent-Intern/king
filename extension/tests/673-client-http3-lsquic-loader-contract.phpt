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
var_dump(str_contains($client, 'typedef struct _king_http3_header'));
var_dump(str_contains($client, 'king_http3_header_t *request_headers;'));
var_dump(str_contains($client, "#if !defined(KING_HTTP3_BACKEND_LSQUIC)\n#include <quiche.h>"));
var_dump(str_contains($client, "#if !defined(KING_HTTP3_BACKEND_LSQUIC)\n#include \"http3/quiche_loader.inc\""));
var_dump(str_contains($client, 'quiche_h3_header'));
var_dump(str_contains($loader, 'dlopen('));
var_dump(str_contains($loader, 'dlsym('));
var_dump(str_contains($loader, 'KING_LSQUIC_LIBRARY'));
var_dump(str_contains($loader, 'lsquic_global_init'));
var_dump(str_contains($loader, 'KING_LSQUIC_GLOBAL_CLIENT'));
var_dump(str_contains($loader, 'lsquic_engine_init_settings'));
var_dump(str_contains($loader, 'lsquic_engine_check_settings'));
var_dump(str_contains($loader, 'lsquic_engine_new'));
var_dump(str_contains($loader, 'lsquic_engine_connect'));
var_dump(str_contains($loader, 'lsquic_engine_packet_in'));
var_dump(str_contains($loader, 'lsquic_engine_process_conns'));
var_dump(str_contains($loader, 'lsquic_engine_send_unsent_packets'));
var_dump(str_contains($loader, 'lsquic_engine_get_conns_count'));
var_dump(str_contains($loader, 'lsquic_engine_count_attq'));
var_dump(str_contains($client, 'int (*lsquic_engine_earliest_adv_tick_fn)(void *, int *);'));
var_dump(str_contains($client, 'int (*lsquic_engine_earliest_adv_tick_fn)(void *, long *, int *);'));
var_dump(str_contains($loader, 'lsquic_conn_make_stream'));
var_dump(str_contains($client, 'void (*lsquic_conn_make_stream_fn)(void *);'));
var_dump(str_contains($client, 'void *(*lsquic_conn_make_stream_fn)(void *);'));
var_dump(str_contains($loader, 'lsquic_conn_n_avail_streams'));
var_dump(str_contains($loader, 'lsquic_conn_n_pending_streams'));
var_dump(str_contains($loader, 'lsquic_conn_cancel_pending_streams'));
var_dump(str_contains($loader, 'lsquic_conn_status'));
var_dump(str_contains($loader, 'lsquic_stream_send_headers'));
var_dump(str_contains($loader, 'lsquic_stream_get_hset'));
var_dump(str_contains($loader, 'lsquic_stream_write'));
var_dump(str_contains($loader, 'lsquic_stream_read'));
var_dump(str_contains($loader, 'lsquic_stream_flush'));
var_dump(str_contains($loader, 'lsquic_stream_shutdown'));
var_dump(str_contains($loader, 'lsquic_stream_get_ctx'));
var_dump(str_contains($loader, 'lsquic_stream_set_ctx'));
var_dump(str_contains($loader, 'lsquic_conn_get_ctx'));
var_dump(str_contains($loader, 'lsquic_conn_set_ctx'));
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
bool(false)
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
bool(true)
bool(true)
bool(false)
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
