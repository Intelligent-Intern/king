--TEST--
King client HTTP/3 has a real LSQUIC runtime adapter contract
--FILE--
<?php
$root = dirname(__DIR__, 2);
$client = (string) file_get_contents($root . '/extension/src/client/http3.c');
$streamRuntime = (string) file_get_contents($root . '/extension/src/client/http3/lsquic_stream_runtime.inc');
$runtimePath = $root . '/extension/src/client/http3/lsquic_runtime.inc';
$runtime = (string) file_get_contents($runtimePath);
$lsquicDispatch = (string) file_get_contents($root . '/extension/src/client/http3/lsquic_dispatch.inc');
$lsquicMultiDispatch = (string) file_get_contents($root . '/extension/src/client/http3/lsquic_multi_dispatch.inc');
$runtimeInit = (string) file_get_contents($root . '/extension/src/client/http3/runtime_init.inc');
$requestResponse = (string) file_get_contents($root . '/extension/src/client/http3/request_response.inc');
$dispatch = (string) file_get_contents($root . '/extension/src/client/http3/dispatch_api.inc');
$helpers = (string) file_get_contents($root . '/extension/src/client/http3/runtime_helpers.inc');
$errors = (string) file_get_contents($root . '/extension/src/client/http3/errors_and_validation.inc');

var_dump(file_exists($runtimePath));
var_dump(str_contains($client, '#include "http3/lsquic_stream_runtime.inc"'));
var_dump(str_contains($client, '#include "http3/lsquic_runtime.inc"'));
var_dump(str_contains($client, '#include "http3/lsquic_dispatch.inc"'));
var_dump(str_contains($client, '#include "http3/lsquic_multi_dispatch.inc"'));
var_dump(str_contains($client, 'struct lsquic_engine_settings lsquic_settings;'));
var_dump(str_contains($client, 'struct lsquic_engine_api lsquic_api;'));
var_dump(str_contains($client, 'lsquic_engine_t *lsquic_engine;'));
var_dump(str_contains($client, 'king_http3_lsquic_request_state_t *lsquic_pending_requests;'));
var_dump(str_contains($client, 'size_t lsquic_pending_request_count;'));
var_dump(str_contains($client, 'size_t lsquic_pending_request_offset;'));
var_dump(str_contains($runtime, 'king_http3_lsquic_runtime_init'));
var_dump(str_contains($runtime, 'king_http3_lsquic_runtime_destroy'));
var_dump(str_contains($runtime, 'king_http3_lsquic_runtime_prepare_request'));
var_dump(str_contains($runtime, 'king_http3_lsquic_runtime_prepare_requests'));
var_dump(str_contains($runtime, 'king_http3_lsquic_request_state_init'));
var_dump(str_contains($streamRuntime, 'struct _king_http3_lsquic_request_state'));
var_dump(str_contains($streamRuntime, 'king_http3_lsquic_request_from_stream_ctx'));
var_dump(str_contains($streamRuntime, 'king_http3_lsquic_request_from_stream'));
var_dump(str_contains($streamRuntime, 'king_http3_lsquic_runtime_peek_pending_request'));
var_dump(str_contains($streamRuntime, 'king_http3_lsquic_runtime_take_pending_request'));
var_dump(str_contains($streamRuntime, 'king_http3_lsquic_send_headers'));
var_dump(str_contains($streamRuntime, 'king_http3_lsquic_write_body'));
var_dump(str_contains($streamRuntime, 'king_http3_lsquic_read_response'));
var_dump(str_contains($streamRuntime, 'king_http3_lsquic_hset_if'));
var_dump(str_contains($streamRuntime, 'king_http3_lsquic_hsi_process_header'));
var_dump(str_contains($streamRuntime, 'king_http3_lsquic_packets_out'));
var_dump(str_contains($runtime, 'king_http3_lsquic_runtime_packet_in'));
var_dump(str_contains($streamRuntime, 'sendmsg(runtime->socket_fd'));
var_dump(str_contains($runtime, 'lsquic_engine_packet_in_fn'));
var_dump(str_contains($runtime, 'lsquic_engine_init_settings_fn'));
var_dump(str_contains($runtime, 'lsquic_engine_check_settings_fn'));
var_dump(str_contains($runtime, 'lsquic_engine_new_fn'));
var_dump(str_contains($runtime, 'lsquic_engine_connect_fn'));
var_dump(str_contains($runtime, 'N_LSQVER'));
var_dump(str_contains($runtime, 'LSENG_HTTP'));
var_dump(str_contains($streamRuntime, 'king_http3_lsquic_stream_if'));
var_dump(str_contains($streamRuntime, '.on_new_stream = king_http3_lsquic_on_new_stream'));
var_dump(str_contains($streamRuntime, '.on_sess_resume_info = king_http3_lsquic_on_sess_resume_info'));
var_dump(str_contains($client, 'lsquic_stream_send_headers_fn'));
var_dump(str_contains($client, 'lsquic_stream_write_fn'));
var_dump(str_contains($client, 'lsquic_stream_read_fn'));
var_dump(str_contains($client, 'lsquic_stream_get_hset_fn'));
var_dump(str_contains($client, 'lsquic_stream_shutdown_fn'));
var_dump(str_contains($streamRuntime, 'lsxpack_header_set_offset2'));
var_dump(str_contains($streamRuntime, 'lsxpack_header_prepare_decode'));
var_dump(str_contains($streamRuntime, 'king_http3_collect_response_header'));
var_dump(str_contains($streamRuntime, 'smart_str_appendl(&request->response->body'));
var_dump(str_contains($streamRuntime, 'request->response->response_complete = true'));
var_dump(str_contains($runtime, 'king_http3_lsquic.lsquic_conn_make_stream_fn(runtime->lsquic_conn)'));
var_dump(str_contains($runtime, 'runtime->lsquic_pending_requests = requests'));
var_dump(str_contains($runtime, 'runtime->lsquic_pending_request_count = request_count'));
var_dump(str_contains($streamRuntime, 'request->headers_sent = true'));
var_dump(str_contains($streamRuntime, 'request->request_failed = true'));
var_dump(str_contains($runtime, 'king_ticket_ring_get(runtime->lsquic_session_resume'));
var_dump(str_contains($streamRuntime, 'king_ticket_ring_put(session, session_len)'));
var_dump(str_contains($streamRuntime, 'LSQ_HSK_RESUMED_OK'));
var_dump(str_contains($runtime, 'runtime->quic_packets_received++'));
var_dump(str_contains($runtime, 'lsquic_engine_earliest_adv_tick_fn'));
var_dump(str_contains($runtime, 'king_secure_zero(runtime->lsquic_session_resume'));
var_dump(str_contains($helpers, 'king_http3_lsquic_runtime_destroy(runtime)'));
var_dump(str_contains($helpers, 'king_http3_lsquic_seed_ticket_from_ring(runtime)'));
var_dump(str_contains($helpers, 'king_http3_lsquic_runtime_process_egress(runtime, "king_http3_propagate_cancel_close")'));
var_dump(str_contains($errors, 'king_http3_lsquic_refresh_transport_stats(runtime)'));
var_dump(str_contains($runtimeInit, 'king_http3_runtime_open_udp_socket'));
var_dump(str_contains($runtimeInit, 'SOCK_DGRAM'));
var_dump(str_contains($lsquicDispatch, 'king_http3_execute_request_lsquic'));
var_dump(str_contains($lsquicDispatch, 'king_http3_lsquic_drive_requests'));
var_dump(str_contains($lsquicDispatch, 'king_http3_lsquic_requests_have_started'));
var_dump(str_contains($lsquicMultiDispatch, 'king_http3_execute_multi_requests_lsquic'));
var_dump(str_contains($lsquicMultiDispatch, 'king_http3_lsquic_runtime_prepare_requests'));
var_dump(str_contains($lsquicMultiDispatch, 'king_http3_lsquic_drive_requests'));
var_dump(str_contains($dispatch, 'king_http3_ensure_lsquic_ready()'));
var_dump(str_contains($dispatch, 'king_http3_throw_lsquic_unavailable(function_name)'));
var_dump(str_contains($dispatch, 'king_http3_execute_request_lsquic('));
var_dump(str_contains($dispatch, 'king_http3_execute_multi_requests_lsquic('));
var_dump(str_contains($lsquicDispatch, 'king_http3_runtime_open_udp_socket(&runtime, &target, function_name)'));
var_dump(str_contains($lsquicDispatch, 'king_http3_lsquic_runtime_init(&runtime, &target, options, function_name)'));
var_dump(str_contains($lsquicDispatch, 'king_http3_lsquic_runtime_prepare_request('));
var_dump(str_contains($lsquicDispatch, 'king_http3_lsquic_runtime_process_egress(runtime, function_name)'));
var_dump(str_contains($lsquicDispatch, 'king_http3_lsquic_runtime_packet_in('));
var_dump(str_contains($lsquicDispatch, 'king_http3_lsquic_poll_timeout_ms(runtime'));
var_dump(str_contains($requestResponse, '"lsquic_h3"'));
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
