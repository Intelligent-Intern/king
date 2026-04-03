static zend_result king_websocket_object_send_internal(
    zval *object,
    zend_string *payload,
    bool is_binary,
    const char *function_name
)
{
    king_ws_state *state = king_websocket_object_fetch_state(object, function_name);

    if (state == NULL) {
        return FAILURE;
    }

    if (king_websocket_require_open(state, function_name) != SUCCESS) {
        king_websocket_throw_last_error(
            king_ce_ws_closed,
            "WebSocket connection is closed."
        );
        return FAILURE;
    }

    if (ZSTR_LEN(payload) > (size_t) state->max_payload_size) {
        king_websocket_set_error(
            "%s() payload size %zu exceeds max_payload_size %ld.",
            function_name,
            ZSTR_LEN(payload),
            state->max_payload_size
        );
        king_websocket_throw_last_error(
            king_ce_ws_protocol_error,
            "WebSocket frame exceeds the configured max payload size."
        );
        return FAILURE;
    }

    if (
        king_websocket_send_frame(
            state,
            is_binary ? KING_WS_OPCODE_BINARY : KING_WS_OPCODE_TEXT,
            (const unsigned char *) ZSTR_VAL(payload),
            ZSTR_LEN(payload),
            function_name
        ) != SUCCESS
    ) {
        king_websocket_throw_last_error(
            king_ce_system_exception,
            "Failed to send the active WebSocket frame."
        );
        return FAILURE;
    }

    king_set_error("");
    return SUCCESS;
}

PHP_METHOD(King_WebSocket_Connection, __construct)
{
    char *url_str = NULL;
    size_t url_len = 0;
    zval *headers_array = NULL;
    zval *options_array = NULL;
    king_ws_state *state;
    king_ws_object *intern;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STRING(url_str, url_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(headers_array)
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_state_create(
        url_str,
        url_len,
        headers_array,
        options_array,
        "WebSocket\\Connection::__construct"
    );
    if (state == NULL) {
        king_websocket_throw_last_error(
            king_ce_validation_exception,
            "King\\WebSocket\\Connection construction failed."
        );
        RETURN_THROWS();
    }

    intern = php_king_ws_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
    }
    ZVAL_RES(&intern->resource, zend_register_resource(state, le_king_ws));
    king_set_error("");
}

PHP_METHOD(King_WebSocket_Connection, send)
{
    zend_string *message;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(message)
    ZEND_PARSE_PARAMETERS_END();

    if (
        king_websocket_object_send_internal(
            ZEND_THIS,
            message,
            false,
            "WebSocket\\Connection::send"
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_WebSocket_Connection, sendBinary)
{
    zend_string *payload;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(payload)
    ZEND_PARSE_PARAMETERS_END();

    if (
        king_websocket_object_send_internal(
            ZEND_THIS,
            payload,
            true,
            "WebSocket\\Connection::sendBinary"
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_WebSocket_Connection, ping)
{
    zend_string *payload_str = NULL;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR_OR_NULL(payload_str)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_object_fetch_state(ZEND_THIS, "WebSocket\\Connection::ping");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (king_websocket_require_open(state, "WebSocket\\Connection::ping") != SUCCESS) {
        king_websocket_throw_last_error(
            king_ce_ws_closed,
            "WebSocket connection is closed."
        );
        RETURN_THROWS();
    }

    if (payload_str != NULL && ZSTR_LEN(payload_str) > KING_WS_PING_MAX_PAYLOAD_LEN) {
        king_websocket_set_error(
            "WebSocket\\Connection::ping() ping payload cannot exceed %d bytes.",
            KING_WS_PING_MAX_PAYLOAD_LEN
        );
        king_websocket_throw_last_error(
            king_ce_ws_protocol_error,
            "WebSocket ping payload exceeds the protocol limit."
        );
        RETURN_THROWS();
    }

    if (king_websocket_uses_local_server_runtime(state)) {
        king_websocket_reject_local_server_frame_io(
            "WebSocket\\Connection::ping"
        );
        king_websocket_throw_last_error(
            king_ce_ws_connection_error,
            "Failed to send the active WebSocket ping frame."
        );
        RETURN_THROWS();
    }

    if (state->last_ping_payload != NULL) {
        zend_string_release(state->last_ping_payload);
    }
    state->last_ping_payload = payload_str != NULL
        ? zend_string_copy(payload_str)
        : zend_string_init("", 0, 0);

    if (
        state->transport_stream != NULL
        && king_websocket_send_frame(
            state,
            KING_WS_OPCODE_PING,
            payload_str != NULL
                ? (const unsigned char *) ZSTR_VAL(payload_str)
                : (const unsigned char *) "",
            payload_str != NULL ? ZSTR_LEN(payload_str) : 0,
            "WebSocket\\Connection::ping"
        ) != SUCCESS
    ) {
        king_websocket_throw_last_error(
            king_ce_ws_connection_error,
            "Failed to send the active WebSocket ping frame."
        );
        RETURN_THROWS();
    }

    king_set_error("");
}

PHP_METHOD(King_WebSocket_Connection, close)
{
    zend_long status_code = 1000;
    zend_string *reason = NULL;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(0, 2)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(status_code)
        Z_PARAM_STR_OR_NULL(reason)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_object_fetch_state(ZEND_THIS, "WebSocket\\Connection::close");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->state == KING_WS_STATE_CLOSED || state->closed) {
        king_set_error("");
        return;
    }

    if (
        king_websocket_validate_close_inputs(
            status_code,
            reason != NULL ? ZSTR_LEN(reason) : 0,
            "WebSocket\\Connection::close"
        ) != SUCCESS
    ) {
        king_websocket_throw_last_error(
            king_ce_validation_exception,
            "WebSocket close input validation failed."
        );
        RETURN_THROWS();
    }

    if (state->last_close_reason != NULL) {
        zend_string_release(state->last_close_reason);
    }
    state->last_close_reason = reason != NULL
        ? zend_string_copy(reason)
        : zend_string_init("", 0, 0);
    state->last_close_status_code = status_code;

    if (state->transport_stream != NULL) {
        unsigned char close_payload[2 + KING_WS_CLOSE_REASON_MAX_LEN];
        size_t close_payload_len = 2 + (reason != NULL ? ZSTR_LEN(reason) : 0);

        close_payload[0] = (unsigned char) ((status_code >> 8) & 0xff);
        close_payload[1] = (unsigned char) (status_code & 0xff);
        if (reason != NULL && ZSTR_LEN(reason) > 0) {
            memcpy(close_payload + 2, ZSTR_VAL(reason), ZSTR_LEN(reason));
        }

        if (
            king_websocket_send_frame(
                state,
                KING_WS_OPCODE_CLOSE,
                close_payload,
                close_payload_len,
                "WebSocket\\Connection::close"
        ) != SUCCESS
        ) {
            king_websocket_throw_last_error(
                king_ce_ws_connection_error,
                "Failed to send the active WebSocket close frame."
            );
            RETURN_THROWS();
        }

        state->close_frame_sent = true;
        king_websocket_drain_transport(state, 100, "WebSocket\\Connection::close");
        if (!state->closed) {
            king_websocket_mark_transport_closed(state);
        }
    } else {
        state->state = KING_WS_STATE_CLOSED;
        state->closed = true;
    }

    king_set_error("");
}

PHP_METHOD(King_WebSocket_Connection, getInfo)
{
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_NONE();

    state = king_websocket_object_fetch_state(ZEND_THIS, "WebSocket\\Connection::getInfo");
    if (state == NULL) {
        RETURN_THROWS();
    }

    king_websocket_build_info_array(return_value, state);
    king_set_error("");
}

PHP_FUNCTION(king_client_websocket_connect)
{
    char *url_str = NULL;
    size_t url_len = 0;
    zval *headers_array = NULL;
    zval *options_array = NULL;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STRING(url_str, url_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(headers_array)
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_state_create(
        url_str,
        url_len,
        headers_array,
        options_array,
        "king_client_websocket_connect"
    );
    if (state == NULL) {
        RETURN_FALSE;
    }

    RETURN_RES(zend_register_resource(state, le_king_ws));
}

PHP_FUNCTION(king_client_websocket_send)
{
    zval *websocket;
    char *data = NULL;
    size_t data_len = 0;
    zend_bool is_binary = false;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_STRING(data, data_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(is_binary)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (
        king_websocket_require_open(
            state,
            "king_client_websocket_send"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (data_len > (size_t) state->max_payload_size) {
        king_websocket_set_error(
            "king_client_websocket_send() payload size %zu exceeds max_payload_size %ld.",
            data_len,
            state->max_payload_size
        );
        RETURN_FALSE;
    }

    if (
        king_websocket_send_frame(
            state,
            is_binary ? KING_WS_OPCODE_BINARY : KING_WS_OPCODE_TEXT,
            (const unsigned char *) data,
            data_len,
            "king_client_websocket_send"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_websocket_send)
{
    zval *websocket;
    char *message = NULL;
    size_t message_len = 0;
    zend_bool is_binary = false;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_STRING(message, message_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(is_binary)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (
        king_websocket_require_open(
            state,
            "king_websocket_send"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (message_len > (size_t) state->max_payload_size) {
        king_websocket_set_error(
            "king_websocket_send() payload size %zu exceeds max_payload_size %ld.",
            message_len,
            state->max_payload_size
        );
        RETURN_FALSE;
    }

    if (
        king_websocket_send_frame(
            state,
            is_binary ? KING_WS_OPCODE_BINARY : KING_WS_OPCODE_TEXT,
            (const unsigned char *) message,
            message_len,
            "king_websocket_send"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_client_websocket_receive)
{
    zval *websocket;
    zend_long timeout_ms = -1;
    king_ws_state *state;
    king_ws_message *message;
    zend_string *payload;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (timeout_ms < -1) {
        king_websocket_set_error(
            "king_client_websocket_receive() timeout_ms must be -1 or >= 0."
        );
        RETURN_FALSE;
    }

    message = king_websocket_message_queue_shift(state);
    if (message != NULL) {
        payload = message->payload;
        efree(message);
        king_set_error("");
        RETURN_STR(payload);
    }

    if (king_websocket_uses_local_server_runtime(state)) {
        king_websocket_reject_local_server_frame_io(
            "king_client_websocket_receive"
        );
        RETURN_FALSE;
    }

    if (state->transport_stream != NULL && !state->closed) {
        if (
            king_websocket_pump_transport(
                state,
                timeout_ms,
                "king_client_websocket_receive"
            ) != SUCCESS
        ) {
            RETURN_FALSE;
        }

        message = king_websocket_message_queue_shift(state);
        if (message != NULL) {
            payload = message->payload;
            efree(message);
            king_set_error("");
            RETURN_STR(payload);
        }
    }

    if (
        (state->state == KING_WS_STATE_OPEN || state->state == KING_WS_STATE_CONNECTING)
        && !state->closed
    ) {
        king_set_error("");
        RETURN_EMPTY_STRING();
    }

    king_websocket_set_error(
        "king_client_websocket_receive() cannot run on a closed WebSocket connection."
    );
    RETURN_FALSE;
}

PHP_FUNCTION(king_client_websocket_ping)
{
    zval *websocket;
    char *payload = "";
    size_t payload_len = 0;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING(payload, payload_len)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (
        king_websocket_require_open(
            state,
            "king_client_websocket_ping"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (payload_len > KING_WS_PING_MAX_PAYLOAD_LEN) {
        king_websocket_set_error(
            "king_client_websocket_ping() ping payload cannot exceed %d bytes.",
            KING_WS_PING_MAX_PAYLOAD_LEN
        );
        RETURN_FALSE;
    }

    if (king_websocket_uses_local_server_runtime(state)) {
        king_websocket_reject_local_server_frame_io(
            "king_client_websocket_ping"
        );
        RETURN_FALSE;
    }

    if (state->last_ping_payload != NULL) {
        zend_string_release(state->last_ping_payload);
    }
    state->last_ping_payload = zend_string_init(payload, payload_len, 0);

    if (
        state->transport_stream != NULL
        && king_websocket_send_frame(
            state,
            KING_WS_OPCODE_PING,
            (const unsigned char *) payload,
            payload_len,
            "king_client_websocket_ping"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_client_websocket_get_status)
{
    zval *websocket;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(websocket)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    king_set_error("");
    RETURN_LONG((zend_long) state->state);
}

PHP_FUNCTION(king_client_websocket_close)
{
    zval *websocket;
    zend_long status_code = 1000;
    char *reason = "";
    size_t reason_len = 0;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(status_code)
        Z_PARAM_STRING(reason, reason_len)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->state == KING_WS_STATE_CLOSED || state->closed) {
        king_set_error("");
        RETURN_TRUE;
    }

    if (
        king_websocket_validate_close_inputs(
            status_code,
            reason_len,
            "king_client_websocket_close"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (state->last_close_reason != NULL) {
        zend_string_release(state->last_close_reason);
    }
    state->last_close_reason = zend_string_init(reason, reason_len, 0);
    state->last_close_status_code = status_code;

    if (state->transport_stream != NULL) {
        unsigned char close_payload[2 + KING_WS_CLOSE_REASON_MAX_LEN];

        close_payload[0] = (unsigned char) ((status_code >> 8) & 0xff);
        close_payload[1] = (unsigned char) (status_code & 0xff);
        if (reason_len > 0) {
            memcpy(close_payload + 2, reason, reason_len);
        }

        if (
            king_websocket_send_frame(
                state,
                KING_WS_OPCODE_CLOSE,
                close_payload,
                reason_len + 2,
                "king_client_websocket_close"
            ) != SUCCESS
        ) {
            RETURN_FALSE;
        }

        state->close_frame_sent = true;
        king_websocket_drain_transport(state, 100, "king_client_websocket_close");
        if (!state->closed) {
            king_websocket_mark_transport_closed(state);
        }
    } else {
        state->state = KING_WS_STATE_CLOSED;
        state->closed = true;
    }

    king_set_error("");
    RETURN_TRUE;
}

const zend_function_entry king_ws_connection_class_methods[] = {
    PHP_ME(King_WebSocket_Connection, __construct, arginfo_class_King_WebSocket_Connection___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_WebSocket_Connection, send, arginfo_class_King_WebSocket_Connection_send, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, sendBinary, arginfo_class_King_WebSocket_Connection_sendBinary, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, ping, arginfo_class_King_WebSocket_Connection_ping, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, close, arginfo_class_King_WebSocket_Connection_close, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, getInfo, arginfo_class_King_WebSocket_Connection_getInfo, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
