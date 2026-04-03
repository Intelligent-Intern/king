static zend_result king_server_http2_build_wire_request(
    zval *request,
    king_server_http2_request_state *state,
    king_client_session_t *session,
    const char *host,
    size_t host_len,
    zend_long port,
    const char *function_name
)
{
    zend_string *authority;
    const char *scheme = "http";

    if (state->method == NULL || state->path == NULL) {
        king_server_local_set_errorf(
            "%s() requires ':method' and ':path' pseudo headers on the on-wire HTTP/2 leaf.",
            function_name
        );
        return FAILURE;
    }

    authority = state->authority != NULL
        ? zend_string_copy(state->authority)
        : king_server_http2_build_authority(host, host_len, port);
    if (state->scheme != NULL && ZSTR_LEN(state->scheme) > 0) {
        scheme = ZSTR_VAL(state->scheme);
    }

    array_init(request);
    add_assoc_str(request, "method", zend_string_copy(state->method));
    add_assoc_str(request, "uri", zend_string_copy(state->path));
    add_assoc_string(request, "version", "HTTP/2");
    add_assoc_str(request, "host", zend_string_copy(authority));
    add_assoc_zval(request, "headers", &state->headers);
    state->headers_initialized = 0;
    if (state->body.s != NULL) {
        smart_str_0(&state->body);
        add_assoc_str(request, "body", state->body.s);
        state->body.s = NULL;
    } else {
        add_assoc_string(request, "body", "");
    }

    if (session->server_pending_request_target != NULL) {
        zend_string_release(session->server_pending_request_target);
    }
    session->server_pending_request_target = zend_string_copy(state->path);

    if (session->server_pending_websocket_key != NULL) {
        zend_string_release(session->server_pending_websocket_key);
        session->server_pending_websocket_key = NULL;
    }
    session->server_pending_websocket_upgrade = false;

    king_server_local_add_common_request_fields(
        request,
        session,
        "http/2",
        scheme,
        (zend_long) state->stream_id
    );

    zend_string_release(authority);
    return SUCCESS;
}

static zend_result king_server_http2_send_response(
    king_client_session_t *session,
    zval *retval,
    uint32_t stream_id,
    const char *function_name
)
{
    zval *status_zv;
    zval *headers_zv;
    zval *body_zv;
    zend_long status = 200;
    zend_string *status_string = NULL;
    zend_string *body = NULL;
    smart_str header_block = {0};
    zend_result rc;

    if (session->transport_socket_fd < 0) {
        return SUCCESS;
    }

    status_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "status", sizeof("status") - 1);
    headers_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "headers", sizeof("headers") - 1);
    body_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "body", sizeof("body") - 1);

    if (status_zv != NULL && Z_TYPE_P(status_zv) != IS_NULL) {
        status = zval_get_long(status_zv);
    }

    if (body_zv != NULL && Z_TYPE_P(body_zv) != IS_NULL) {
        body = zval_get_string(body_zv);
    } else {
        body = zend_string_init("", 0, 0);
    }

    status_string = strpprintf(0, "%ld", status);
    king_server_http2_hpack_encode_literal_static_name(
        &header_block,
        8,
        ZSTR_VAL(status_string),
        ZSTR_LEN(status_string)
    );

    if (headers_zv != NULL && Z_TYPE_P(headers_zv) == IS_ARRAY) {
        zend_string *header_name;
        zval *header_value;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers_zv), header_name, header_value)
        {
            if (header_name == NULL) {
                continue;
            }

            if (
                zend_string_equals_literal_ci(header_name, "content-length")
                || zend_string_equals_literal_ci(header_name, "connection")
            ) {
                continue;
            }

            if (Z_TYPE_P(header_value) == IS_ARRAY) {
                zval *entry;

                ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(header_value), entry)
                {
                    zend_string *value = zval_get_string(entry);

                    king_server_http2_hpack_append_header_value(
                        &header_block,
                        header_name,
                        value
                    );
                    zend_string_release(value);
                }
                ZEND_HASH_FOREACH_END();
            } else {
                zend_string *value = zval_get_string(header_value);

                king_server_http2_hpack_append_header_value(
                    &header_block,
                    header_name,
                    value
                );
                zend_string_release(value);
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    smart_str_0(&header_block);
    rc = king_server_http2_write_frame(
        session->transport_socket_fd,
        KING_SERVER_HTTP2_FRAME_HEADERS,
        ZSTR_LEN(body) == 0
            ? (KING_SERVER_HTTP2_FLAG_END_HEADERS | KING_SERVER_HTTP2_FLAG_END_STREAM)
            : KING_SERVER_HTTP2_FLAG_END_HEADERS,
        stream_id,
        (const unsigned char *) ZSTR_VAL(header_block.s),
        ZSTR_LEN(header_block.s),
        function_name
    );

    if (rc == SUCCESS && ZSTR_LEN(body) > 0) {
        rc = king_server_http2_write_frame(
            session->transport_socket_fd,
            KING_SERVER_HTTP2_FRAME_DATA,
            KING_SERVER_HTTP2_FLAG_END_STREAM,
            stream_id,
            (const unsigned char *) ZSTR_VAL(body),
            ZSTR_LEN(body),
            function_name
        );
    }

    zend_string_release(status_string);
    zend_string_release(body);
    smart_str_free(&header_block);
    return rc;
}

static zend_result king_server_http2_handle_wire_request(
    king_client_session_t *session,
    zval *request,
    const char *host,
    size_t host_len,
    zend_long port,
    const char *function_name
)
{
    king_server_http2_request_state state;
    zend_bool saw_request_end = 0;

    king_server_http2_request_state_init(&state);

    while (!saw_request_end) {
        uint8_t frame_type = 0;
        uint8_t frame_flags = 0;
        uint32_t stream_id = 0;
        zend_string *payload = NULL;

        if (
            king_server_http2_read_frame(
                session->transport_socket_fd,
                &frame_type,
                &frame_flags,
                &stream_id,
                &payload,
                function_name
            ) != SUCCESS
        ) {
            king_server_http2_request_state_dtor(&state);
            return FAILURE;
        }

        switch (frame_type) {
            case KING_SERVER_HTTP2_FRAME_SETTINGS:
                if (stream_id != 0) {
                    zend_string_release(payload);
                    king_server_local_set_errorf(
                        "%s() received an HTTP/2 SETTINGS frame on a non-zero stream.",
                        function_name
                    );
                    king_server_http2_request_state_dtor(&state);
                    return FAILURE;
                }

                if ((frame_flags & KING_SERVER_HTTP2_FLAG_ACK) == 0) {
                    if ((ZSTR_LEN(payload) % 6) != 0) {
                        zend_string_release(payload);
                        king_server_local_set_errorf(
                            "%s() received an invalid HTTP/2 SETTINGS payload length.",
                            function_name
                        );
                        king_server_http2_request_state_dtor(&state);
                        return FAILURE;
                    }

                    if (
                        king_server_http2_send_settings_ack(
                            session->transport_socket_fd,
                            function_name
                        ) != SUCCESS
                    ) {
                        zend_string_release(payload);
                        king_server_http2_request_state_dtor(&state);
                        return FAILURE;
                    }
                }
                break;

            case KING_SERVER_HTTP2_FRAME_HEADERS:
                if (
                    stream_id == 0
                    || (frame_flags & KING_SERVER_HTTP2_FLAG_END_HEADERS) == 0
                    || (frame_flags & KING_SERVER_HTTP2_FLAG_PADDED) != 0
                    || (frame_flags & KING_SERVER_HTTP2_FLAG_PRIORITY) != 0
                ) {
                    zend_string_release(payload);
                    king_server_local_set_errorf(
                        "%s() received an unsupported HTTP/2 HEADERS frame shape on the current wire leaf.",
                        function_name
                    );
                    king_server_http2_request_state_dtor(&state);
                    return FAILURE;
                }

                if (state.stream_id == 0) {
                    state.stream_id = stream_id;
                } else if (state.stream_id != stream_id) {
                    zend_string_release(payload);
                    king_server_local_set_errorf(
                        "%s() only supports one active request stream on the current HTTP/2 wire leaf.",
                        function_name
                    );
                    king_server_http2_request_state_dtor(&state);
                    return FAILURE;
                }

                if (
                    king_server_http2_hpack_decode_headers(
                        (const unsigned char *) ZSTR_VAL(payload),
                        ZSTR_LEN(payload),
                        &state,
                        function_name
                    ) != SUCCESS
                ) {
                    zend_string_release(payload);
                    king_server_http2_request_state_dtor(&state);
                    return FAILURE;
                }

                if ((frame_flags & KING_SERVER_HTTP2_FLAG_END_STREAM) != 0) {
                    saw_request_end = 1;
                }
                break;

            case KING_SERVER_HTTP2_FRAME_DATA:
                if (
                    stream_id == 0
                    || stream_id != state.stream_id
                    || (frame_flags & KING_SERVER_HTTP2_FLAG_PADDED) != 0
                ) {
                    zend_string_release(payload);
                    king_server_local_set_errorf(
                        "%s() received an unsupported HTTP/2 DATA frame shape on the current wire leaf.",
                        function_name
                    );
                    king_server_http2_request_state_dtor(&state);
                    return FAILURE;
                }

                if (ZSTR_LEN(payload) > 0) {
                    if (state.body_bytes > KING_SERVER_HTTP2_MAX_REQUEST_BODY_BYTES - ZSTR_LEN(payload)) {
                        zend_string_release(payload);
                        king_server_local_set_errorf(
                            "%s() received an HTTP/2 request body that exceeds the active one-shot limit.",
                            function_name
                        );
                        king_server_http2_request_state_dtor(&state);
                        return FAILURE;
                    }

                    state.body_bytes += ZSTR_LEN(payload);
                    smart_str_appendl(&state.body, ZSTR_VAL(payload), ZSTR_LEN(payload));
                }

                if ((frame_flags & KING_SERVER_HTTP2_FLAG_END_STREAM) != 0) {
                    saw_request_end = 1;
                }
                break;

            case KING_SERVER_HTTP2_FRAME_PING:
                if (
                    stream_id != 0
                    || ZSTR_LEN(payload) != 8
                ) {
                    zend_string_release(payload);
                    king_server_local_set_errorf(
                        "%s() received an invalid HTTP/2 PING frame.",
                        function_name
                    );
                    king_server_http2_request_state_dtor(&state);
                    return FAILURE;
                }

                if ((frame_flags & KING_SERVER_HTTP2_FLAG_ACK) == 0) {
                    if (
                        king_server_http2_write_frame(
                            session->transport_socket_fd,
                            KING_SERVER_HTTP2_FRAME_PING,
                            KING_SERVER_HTTP2_FLAG_ACK,
                            0,
                            (const unsigned char *) ZSTR_VAL(payload),
                            ZSTR_LEN(payload),
                            function_name
                        ) != SUCCESS
                    ) {
                        zend_string_release(payload);
                        king_server_http2_request_state_dtor(&state);
                        return FAILURE;
                    }
                }
                break;

            default:
                /* Ignore other control frames on the current one-request leaf. */
                break;
        }

        zend_string_release(payload);
    }

    if (
        king_server_http2_build_wire_request(
            request,
            &state,
            session,
            host,
            host_len,
            port,
            function_name
        ) != SUCCESS
    ) {
        king_server_http2_request_state_dtor(&state);
        return FAILURE;
    }

    king_server_http2_request_state_dtor(&state);
    return SUCCESS;
}

