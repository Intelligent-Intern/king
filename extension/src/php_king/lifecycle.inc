/*
 * Extension lifecycle hooks for php_king. Wires MINIT/MSHUTDOWN/RINIT/
 * RSHUTDOWN/MINFO across the module bootstrap, registry setup and per-request
 * runtime teardown paths.
 */

PHP_MINIT_FUNCTION(king)
{
    if (king_init_modules(type, module_number) != SUCCESS) {
        return FAILURE;
    }

    if (king_proto_registry_minit() != SUCCESS) {
        return FAILURE;
    }
    if (king_semantic_dns_registry_minit() != SUCCESS) {
        king_proto_registry_mshutdown();
        return FAILURE;
    }
    if (king_cdn_cache_registry_minit() != SUCCESS) {
        king_semantic_dns_registry_mshutdown();
        king_proto_registry_mshutdown();
        return FAILURE;
    }

    if (king_iibin_minit() != SUCCESS) {
        king_cdn_cache_registry_mshutdown();
        king_semantic_dns_registry_mshutdown();
        king_proto_registry_mshutdown();
        return FAILURE;
    }

    le_king_session = zend_register_list_destructors_ex(
        king_session_dtor, NULL, "King\\Session", module_number);
    le_king_cfg = zend_register_list_destructors_ex(
        king_cfg_dtor, NULL, "King\\Config", module_number);
    le_king_mcp = zend_register_list_destructors_ex(
        king_mcp_dtor, NULL, "King\\MCP", module_number);
    le_king_ws = zend_register_list_destructors_ex(
        king_ws_dtor, NULL, "King\\WebSocket", module_number);
    le_king_request_context = zend_register_list_destructors_ex(
        king_http1_request_context_dtor, NULL, "King\\HttpRequestContext", module_number);

    king_ce_exception = king_register_exception(
        "King\\Exception", zend_ce_exception);

    king_ce_stream_exception = king_register_exception(
        "King\\StreamException", king_ce_exception);
    king_ce_invalid_state = king_register_exception(
        "King\\InvalidStateException", king_ce_stream_exception);
    king_ce_unknown_stream = king_register_exception(
        "King\\UnknownStreamException", king_ce_stream_exception);
    king_ce_stream_blocked = king_register_exception(
        "King\\StreamBlockedException", king_ce_stream_exception);
    king_ce_stream_limit = king_register_exception(
        "King\\StreamLimitException", king_ce_stream_exception);
    king_ce_final_size = king_register_exception(
        "King\\FinalSizeException", king_ce_stream_exception);
    king_ce_stream_stopped = king_register_exception(
        "King\\StreamStoppedException", king_ce_stream_exception);
    king_ce_fin_expected = king_register_exception(
        "King\\FinExpectedException", king_ce_stream_exception);
    king_ce_invalid_fin_state = king_register_exception(
        "King\\InvalidFinStateException", king_ce_stream_exception);
    king_ce_done = king_register_exception(
        "King\\DoneException", king_ce_stream_exception);
    king_ce_too_many_streams = king_register_exception(
        "King\\TooManyStreamsException", king_ce_stream_exception);

    king_ce_quic_exception = king_register_exception(
        "King\\QuicException", king_ce_exception);
    king_ce_congestion_control = king_register_exception(
        "King\\CongestionControlException", king_ce_quic_exception);

    king_ce_mcp_exception = king_register_exception(
        "King\\MCPException", king_ce_exception);
    king_ce_mcp_connection_error = king_register_exception(
        "King\\MCPConnectionException", king_ce_mcp_exception);
    king_ce_mcp_protocol_error = king_register_exception(
        "King\\MCPProtocolException", king_ce_mcp_exception);
    king_ce_mcp_timeout = king_register_exception(
        "King\\MCPTimeoutException", king_ce_mcp_exception);
    king_ce_mcp_data_error = king_register_exception(
        "King\\MCPDataException", king_ce_mcp_exception);

    king_ce_ws_exception = king_register_exception(
        "King\\WebSocketException", king_ce_exception);
    king_ce_ws_connection_error = king_register_exception(
        "King\\WebSocketConnectionException", king_ce_ws_exception);
    king_ce_ws_protocol_error = king_register_exception(
        "King\\WebSocketProtocolException", king_ce_ws_exception);
    king_ce_ws_timeout = king_register_exception(
        "King\\WebSocketTimeoutException", king_ce_ws_exception);
    king_ce_ws_closed = king_register_exception(
        "King\\WebSocketClosedException", king_ce_ws_exception);

    king_ce_runtime_exception = king_register_exception(
        "King\\RuntimeException", king_ce_exception);
    king_ce_system_exception = king_register_exception(
        "King\\SystemException", king_ce_exception);
    king_ce_validation_exception = king_register_exception(
        "King\\ValidationException", king_ce_exception);
    king_ce_timeout_exception = king_register_exception(
        "King\\TimeoutException", king_ce_exception);
    king_ce_network_exception = king_register_exception(
        "King\\NetworkException", king_ce_exception);
    king_ce_tls_exception = king_register_exception(
        "King\\TlsException", king_ce_exception);
    king_ce_protocol_exception = king_register_exception(
        "King\\ProtocolException", king_ce_exception);

    king_ce_cancel_token = king_register_class_with_flags(
        "King\\CancelToken",
        NULL,
        king_cancel_token_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_config = king_register_class_with_flags(
        "King\\Config",
        NULL,
        king_config_class_methods,
        ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_session = king_register_class_with_flags(
        "King\\Session",
        NULL,
        king_session_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_stream = king_register_class_with_flags(
        "King\\Stream",
        NULL,
        king_stream_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_response = king_register_class_with_flags(
        "King\\Response",
        NULL,
        king_response_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_mcp = king_register_class_with_flags(
        "King\\MCP",
        NULL,
        king_mcp_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_object_store = king_register_class_with_flags(
        "King\\ObjectStore",
        NULL,
        king_object_store_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_autoscaling = king_register_class_with_flags(
        "King\\Autoscaling",
        NULL,
        king_autoscaling_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_client_http = king_register_class_with_flags(
        "King\\Client\\HttpClient",
        NULL,
        king_http_client_class_methods,
        ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_client_http1 = king_register_class_with_flags(
        "King\\Client\\Http1Client",
        king_ce_client_http,
        NULL,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_client_http2 = king_register_class_with_flags(
        "King\\Client\\Http2Client",
        king_ce_client_http,
        NULL,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_client_http3 = king_register_class_with_flags(
        "King\\Client\\Http3Client",
        king_ce_client_http,
        NULL,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_ws_server = king_register_class_with_flags(
        "King\\WebSocket\\Server",
        NULL,
        king_ws_server_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_ws_connection = king_register_class_with_flags(
        "King\\WebSocket\\Connection",
        NULL,
        king_ws_connection_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_cancel_token_object_handlers_init();
    king_config_object_handlers_init();
    king_mcp_object_handlers_init();
    king_stream_object_handlers_init();
    king_response_object_handlers_init();
    king_http_client_object_handlers_init();
    king_ws_object_handlers_init();
    king_ws_server_object_handlers_init();
    king_session_object_handlers_init();

    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(king)
{
    king_http1_pool_module_shutdown();
    king_http2_pool_module_shutdown();
    king_object_store_shutdown_system();
    king_semantic_dns_shutdown_system();
    king_shutdown_modules(type, module_number);
    king_cdn_cache_registry_mshutdown();
    king_semantic_dns_registry_mshutdown();
    king_proto_registry_mshutdown();
    return SUCCESS;
}

PHP_RINIT_FUNCTION(king)
{
    king_last_error[0] = '\0';
    return king_request_init(type, module_number);
}

PHP_RSHUTDOWN_FUNCTION(king)
{
    king_object_store_request_shutdown();
    king_semantic_dns_request_shutdown();
    king_http1_pool_request_shutdown();
    king_http2_pool_request_shutdown();
    return king_request_shutdown(type, module_number);
}

PHP_MINFO_FUNCTION(king)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "King support", "enabled");
    php_info_print_table_row(2, "Version", PHP_KING_VERSION);
    php_info_print_table_row(2, "Build", "v1 (runtime-loaded QUIC)");
#if defined(KING_HTTP3_BACKEND_LSQUIC)
    php_info_print_table_row(2, "QUIC backend", "runtime-loaded LSQUIC");
#else
    php_info_print_table_row(2, "QUIC backend", "runtime-loaded libquiche");
#endif
    php_info_print_table_row(2, "Active runtimes", king_get_active_runtime_summary());
    php_info_print_table_row(2, "Stubbed API groups", king_get_stubbed_api_summary());
    php_info_print_table_end();
    DISPLAY_INI_ENTRIES();
}
