/*
 * Root function table for the King extension. Maps the public procedural
 * surface onto the shared arginfo blocks declared in this module.
 */

static const zend_function_entry king_functions[] = {
    PHP_FE(king_version, arginfo_king_no_args)
    PHP_FE(king_health, arginfo_king_no_args)
    PHP_FE(king_new_config, arginfo_king_new_config)
    PHP_FE(king_await, arginfo_king_await)
    PHP_FE(king_awaitable_poll, arginfo_king_awaitable_poll)
    PHP_FE(king_awaitable_cancel, arginfo_king_awaitable_cancel)
    PHP_FE(king_awaitable_status, arginfo_king_awaitable_status)
    PHP_FE(king_awaitable_any, arginfo_king_awaitable_any)
    PHP_FE(king_awaitable_all, arginfo_king_awaitable_all)

    PHP_FE(king_connect, arginfo_king_connect)
    PHP_FE(king_close, arginfo_king_session_resource)
    PHP_FE(king_send_request, arginfo_king_request_send)
    PHP_FE(king_send_request_async, arginfo_king_request_send_async)
    PHP_FE(king_receive_response, arginfo_king_receive_response)
    PHP_FE(king_poll, arginfo_king_poll)
    PHP_FE(king_cancel_stream, arginfo_king_cancel_stream)
    PHP_FE(king_export_session_ticket, arginfo_king_session_ticket_export)
    PHP_FE(king_import_session_ticket, arginfo_king_session_ticket_import)
    PHP_FE(king_set_ca_file, arginfo_king_set_ca_file)
    PHP_FE(king_set_client_cert, arginfo_king_set_client_cert)
    PHP_FE(king_get_last_error, arginfo_king_no_args)
    PHP_FE(king_get_stats, arginfo_king_session_resource)
#include "../db_ingest/function_entry.inc"

    PHP_FE(king_client_send_request, arginfo_king_request_send)
    PHP_FE(king_client_send_request_async, arginfo_king_request_send_async)
    PHP_FE(king_client_stream_cancel, arginfo_king_cancel_stream)
    PHP_FE(king_client_tls_set_ca_file, arginfo_king_set_ca_file)
    PHP_FE(king_client_tls_set_client_cert, arginfo_king_set_client_cert)
    PHP_FE(king_client_tls_export_session_ticket, arginfo_king_session_ticket_export)
    PHP_FE(king_client_tls_import_session_ticket, arginfo_king_session_ticket_import)
    PHP_FE(king_client_early_hints_get_pending, arginfo_king_request_context_resource)
    PHP_FE(king_client_early_hints_process, arginfo_king_early_hints_process)

    PHP_FE(king_http1_request_send, arginfo_king_request_send)
    PHP_FE(king_http1_request_send_async, arginfo_king_request_send_async)
    PHP_FE(king_http2_request_send, arginfo_king_request_send)
    PHP_FE(king_http2_request_send_async, arginfo_king_request_send_async)
    PHP_FE(king_http2_request_send_multi, arginfo_king_http2_request_send_multi)
    PHP_FE(king_http2_request_send_multi_async, arginfo_king_http2_request_send_multi_async)
    PHP_FE(king_http3_request_send, arginfo_king_request_send)
    PHP_FE(king_http3_request_send_async, arginfo_king_request_send_async)
    PHP_FE(king_http3_request_send_multi, arginfo_king_http3_request_send_multi)
    PHP_FE(king_http3_request_send_multi_async, arginfo_king_http3_request_send_multi_async)

    PHP_FE(king_client_websocket_connect, arginfo_king_websocket_connect)
    PHP_FE(king_client_websocket_connect_async, arginfo_king_websocket_connect_async)
    PHP_FE(king_client_websocket_send, arginfo_king_websocket_send)
    PHP_FE(king_client_websocket_send_async, arginfo_king_websocket_send_async)
    PHP_FE(king_client_websocket_receive, arginfo_king_websocket_receive)
    PHP_FE(king_client_websocket_receive_async, arginfo_king_websocket_receive_async)
    PHP_FE(king_client_websocket_ping, arginfo_king_websocket_ping)
    PHP_FE(king_client_websocket_get_status, arginfo_king_websocket_status)
    PHP_FE(king_client_websocket_get_last_error, arginfo_king_no_args)
    PHP_FE(king_client_websocket_close, arginfo_king_websocket_close)
    PHP_FE(king_websocket_send, arginfo_king_websocket_send)

    PHP_FE(king_http1_server_listen, arginfo_king_server_listen)
    PHP_FE(king_http1_server_listen_once, arginfo_king_server_listen)
    PHP_FE(king_http2_server_listen, arginfo_king_server_listen)
    PHP_FE(king_http2_server_listen_once, arginfo_king_server_listen)
    PHP_FE(king_http3_server_listen, arginfo_king_server_listen)
    PHP_FE(king_http3_server_listen_once, arginfo_king_server_listen)
    PHP_FE(king_server_listen, arginfo_king_server_listen)
    PHP_FE(king_server_on_cancel, arginfo_king_server_on_cancel)
    PHP_FE(king_server_send_early_hints, arginfo_king_server_send_early_hints)
    PHP_FE(king_server_upgrade_to_websocket, arginfo_king_server_upgrade_to_websocket)
    PHP_FE(king_rtp_bind, arginfo_king_rtp_bind)
    PHP_FE(king_rtp_ice_credentials, arginfo_king_rtp_ice_credentials)
    PHP_FE(king_rtp_dtls_fingerprint, arginfo_king_rtp_dtls_fingerprint)
    PHP_FE(king_rtp_dtls_accept, arginfo_king_rtp_dtls_accept)
    PHP_FE(king_rtp_recv, arginfo_king_rtp_recv)
    PHP_FE(king_rtp_send, arginfo_king_rtp_send)
    PHP_FE(king_rtp_close, arginfo_king_rtp_close)
    PHP_FE(king_server_reload_tls_config, arginfo_king_server_reload_tls_config)
    PHP_FE(king_server_init_telemetry, arginfo_king_server_init_telemetry)
    PHP_FE(king_session_get_peer_cert_subject, arginfo_king_session_capability)
    PHP_FE(king_session_close_server_initiated, arginfo_king_session_close_server_initiated)
    PHP_FE(king_admin_api_listen, arginfo_king_admin_api_listen)

    PHP_FE(king_proto_define_schema, arginfo_king_proto_define_schema)
    PHP_FE(king_proto_define_enum, arginfo_king_proto_define_enum)
    PHP_FE(king_proto_encode, arginfo_king_proto_encode)
    PHP_FE(king_proto_encode_batch, arginfo_king_proto_encode_batch)
    PHP_FE(king_proto_decode, arginfo_king_proto_decode)
    PHP_FE(king_proto_decode_batch, arginfo_king_proto_decode_batch)
    PHP_FE(king_proto_is_schema_defined, arginfo_king_one_string)
    PHP_FE(king_proto_is_enum_defined, arginfo_king_one_string)
    PHP_FE(king_proto_is_defined, arginfo_king_one_string)
    PHP_FE(king_proto_get_defined_schemas, arginfo_king_no_args)
    PHP_FE(king_proto_get_defined_enums, arginfo_king_no_args)

#include "../mcp/php_binding/function_entries.inc"

    PHP_FE(king_pipeline_orchestrator_run, arginfo_king_pipeline_run)
    PHP_FE(king_pipeline_orchestrator_run_async, arginfo_king_pipeline_run_async)
    PHP_FE(king_pipeline_orchestrator_dispatch, arginfo_king_pipeline_run)
    PHP_FE(king_pipeline_orchestrator_dispatch_async, arginfo_king_pipeline_run_async)
    PHP_FE(king_pipeline_orchestrator_register_tool, arginfo_king_pipeline_register_tool)
    PHP_FE(king_pipeline_orchestrator_register_handler, arginfo_king_pipeline_register_handler)
    PHP_FE(king_pipeline_orchestrator_configure_logging, arginfo_king_config_array)
    PHP_FE(king_pipeline_orchestrator_worker_run_next, arginfo_king_no_args)
    PHP_FE(king_pipeline_orchestrator_resume_run, arginfo_king_pipeline_get_run)
    PHP_FE(king_pipeline_orchestrator_get_run, arginfo_king_pipeline_get_run)
    PHP_FE(king_pipeline_orchestrator_cancel_run, arginfo_king_pipeline_get_run)

#include "../semantic_dns/function_entries.inc"

#include "../object_store/function_entries.inc"

#include "../xslt/function_entries.inc"
#include "../inference/function_entries.inc"

#include "../telemetry/function_entries.inc"

#include "../autoscaling/function_entries.inc"

    PHP_FE(king_system_init, arginfo_king_config_array)
    PHP_FE(king_system_health_check, arginfo_king_no_args)
    PHP_FE(king_system_get_status, arginfo_king_no_args)
    PHP_FE(king_system_get_metrics, arginfo_king_no_args)
    PHP_FE(king_system_get_performance_report, arginfo_king_no_args)
    PHP_FE(king_system_get_component_info, arginfo_king_one_string)
    PHP_FE(king_system_process_request, arginfo_king_system_process_request)
    PHP_FE(king_system_restart_component, arginfo_king_one_string)
    PHP_FE(king_system_fail_component, arginfo_king_one_string)
    PHP_FE(king_system_recover, arginfo_king_no_args)
    PHP_FE(king_system_shutdown, arginfo_king_no_args)

    PHP_FE_END
};
