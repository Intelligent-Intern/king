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

    king_mcp_register_exception_classes();
    king_client_register_websocket_exception_classes();

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
    king_awaitable_register_classes();
    king_config_register_classes();
    king_client_register_session_classes();
    king_mcp_register_classes();
    king_pipeline_orchestrator_register_classes();
    king_object_store_register_classes();
    king_autoscaling_register_classes();
    king_media_register_classes();
    king_xslt_register_classes();
    king_inference_register_classes();
    king_client_register_http_classes();
    king_server_register_websocket_classes();
    king_client_register_websocket_classes();
}

static void king_init_object_handlers(void)
{
    king_awaitable_init_object_handlers();
    king_config_init_object_handlers();
    king_mcp_init_object_handlers();
    king_media_init_object_handlers();
    king_xslt_init_object_handlers();
    king_inference_init_object_handlers();
    king_client_init_object_handlers();
    king_server_init_object_handlers();
    king_client_init_session_object_handlers();
}

void king_register_php_classes_and_handlers(void)
{
    king_register_exception_classes();
    king_register_userland_classes();
    king_init_object_handlers();
}
