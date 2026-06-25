/*
 * Shared arginfo block for the King extension. Centralizes the procedural and
 * OO signature metadata consumed by the module function table and class
 * registrations.
 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_no_args, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_await, 0, 1, IS_MIXED, 0)
    ZEND_ARG_OBJ_INFO(0, awaitable, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_awaitable_poll, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_OBJ_INFO(0, awaitable, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_awaitable_cancel, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_OBJ_INFO(0, awaitable, King\\Awaitable, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_awaitable_status, 0, 1, IS_STRING, 0)
    ZEND_ARG_OBJ_INFO(0, awaitable, King\\Awaitable, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_awaitable_any, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, awaitables, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_awaitable_all, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, awaitables, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Awaitable___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_await, 0, 0, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_poll, 0, 0, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_cancel, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_isPending, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_isDone, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_isCancelled, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_getStatus, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_getOperation, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Awaitable_any, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, awaitables, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Awaitable_all, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, awaitables, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_one_string, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_one_long, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, value, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_connect, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, config, IS_MIXED, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_new_config, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_session_resource, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_poll, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_cancel_stream, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, stream_id, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, how, IS_STRING, 0, "\"both\"")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, session, IS_MIXED, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_set_ca_file, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_set_client_cert, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, cert, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_session_ticket_export, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_session_ticket_import, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, ticket, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_optional_headers, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_headers, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, headers, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_config_array, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, config, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_service_discovery, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, service_type, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, criteria, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_service_lookup, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, service_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, client_info, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_register_service, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, service_info, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_register_mother_node, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, mother_node_info, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_update_service_status, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, service_id, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, status, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, metrics, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_encode, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_encode_batch, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, records, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_decode, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, binary_data, IS_STRING, 0)
    ZEND_ARG_TYPE_MASK(0, decode_as_object, MAY_BE_BOOL|MAY_BE_STRING|MAY_BE_ARRAY, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_decode_batch, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, binary_records, IS_ARRAY, 0)
    ZEND_ARG_TYPE_MASK(0, decode_as_object, MAY_BE_BOOL|MAY_BE_STRING|MAY_BE_ARRAY, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_define_enum, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, enum_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, enum_values, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_define_schema, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, schema_definition, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_request_send, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, method, IS_STRING, 1, "\"GET\"")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, body, IS_MIXED, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_request_send_async, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, method, IS_STRING, 1, "\"GET\"")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, body, IS_MIXED, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_http2_request_send_multi, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, requests, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_http2_request_send_multi_async, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, requests, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_http3_request_send_multi, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, requests, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_http3_request_send_multi_async, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, requests, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_receive_response, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, request_context, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_early_hints_process, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, request_context, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, headers, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_request_context_resource, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, request_context, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_websocket_connect, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_websocket_connect_async, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_websocket_send, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, websocket, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, is_binary, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_websocket_send_async, 0, 2, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, websocket, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, is_binary, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_websocket_receive, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, websocket, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "-1")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_websocket_receive_async, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, websocket, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "-1")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_websocket_ping, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, websocket, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, payload, IS_STRING, 0, "\"\"")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_websocket_status, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, websocket, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_websocket_close, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, websocket, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, status_code, IS_LONG, 0, "1000")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, reason, IS_STRING, 0, "\"\"")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_server_listen, 0, 0, 4)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, config, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, handler, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_server_on_cancel, 0, 0, 3)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, stream_id, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, handler, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_server_send_early_hints, 0, 0, 3)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, stream_id, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, hints, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_server_upgrade_to_websocket, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, stream_id, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_rtp_bind, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_rtp_ice_credentials, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, socket, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_rtp_dtls_fingerprint, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, socket, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_rtp_dtls_accept, 0, 0, 3)
    ZEND_ARG_TYPE_INFO(0, socket, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, ip, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "5000")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_rtp_recv, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, socket, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_rtp_send, 0, 0, 4)
    ZEND_ARG_TYPE_INFO(0, socket, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_rtp_close, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, socket, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_server_reload_tls_config, 0, 0, 3)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, cert_file_path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, key_file_path, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_server_init_telemetry, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, config, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_session_capability, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, capability, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_session_close_server_initiated, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, capability, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, error_code, IS_LONG, 0, "0")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, reason, IS_STRING, 0, "\"\"")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_admin_api_listen, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, target_server, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, config, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_mcp_connect, 0, 0, 3)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, config, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_mcp_request, 0, 0, 4)
    ZEND_ARG_TYPE_INFO(0, connection, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, service_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, request_payload, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_mcp_request_async, 0, 4, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, connection, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, service_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, request_payload, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_mcp_request_iibin, 0, 0, 4)
    ZEND_ARG_TYPE_INFO(0, connection, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, service_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_mcp_request_iibin_async, 0, 4, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, connection, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, service_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_mcp_server_create, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, definition, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_mcp_server_handle_jsonrpc, 0, 2, IS_STRING, 1)
    ZEND_ARG_TYPE_INFO(0, server, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, context, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_mcp_server_handle_http, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, server, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, request, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, context, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_mcp_server_stdio, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, server, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_mcp_close, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, connection, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_mcp_upload_from_stream, 0, 0, 5)
    ZEND_ARG_TYPE_INFO(0, connection, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, service_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stream_identifier, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stream, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_mcp_download_to_stream, 0, 0, 5)
    ZEND_ARG_TYPE_INFO(0, connection, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, service_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, request_payload, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stream, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_pipeline_run, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, initial_data, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, pipeline, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, exec_options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_pipeline_run_async, 0, 2, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, initial_data, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, pipeline, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, exec_options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_pipeline_register_tool, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, tool_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, config, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_pipeline_register_handler, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, tool_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, handler, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_pipeline_get_run, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, run_id, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_system_process_request, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, request_data, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_xslt_transform_file, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, source_path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stylesheet_path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_xslt_transform_to_file, 0, 0, 3)
    ZEND_ARG_TYPE_INFO(0, source_path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stylesheet_path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, output_path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_inference_model_load, 0, 1, King\\Inference\\Model, 0)
    ZEND_ARG_TYPE_INFO(0, config, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_inference_model_info, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, model, King\\Inference\\Model, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_inference_tokenize, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, model, King\\Inference\\Model, 0)
    ZEND_ARG_TYPE_INFO(0, text, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_inference_tensor_view, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, model, King\\Inference\\Model, 0)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_inference_tensor_index, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, model, King\\Inference\\Model, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_inference_tensor_dequantize, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, model, King\\Inference\\Model, 0)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_inference_tensor_matmul, 0, 3, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, model, King\\Inference\\Model, 0)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, input, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_inference_graph_run, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, model, King\\Inference\\Model, 0)
    ZEND_ARG_TYPE_INFO(0, graph, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_inference_kv_cache_plan, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, model, King\\Inference\\Model, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, request, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_inference_stream, 0, 2, King\\Inference\\Stream, 0)
    ZEND_ARG_OBJ_INFO(0, model, King\\Inference\\Model, 0)
    ZEND_ARG_TYPE_INFO(0, request, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_inference_openai_chat_http_response, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, model, King\\Inference\\Model, 0)
    ZEND_ARG_TYPE_INFO(0, request, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_inference_openai_http_response, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, models, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, request, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_inference_next, 0, 1, IS_ARRAY, 1)
    ZEND_ARG_OBJ_INFO(0, stream, King\\Inference\\Stream, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_inference_next_async, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_OBJ_INFO(0, stream, King\\Inference\\Stream, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_inference_cancel, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_OBJ_INFO(0, stream, King\\Inference\\Stream, 0)
ZEND_END_ARG_INFO()

const zend_function_entry king_pipeline_orchestrator_class_methods[] = {
    ZEND_ME_MAPPING(run, king_pipeline_orchestrator_run, arginfo_king_pipeline_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(runAsync, king_pipeline_orchestrator_run_async, arginfo_king_pipeline_run_async, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(dispatch, king_pipeline_orchestrator_dispatch, arginfo_king_pipeline_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(dispatchAsync, king_pipeline_orchestrator_dispatch_async, arginfo_king_pipeline_run_async, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(registerTool, king_pipeline_orchestrator_register_tool, arginfo_king_pipeline_register_tool, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(registerHandler, king_pipeline_orchestrator_register_handler, arginfo_king_pipeline_register_handler, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(configureLogging, king_pipeline_orchestrator_configure_logging, arginfo_king_config_array, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(workerRunNext, king_pipeline_orchestrator_worker_run_next, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(resumeRun, king_pipeline_orchestrator_resume_run, arginfo_king_pipeline_get_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getRun, king_pipeline_orchestrator_get_run, arginfo_king_pipeline_get_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(cancelRun, king_pipeline_orchestrator_cancel_run, arginfo_king_pipeline_get_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_FE_END
};
