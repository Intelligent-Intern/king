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

PHP_FUNCTION(king_client_websocket_connect_async)
{
    char *url_str = NULL;
    size_t url_len = 0;
    zval *headers_array = NULL;
    zval *options_array = NULL;
    zval params[3];
    uint32_t index;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STRING(url_str, url_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(headers_array)
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_STRINGL(&params[0], url_str, url_len);
    if (headers_array != NULL) {
        ZVAL_COPY(&params[1], headers_array);
    } else {
        ZVAL_NULL(&params[1]);
    }
    if (options_array != NULL) {
        ZVAL_COPY(&params[2], options_array);
    } else {
        ZVAL_NULL(&params[2]);
    }

    if (king_awaitable_create_function_call(
            return_value,
            "king_client_websocket_connect_async",
            sizeof("king_client_websocket_connect_async") - 1,
            "king_client_websocket_connect",
            sizeof("king_client_websocket_connect") - 1,
            params,
            3,
            NULL
        ) != SUCCESS) {
        for (index = 0; index < 3; index++) {
            zval_ptr_dtor(&params[index]);
        }
        RETURN_FALSE;
    }

    for (index = 0; index < 3; index++) {
        zval_ptr_dtor(&params[index]);
    }
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
            "king_client_websocket_send() payload size %zu exceeds max_payload_size " ZEND_LONG_FMT ".",
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

PHP_FUNCTION(king_client_websocket_send_async)
{
    zval *websocket;
    char *data = NULL;
    size_t data_len = 0;
    zend_bool is_binary = false;
    zval params[3];
    uint32_t index;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_STRING(data, data_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(is_binary)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_COPY(&params[0], websocket);
    ZVAL_STRINGL(&params[1], data, data_len);
    ZVAL_BOOL(&params[2], is_binary);

    if (king_awaitable_create_function_call(
            return_value,
            "king_client_websocket_send_async",
            sizeof("king_client_websocket_send_async") - 1,
            "king_client_websocket_send",
            sizeof("king_client_websocket_send") - 1,
            params,
            3,
            NULL
        ) != SUCCESS) {
        for (index = 0; index < 3; index++) {
            zval_ptr_dtor(&params[index]);
        }
        RETURN_FALSE;
    }

    for (index = 0; index < 3; index++) {
        zval_ptr_dtor(&params[index]);
    }
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
            "king_websocket_send() payload size %zu exceeds max_payload_size " ZEND_LONG_FMT ".",
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

PHP_FUNCTION(king_client_websocket_receive_async)
{
    zval *websocket;
    zend_long timeout_ms = -1;
    zval params[2];
    uint32_t index;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_COPY(&params[0], websocket);
    ZVAL_LONG(&params[1], timeout_ms);

    if (king_awaitable_create_function_call(
            return_value,
            "king_client_websocket_receive_async",
            sizeof("king_client_websocket_receive_async") - 1,
            "king_client_websocket_receive",
            sizeof("king_client_websocket_receive") - 1,
            params,
            2,
            NULL
        ) != SUCCESS) {
        for (index = 0; index < 2; index++) {
            zval_ptr_dtor(&params[index]);
        }
        RETURN_FALSE;
    }

    for (index = 0; index < 2; index++) {
        zval_ptr_dtor(&params[index]);
    }
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
    zend_string *reason_string = NULL;

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

    if (reason_len > 0) {
        reason_string = zend_string_init(reason, reason_len, 0);
    }

    if (
        king_websocket_state_close(
            state,
            status_code,
            reason_string,
            "king_client_websocket_close"
        ) != SUCCESS
    ) {
        if (reason_string != NULL) {
            zend_string_release(reason_string);
        }
        RETURN_FALSE;
    }

    if (reason_string != NULL) {
        zend_string_release(reason_string);
    }

    king_set_error("");
    RETURN_TRUE;
}
