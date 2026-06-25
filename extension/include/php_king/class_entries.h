/*
 * include/php_king/class_entries.h - Core Zend class-entry externs
 */

#ifndef KING_PHP_KING_CLASS_ENTRIES_H
#define KING_PHP_KING_CLASS_ENTRIES_H

#include <php.h>

extern zend_class_entry
    *king_ce_exception,
    *king_ce_stream_exception,
    *king_ce_invalid_state,
    *king_ce_unknown_stream,
    *king_ce_stream_blocked,
    *king_ce_stream_limit,
    *king_ce_final_size,
    *king_ce_stream_stopped,
    *king_ce_fin_expected,
    *king_ce_invalid_fin_state,
    *king_ce_done,
    *king_ce_quic_exception,
    *king_ce_congestion_control,
    *king_ce_too_many_streams,
    *king_ce_runtime_exception,
    *king_ce_system_exception,
    *king_ce_validation_exception,
    *king_ce_timeout_exception,
    *king_ce_network_exception,
    *king_ce_tls_exception,
    *king_ce_protocol_exception,
    *king_ce_mcp_exception,
    *king_ce_mcp_connection_error,
    *king_ce_mcp_protocol_error,
    *king_ce_mcp_timeout,
    *king_ce_mcp_data_error,
    *king_ce_ws_exception,
    *king_ce_ws_connection_error,
    *king_ce_ws_protocol_error,
    *king_ce_ws_timeout,
    *king_ce_ws_closed;

extern zend_class_entry
    *king_ce_cancel_token,
    *king_ce_awaitable,
    *king_ce_config,
    *king_ce_session,
    *king_ce_stream,
    *king_ce_response,
    *king_ce_mcp,
    *king_ce_mcp_server,
    *king_ce_pipeline_orchestrator,
    *king_ce_object_store,
    *king_ce_autoscaling,
    *king_ce_rtp_socket,
    *king_ce_xslt_processor,
    *king_ce_inference,
    *king_ce_inference_model,
    *king_ce_inference_stream,
    *king_ce_client_http,
    *king_ce_client_http1,
    *king_ce_client_http2,
    *king_ce_client_http3,
    *king_ce_ws_server,
    *king_ce_ws_connection;

#endif /* KING_PHP_KING_CLASS_ENTRIES_H */
