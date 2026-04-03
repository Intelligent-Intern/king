static zend_result king_websocket_validate_header_pair(
    zend_string *header_name,
    zend_string *header_value,
    const char *function_name
)
{
    if (header_name == NULL || ZSTR_LEN(header_name) == 0) {
        king_websocket_set_error(
            "%s() handshake header names must be non-empty strings.",
            function_name
        );
        return FAILURE;
    }

    if (
        king_websocket_string_has_crlf(
            ZSTR_VAL(header_name),
            ZSTR_LEN(header_name)
        )
        || king_websocket_string_has_crlf(
            ZSTR_VAL(header_value),
            ZSTR_LEN(header_value)
        )
    ) {
        king_websocket_set_error(
            "%s() handshake headers must not contain CRLF bytes.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_websocket_append_handshake_headers(
    smart_str *request,
    king_ws_state *state,
    const char *function_name
)
{
    zend_string *header_name;
    zval *header_value;

    if (Z_ISUNDEF(state->headers) || Z_TYPE(state->headers) != IS_ARRAY) {
        return SUCCESS;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(state->headers), header_name, header_value)
    {
        if (header_name == NULL) {
            continue;
        }

        if (Z_TYPE_P(header_value) == IS_ARRAY) {
            zval *entry;

            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(header_value), entry)
            {
                zend_string *value = zval_get_string(entry);

                if (
                    king_websocket_validate_header_pair(
                        header_name,
                        value,
                        function_name
                    ) != SUCCESS
                ) {
                    zend_string_release(value);
                    return FAILURE;
                }

                smart_str_append(request, header_name);
                smart_str_appends(request, ": ");
                smart_str_append(request, value);
                smart_str_appends(request, "\r\n");
                zend_string_release(value);
            }
            ZEND_HASH_FOREACH_END();
            continue;
        }

        zend_string *value = zval_get_string(header_value);

        if (
            king_websocket_validate_header_pair(
                header_name,
                value,
                function_name
            ) != SUCCESS
        ) {
            zend_string_release(value);
            return FAILURE;
        }

        smart_str_append(request, header_name);
        smart_str_appends(request, ": ");
        smart_str_append(request, value);
        smart_str_appends(request, "\r\n");
        zend_string_release(value);
    }
    ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_websocket_complete_handshake(
    king_ws_state *state,
    const char *function_name
)
{
    struct timeval timeout;
    zend_string *transport_target = NULL;
    zend_string *authority = NULL;
    zend_string *client_key = NULL;
    zend_string *expected_accept = NULL;
    zend_string *transport_error = NULL;
    php_stream *stream = NULL;
    smart_str request = {0};
    char line[KING_WS_HTTP_LINE_MAX];
    size_t line_len = 0;
    int transport_error_code = 0;
    const char *accept_value = NULL;

    transport_target = king_websocket_build_transport_target(state);
    authority = king_websocket_build_authority(state);
    client_key = king_websocket_generate_client_key(state);
    expected_accept = king_websocket_compute_expected_accept(client_key);

    timeout.tv_sec = state->handshake_timeout_ms / 1000;
    timeout.tv_usec = (state->handshake_timeout_ms % 1000) * 1000;

    stream = php_stream_xport_create(
        ZSTR_VAL(transport_target),
        ZSTR_LEN(transport_target),
        0,
        STREAM_XPORT_CLIENT | STREAM_XPORT_CONNECT,
        NULL,
        &timeout,
        NULL,
        &transport_error,
        &transport_error_code
    );
    if (stream == NULL) {
        if (transport_error != NULL) {
            king_websocket_set_error(
                "%s() failed to connect the WebSocket socket: %s",
                function_name,
                ZSTR_VAL(transport_error)
            );
        } else {
            king_websocket_set_error(
                "%s() failed to connect the WebSocket socket (code %d).",
                function_name,
                transport_error_code
            );
        }
        if (transport_error != NULL) {
            zend_string_release(transport_error);
        }
        zend_string_release(expected_accept);
        zend_string_release(client_key);
        zend_string_release(authority);
        zend_string_release(transport_target);
        return FAILURE;
    }

    state->transport_stream = stream;
    php_stream_set_option(stream, PHP_STREAM_OPTION_BLOCKING, 1, NULL);
    php_stream_set_option(stream, PHP_STREAM_OPTION_READ_TIMEOUT, 0, &timeout);

    smart_str_appends(&request, "GET ");
    smart_str_append(&request, state->request_target);
    smart_str_appends(&request, " HTTP/1.1\r\nHost: ");
    smart_str_append(&request, authority);
    smart_str_appends(&request, "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ");
    smart_str_append(&request, client_key);
    smart_str_appends(&request, "\r\nSec-WebSocket-Version: 13\r\n");

    if (
        king_websocket_append_handshake_headers(
            &request,
            state,
            function_name
        ) != SUCCESS
    ) {
        smart_str_free(&request);
        king_websocket_mark_transport_closed(state);
        zend_string_release(expected_accept);
        zend_string_release(client_key);
        zend_string_release(authority);
        zend_string_release(transport_target);
        return FAILURE;
    }

    smart_str_appends(&request, "\r\n");
    smart_str_0(&request);

    if (
        king_websocket_write_all(
            state,
            (const unsigned char *) ZSTR_VAL(request.s),
            ZSTR_LEN(request.s),
            function_name
        ) != SUCCESS
    ) {
        smart_str_free(&request);
        king_websocket_mark_transport_closed(state);
        zend_string_release(expected_accept);
        zend_string_release(client_key);
        zend_string_release(authority);
        zend_string_release(transport_target);
        return FAILURE;
    }

    if (
        php_stream_get_line(
            state->transport_stream,
            line,
            sizeof(line),
            &line_len
        ) == NULL
    ) {
        smart_str_free(&request);
        king_websocket_mark_transport_closed(state);
        king_websocket_set_error(
            "%s() did not receive a WebSocket handshake status line from the peer.",
            function_name
        );
        zend_string_release(expected_accept);
        zend_string_release(client_key);
        zend_string_release(authority);
        zend_string_release(transport_target);
        return FAILURE;
    }

    king_websocket_trim_line(line, &line_len);
    if (strncmp(line, "HTTP/1.1 101", 12) != 0 && strncmp(line, "HTTP/1.0 101", 12) != 0) {
        smart_str_free(&request);
        king_websocket_mark_transport_closed(state);
        king_websocket_set_error(
            "%s() expected an HTTP 101 WebSocket upgrade response but received '%s'.",
            function_name,
            line
        );
        zend_string_release(expected_accept);
        zend_string_release(client_key);
        zend_string_release(authority);
        zend_string_release(transport_target);
        return FAILURE;
    }

    while (
        php_stream_get_line(
            state->transport_stream,
            line,
            sizeof(line),
            &line_len
        ) != NULL
    ) {
        char *colon;

        king_websocket_trim_line(line, &line_len);
        if (line_len == 0) {
            break;
        }

        colon = strchr(line, ':');
        if (colon == NULL) {
            continue;
        }

        *colon = '\0';
        if (strcasecmp(line, "Sec-WebSocket-Accept") == 0) {
            accept_value = king_websocket_skip_ascii_space(colon + 1);
        }
    }

    smart_str_free(&request);

    if (accept_value == NULL || strcmp(accept_value, ZSTR_VAL(expected_accept)) != 0) {
        king_websocket_mark_transport_closed(state);
        king_websocket_set_error(
            "%s() received an invalid Sec-WebSocket-Accept handshake header.",
            function_name
        );
        zend_string_release(expected_accept);
        zend_string_release(client_key);
        zend_string_release(authority);
        zend_string_release(transport_target);
        return FAILURE;
    }

    state->state = KING_WS_STATE_OPEN;
    state->handshake_complete = true;
    state->closed = false;
    state->close_frame_sent = false;
    king_set_error("");

    zend_string_release(expected_accept);
    zend_string_release(client_key);
    zend_string_release(authority);
    zend_string_release(transport_target);
    return SUCCESS;
}

