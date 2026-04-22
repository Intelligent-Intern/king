--TEST--
King client HTTP/3 has a real LSQUIC runtime adapter contract
--FILE--
<?php
$root = dirname(__DIR__, 2);
$client = (string) file_get_contents($root . '/extension/src/client/http3.c');
$runtimePath = $root . '/extension/src/client/http3/lsquic_runtime.inc';
$runtime = (string) file_get_contents($runtimePath);
$helpers = (string) file_get_contents($root . '/extension/src/client/http3/runtime_helpers.inc');
$errors = (string) file_get_contents($root . '/extension/src/client/http3/errors_and_validation.inc');

var_dump(file_exists($runtimePath));
var_dump(str_contains($client, '#include "http3/lsquic_runtime.inc"'));
var_dump(str_contains($client, 'struct lsquic_engine_settings lsquic_settings;'));
var_dump(str_contains($client, 'struct lsquic_engine_api lsquic_api;'));
var_dump(str_contains($client, 'lsquic_engine_t *lsquic_engine;'));
var_dump(str_contains($runtime, 'king_http3_lsquic_runtime_init'));
var_dump(str_contains($runtime, 'king_http3_lsquic_runtime_destroy'));
var_dump(str_contains($runtime, 'king_http3_lsquic_runtime_prepare_request'));
var_dump(str_contains($runtime, 'king_http3_lsquic_send_headers'));
var_dump(str_contains($runtime, 'king_http3_lsquic_write_body'));
var_dump(str_contains($runtime, 'king_http3_lsquic_read_response'));
var_dump(str_contains($runtime, 'king_http3_lsquic_hset_if'));
var_dump(str_contains($runtime, 'king_http3_lsquic_hsi_process_header'));
var_dump(str_contains($runtime, 'king_http3_lsquic_packets_out'));
var_dump(str_contains($runtime, 'king_http3_lsquic_runtime_packet_in'));
var_dump(str_contains($runtime, 'sendmsg(runtime->socket_fd'));
var_dump(str_contains($runtime, 'lsquic_engine_packet_in_fn'));
var_dump(str_contains($runtime, 'lsquic_engine_init_settings_fn'));
var_dump(str_contains($runtime, 'lsquic_engine_check_settings_fn'));
var_dump(str_contains($runtime, 'lsquic_engine_new_fn'));
var_dump(str_contains($runtime, 'lsquic_engine_connect_fn'));
var_dump(str_contains($runtime, 'N_LSQVER'));
var_dump(str_contains($runtime, 'LSENG_HTTP'));
var_dump(str_contains($runtime, 'king_http3_lsquic_stream_if'));
var_dump(str_contains($runtime, '.on_new_stream = king_http3_lsquic_on_new_stream'));
var_dump(str_contains($runtime, '.on_sess_resume_info = king_http3_lsquic_on_sess_resume_info'));
var_dump(str_contains($runtime, 'lsquic_stream_send_headers_fn'));
var_dump(str_contains($runtime, 'lsquic_stream_write_fn'));
var_dump(str_contains($runtime, 'lsquic_stream_read_fn'));
var_dump(str_contains($runtime, 'lsquic_stream_get_hset_fn'));
var_dump(str_contains($runtime, 'lsquic_stream_shutdown_fn'));
var_dump(str_contains($runtime, 'lsxpack_header_set_offset2'));
var_dump(str_contains($runtime, 'lsxpack_header_prepare_decode'));
var_dump(str_contains($runtime, 'king_http3_collect_response_header'));
var_dump(str_contains($runtime, 'smart_str_appendl(&runtime->lsquic_response->body'));
var_dump(str_contains($runtime, 'runtime->lsquic_response->response_complete = true'));
var_dump(str_contains($runtime, 'king_http3_lsquic.lsquic_conn_make_stream_fn(runtime->lsquic_conn)'));
var_dump(str_contains($runtime, 'king_ticket_ring_get(runtime->lsquic_session_resume'));
var_dump(str_contains($runtime, 'king_ticket_ring_put(session, session_len)'));
var_dump(str_contains($runtime, 'LSQ_HSK_RESUMED_OK'));
var_dump(str_contains($runtime, 'runtime->quic_packets_received++'));
var_dump(str_contains($runtime, 'lsquic_engine_earliest_adv_tick_fn'));
var_dump(str_contains($runtime, 'king_secure_zero(runtime->lsquic_session_resume'));
var_dump(str_contains($helpers, 'king_http3_lsquic_runtime_destroy(runtime)'));
var_dump(str_contains($helpers, 'king_http3_lsquic_seed_ticket_from_ring(runtime)'));
var_dump(str_contains($errors, 'king_http3_lsquic_refresh_transport_stats(runtime)'));
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
