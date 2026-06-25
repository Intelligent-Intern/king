#include "resource_destructors.inc"

void king_client_register_resource_types(int module_number)
{
    le_king_session = zend_register_list_destructors_ex(
        king_client_session_resource_dtor, NULL, "King\\Session", module_number);
    le_king_ws = zend_register_list_destructors_ex(
        king_client_websocket_resource_dtor, NULL, "King\\WebSocket", module_number);
    le_king_request_context = zend_register_list_destructors_ex(
        king_client_http1_request_context_resource_dtor,
        NULL,
        "King\\HttpRequestContext",
        module_number);
}

void king_client_register_websocket_exception_classes(void)
{
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
}

void king_client_register_session_classes(void)
{
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
}

void king_client_register_http_classes(void)
{
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
}

void king_client_register_websocket_classes(void)
{
    king_ce_ws_connection = king_register_class_with_flags(
        "King\\WebSocket\\Connection",
        NULL,
        king_ws_connection_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
}

void king_client_init_object_handlers(void)
{
    king_stream_object_handlers_init();
    king_response_object_handlers_init();
    king_http_client_object_handlers_init();
    king_ws_object_handlers_init();
}

void king_client_init_session_object_handlers(void)
{
    king_session_object_handlers_init();
}
