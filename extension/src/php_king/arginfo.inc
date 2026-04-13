/*
 * Shared arginfo block for the King extension. Centralizes the procedural and
 * OO signature metadata consumed by the module function table and class
 * registrations.
 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_no_args, 0, 0, 0)
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

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_semantic_dns_query, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, query, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, max_response_bytes, IS_LONG, 0, "256")
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

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_object_lookup, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, object_id, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_object_store_put, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, object_id, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_object_store_put_from_stream, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, object_id, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stream, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_object_store_get_to_stream, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, object_id, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stream, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_object_store_begin_resumable_upload, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, object_id, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_object_store_append_resumable_upload_chunk, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, upload_id, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, stream, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_object_id, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, object_id, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_object_store_backup, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, object_id, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, destination_path, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_object_store_restore, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, object_id, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, source_path, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_object_store_all_objects_path, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_object_store_backup_all_objects, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_optional_object_id, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, object_id, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_encode, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_decode, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, binary_data, IS_STRING, 0)
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

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_http2_request_send_multi, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, requests, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_http3_request_send_multi, 0, 0, 1)
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

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_websocket_send, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, websocket, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, is_binary, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_websocket_receive, 0, 0, 1)
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

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_telemetry_start_span, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, operation_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, attributes, IS_ARRAY, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, parent_span_id, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_telemetry_end_span, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, span_id, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, final_attributes, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_telemetry_record_metric, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, metric_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, value, IS_DOUBLE, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, labels, IS_ARRAY, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, metric_type, IS_STRING, 0, "\"counter\"")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_telemetry_log, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, level, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, attributes, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_optional_instances, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, instances, IS_LONG, 0, "1")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_autoscaling_register_node, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, server_id, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, name, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_system_process_request, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, request_data, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

const zend_function_entry king_object_store_class_methods[] = {
    ZEND_ME_MAPPING(init, king_object_store_init, arginfo_king_config_array, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(put, king_object_store_put, arginfo_king_object_store_put, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(putFromStream, king_object_store_put_from_stream, arginfo_king_object_store_put_from_stream, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(beginResumableUpload, king_object_store_begin_resumable_upload, arginfo_king_object_store_begin_resumable_upload, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(appendResumableUploadChunk, king_object_store_append_resumable_upload_chunk, arginfo_king_object_store_append_resumable_upload_chunk, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(completeResumableUpload, king_object_store_complete_resumable_upload, arginfo_king_object_id, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(abortResumableUpload, king_object_store_abort_resumable_upload, arginfo_king_object_id, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getResumableUploadStatus, king_object_store_get_resumable_upload_status, arginfo_king_object_id, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(get, king_object_store_get, arginfo_king_object_lookup, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getToStream, king_object_store_get_to_stream, arginfo_king_object_store_get_to_stream, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(delete, king_object_store_delete, arginfo_king_object_id, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(backupObject, king_object_store_backup_object, arginfo_king_object_store_backup, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(restoreObject, king_object_store_restore_object, arginfo_king_object_store_restore, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(backupAllObjects, king_object_store_backup_all_objects, arginfo_king_object_store_backup_all_objects, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(restoreAllObjects, king_object_store_restore_all_objects, arginfo_king_object_store_all_objects_path, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(listObjects, king_object_store_list, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getStats, king_object_store_get_stats, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(optimize, king_object_store_optimize, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(cleanupExpiredObjects, king_object_store_cleanup_expired_objects, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getMetadata, king_object_store_get_metadata, arginfo_king_object_id, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_FE_END
};

const zend_function_entry king_autoscaling_class_methods[] = {
    ZEND_ME_MAPPING(init, king_autoscaling_init, arginfo_king_config_array, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(startMonitoring, king_autoscaling_start_monitoring, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(stopMonitoring, king_autoscaling_stop_monitoring, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getMetrics, king_autoscaling_get_metrics, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getStatus, king_autoscaling_get_status, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getNodes, king_autoscaling_get_nodes, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(scaleUp, king_autoscaling_scale_up, arginfo_king_optional_instances, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(scaleDown, king_autoscaling_scale_down, arginfo_king_optional_instances, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(registerNode, king_autoscaling_register_node, arginfo_king_autoscaling_register_node, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(markNodeReady, king_autoscaling_mark_node_ready, arginfo_king_one_long, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(drainNode, king_autoscaling_drain_node, arginfo_king_one_long, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_FE_END
};

/* ── RTP / SFU ────────────────────────────────────────────────────────── */

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_rtp_bind, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG,   0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_rtp_socket, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, socket, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_rtp_recv, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, socket, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_rtp_send, 0, 0, 4)
    ZEND_ARG_TYPE_INFO(0, socket, IS_MIXED,  0)
    ZEND_ARG_TYPE_INFO(0, host,   IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port,   IS_LONG,   0)
    ZEND_ARG_TYPE_INFO(0, data,   IS_STRING, 0)
ZEND_END_ARG_INFO()
