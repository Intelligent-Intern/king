/*
 * Class and object-handler registration for the King extension. The ordering
 * mirrors the historical MINIT sequence; this file only keeps that sequence
 * out of the lifecycle hook body.
 */

static void king_register_exception_classes(void)
{
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
}

static void king_register_userland_classes(void)
{
    king_ce_cancel_token = king_register_class_with_flags(
        "King\\CancelToken",
        NULL,
        king_cancel_token_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_awaitable = king_register_class_with_flags(
        "King\\Awaitable",
        NULL,
        king_awaitable_class_methods,
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
    king_ce_mcp_server = king_register_class_with_flags(
        "King\\MCPServer",
        NULL,
        king_mcp_server_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_pipeline_orchestrator = king_register_class_with_flags(
        "King\\PipelineOrchestrator",
        NULL,
        king_pipeline_orchestrator_class_methods,
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
    king_ce_rtp_socket = king_register_class_with_flags(
        "King\\RTP\\Socket",
        NULL,
        king_rtp_socket_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_xslt_processor = king_register_class_with_flags(
        "King\\XSLT\\Processor",
        NULL,
        king_xslt_processor_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_inference = king_register_class_with_flags(
        "King\\Inference",
        NULL,
        king_inference_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_inference_model = king_register_class_with_flags(
        "King\\Inference\\Model",
        NULL,
        king_inference_model_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_inference_stream = king_register_class_with_flags(
        "King\\Inference\\Stream",
        NULL,
        king_inference_stream_class_methods,
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
}

static void king_init_object_handlers(void)
{
    king_cancel_token_object_handlers_init();
    king_awaitable_object_handlers_init();
    king_config_object_handlers_init();
    king_mcp_object_handlers_init();
    king_mcp_server_object_handlers_init();
    king_rtp_socket_object_handlers_init();
    king_xslt_processor_object_handlers_init();
    king_inference_model_object_handlers_init();
    king_inference_stream_object_handlers_init();
    king_stream_object_handlers_init();
    king_response_object_handlers_init();
    king_http_client_object_handlers_init();
    king_ws_object_handlers_init();
    king_ws_server_object_handlers_init();
    king_session_object_handlers_init();
}

static void king_register_php_classes_and_handlers(void)
{
    king_register_exception_classes();
    king_register_userland_classes();
    king_init_object_handlers();
}
