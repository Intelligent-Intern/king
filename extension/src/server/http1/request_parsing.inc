static zend_result king_server_http1_read_request_head(
    int socket_fd,
    smart_str *request_head,
    zend_long deadline_ms,
    const char *function_name
)
{
    char ch;
    size_t total = 0;

    while (1) {
        if (
            king_server_http1_wait_fd(
                socket_fd,
                POLLIN,
                deadline_ms,
                function_name,
                "request head phase"
            ) != SUCCESS
        ) {
            return FAILURE;
        }

        ssize_t chunk = recv(socket_fd, &ch, 1, 0);

        if (chunk < 0 && errno == EINTR) {
            continue;
        }

#ifdef EAGAIN
        if (chunk < 0 && (errno == EAGAIN || errno == EWOULDBLOCK)) {
            continue;
        }
#endif

        if (chunk <= 0) {
            king_server_local_set_errorf(
                "%s() failed before a complete HTTP/1 request head was received (errno %d).",
                function_name,
                errno
            );
            return FAILURE;
        }

        smart_str_appendc(request_head, ch);
        total++;

        if (total > KING_SERVER_HTTP1_MAX_REQUEST_HEAD_BYTES) {
            king_server_local_set_errorf(
                "%s() received an HTTP/1 request head larger than %d bytes.",
                function_name,
                KING_SERVER_HTTP1_MAX_REQUEST_HEAD_BYTES
            );
            return FAILURE;
        }

        if (
            total >= 4
            && ZSTR_VAL(request_head->s)[total - 4] == '\r'
            && ZSTR_VAL(request_head->s)[total - 3] == '\n'
            && ZSTR_VAL(request_head->s)[total - 2] == '\r'
            && ZSTR_VAL(request_head->s)[total - 1] == '\n'
        ) {
            break;
        }
    }

    smart_str_0(request_head);
    return SUCCESS;
}

static zend_string *king_server_http1_normalize_header_name(
    const char *name,
    size_t name_len
)
{
    zend_string *normalized = zend_string_alloc(name_len, 0);
    size_t i;

    for (i = 0; i < name_len; i++) {
        ZSTR_VAL(normalized)[i] = (char) tolower((unsigned char) name[i]);
    }

    ZSTR_VAL(normalized)[name_len] = '\0';
    return normalized;
}

static zend_result king_server_http1_parse_request_head(
    const char *raw_request,
    size_t raw_request_len,
    king_client_session_t *session,
    zval *request,
    zend_long *content_length_out,
    const char *function_name
)
{
    const char *cursor = raw_request;
    const char *end = raw_request + raw_request_len;
    const char *line_end;
    const char *method_end;
    const char *target_end;
    const char *body_cursor;
    zval headers;
    zend_string *host_value = NULL;
    zend_bool saw_ws_connection = false;
    zend_bool saw_ws_upgrade = false;

    *content_length_out = 0;

    line_end = strstr(cursor, "\r\n");
    if (line_end == NULL) {
        king_server_local_set_errorf(
            "%s() received an invalid HTTP/1 request line.",
            function_name
        );
        return FAILURE;
    }

    method_end = memchr(cursor, ' ', (size_t) (line_end - cursor));
    if (method_end == NULL || method_end == cursor) {
        king_server_local_set_errorf(
            "%s() received an invalid HTTP/1 method token.",
            function_name
        );
        return FAILURE;
    }

    target_end = memchr(method_end + 1, ' ', (size_t) (line_end - (method_end + 1)));
    if (target_end == NULL || target_end == method_end + 1) {
        king_server_local_set_errorf(
            "%s() received an invalid HTTP/1 request target.",
            function_name
        );
        return FAILURE;
    }

    array_init(request);
    add_assoc_stringl(request, "method", cursor, (size_t) (method_end - cursor));
    add_assoc_stringl(
        request,
        "uri",
        method_end + 1,
        (size_t) (target_end - (method_end + 1))
    );
    add_assoc_stringl(
        request,
        "version",
        target_end + 1,
        (size_t) (line_end - (target_end + 1))
    );

    if (session->server_pending_request_target != NULL) {
        zend_string_release(session->server_pending_request_target);
    }
    session->server_pending_request_target = zend_string_init(
        method_end + 1,
        (size_t) (target_end - (method_end + 1)),
        0
    );

    if (session->server_pending_websocket_key != NULL) {
        zend_string_release(session->server_pending_websocket_key);
        session->server_pending_websocket_key = NULL;
    }
    session->server_pending_websocket_upgrade = false;

    array_init(&headers);
    cursor = line_end + 2;
    while (cursor < end) {
        const char *colon;
        const char *value_start;
        size_t name_len;
        size_t value_len;
        zend_string *normalized_name;
        zval *existing;

        line_end = strstr(cursor, "\r\n");
        if (line_end == NULL) {
            zval_ptr_dtor(&headers);
            zval_ptr_dtor(request);
            ZVAL_UNDEF(request);
            king_server_local_set_errorf(
                "%s() received an invalid HTTP/1 header block.",
                function_name
            );
            return FAILURE;
        }

        if (line_end == cursor) {
            cursor = line_end + 2;
            break;
        }

        colon = memchr(cursor, ':', (size_t) (line_end - cursor));
        if (colon == NULL || colon == cursor) {
            zval_ptr_dtor(&headers);
            zval_ptr_dtor(request);
            ZVAL_UNDEF(request);
            king_server_local_set_errorf(
                "%s() received an invalid HTTP/1 header line.",
                function_name
            );
            return FAILURE;
        }

        name_len = king_server_http1_trim_len(cursor, (size_t) (colon - cursor));
        value_start = king_server_http1_skip_space(colon + 1);
        value_len = king_server_http1_trim_len(
            value_start,
            (size_t) (line_end - value_start)
        );

        normalized_name = king_server_http1_normalize_header_name(cursor, name_len);
        existing = zend_hash_find(Z_ARRVAL(headers), normalized_name);
        if (existing == NULL) {
            zval header_value;

            ZVAL_STRINGL(&header_value, value_start, value_len);
            zend_hash_update(Z_ARRVAL(headers), normalized_name, &header_value);
        } else if (Z_TYPE_P(existing) == IS_ARRAY) {
            add_next_index_stringl(existing, value_start, value_len);
        } else {
            zval values;
            zend_string *first_value = zval_get_string(existing);

            array_init(&values);
            add_next_index_str(&values, first_value);
            add_next_index_stringl(&values, value_start, value_len);
            zend_hash_update(Z_ARRVAL(headers), normalized_name, &values);
        }
        zend_string_release(normalized_name);

        if (name_len == sizeof("Host") - 1 && strncasecmp(cursor, "Host", name_len) == 0) {
            if (host_value != NULL) {
                zend_string_release(host_value);
            }
            host_value = zend_string_init(value_start, value_len, 0);
        } else if (
            name_len == sizeof("Content-Length") - 1
            && strncasecmp(cursor, "Content-Length", name_len) == 0
        ) {
            size_t i;
            zend_ulong parsed = 0;

            if (value_len == 0) {
                if (host_value != NULL) {
                    zend_string_release(host_value);
                }
                zval_ptr_dtor(&headers);
                zval_ptr_dtor(request);
                ZVAL_UNDEF(request);
                king_server_local_set_errorf(
                    "%s() received an invalid HTTP/1 Content-Length header.",
                    function_name
                );
                return FAILURE;
            }

            for (i = 0; i < value_len; i++) {
                unsigned char ch = (unsigned char) value_start[i];
                if (ch < '0' || ch > '9') {
                    if (host_value != NULL) {
                        zend_string_release(host_value);
                    }
                    zval_ptr_dtor(&headers);
                    zval_ptr_dtor(request);
                    ZVAL_UNDEF(request);
                    king_server_local_set_errorf(
                        "%s() received an invalid HTTP/1 Content-Length header.",
                        function_name
                    );
                    return FAILURE;
                }

                if (
                    parsed > (zend_ulong) KING_SERVER_HTTP1_MAX_REQUEST_BODY_BYTES / 10
                    || (
                        parsed == (zend_ulong) KING_SERVER_HTTP1_MAX_REQUEST_BODY_BYTES / 10
                        && (zend_ulong) (ch - '0')
                            > (zend_ulong) KING_SERVER_HTTP1_MAX_REQUEST_BODY_BYTES % 10
                    )
                ) {
                    if (host_value != NULL) {
                        zend_string_release(host_value);
                    }
                    zval_ptr_dtor(&headers);
                    zval_ptr_dtor(request);
                    ZVAL_UNDEF(request);
                    king_server_local_set_errorf(
                        "%s() received an invalid HTTP/1 Content-Length header.",
                        function_name
                    );
                    return FAILURE;
                }

                parsed = (parsed * 10) + (zend_ulong) (ch - '0');
            }

            *content_length_out = (zend_long) parsed;
        } else if (
            name_len == sizeof("Connection") - 1
            && strncasecmp(cursor, "Connection", name_len) == 0
        ) {
            saw_ws_connection = king_server_http1_header_value_has_token(
                value_start,
                value_len,
                "Upgrade"
            );
        } else if (
            name_len == sizeof("Upgrade") - 1
            && strncasecmp(cursor, "Upgrade", name_len) == 0
        ) {
            saw_ws_upgrade = (
                value_len == sizeof("websocket") - 1
                && strncasecmp(value_start, "websocket", value_len) == 0
            );
        } else if (
            name_len == sizeof("Sec-WebSocket-Key") - 1
            && strncasecmp(cursor, "Sec-WebSocket-Key", name_len) == 0
            && value_len > 0
        ) {
            session->server_pending_websocket_key = zend_string_init(
                value_start,
                value_len,
                0
            );
        }

        cursor = line_end + 2;
    }

    if (host_value == NULL) {
        host_value = strpprintf(
            0,
            "%s:" ZEND_LONG_FMT,
            ZSTR_VAL(session->host),
            session->port
        );
    }

    body_cursor = cursor;
    add_assoc_str(request, "host", host_value);
    add_assoc_zval(request, "headers", &headers);
    add_assoc_string(request, "body", "");

    king_server_local_add_common_request_fields(
        request,
        session,
        "http/1.1",
        "http",
        0
    );

    session->server_pending_websocket_upgrade =
        saw_ws_connection
        && saw_ws_upgrade
        && session->server_pending_websocket_key != NULL;

    if (body_cursor != end && (size_t) (end - body_cursor) > 0) {
        king_server_local_set_errorf(
            "%s() received unread HTTP/1 request bytes before the request body stage.",
            function_name
        );
        zval_ptr_dtor(request);
        ZVAL_UNDEF(request);
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_server_http1_read_request_body(
    int socket_fd,
    zval *request,
    zend_long content_length,
    zend_long deadline_ms,
    const char *function_name
)
{
    zend_string *body;
    zval *body_zv;

    body_zv = zend_hash_str_find(
        Z_ARRVAL_P(request),
        "body",
        sizeof("body") - 1
    );
    if (body_zv == NULL || Z_TYPE_P(body_zv) != IS_STRING) {
        king_server_local_set_errorf(
            "%s() failed to attach the parsed HTTP/1 request body.",
            function_name
        );
        return FAILURE;
    }

    if (content_length == 0) {
        return SUCCESS;
    }

    body = zend_string_alloc((size_t) content_length, 0);
    if (
        king_server_http1_read_exact_fd(
            socket_fd,
            (unsigned char *) ZSTR_VAL(body),
            (size_t) content_length,
            deadline_ms,
            function_name
        ) != SUCCESS
    ) {
        zend_string_release(body);
        return FAILURE;
    }

    ZSTR_VAL(body)[content_length] = '\0';
    zval_ptr_dtor(body_zv);
    ZVAL_STR(body_zv, body);
    return SUCCESS;
}

static zend_result king_server_http1_append_response_headers(
    smart_str *response,
    zval *headers
)
{
    zend_string *header_name;
    zval *header_value;

    if (headers == NULL || Z_TYPE_P(headers) != IS_ARRAY) {
        return SUCCESS;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers), header_name, header_value)
    {
        if (header_name == NULL) {
            continue;
        }

        if (
            zend_string_equals_literal_ci(header_name, "Content-Length")
            || zend_string_equals_literal_ci(header_name, "Connection")
        ) {
            continue;
        }

        if (Z_TYPE_P(header_value) == IS_ARRAY) {
            zval *entry;

            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(header_value), entry)
            {
                zend_string *value = zval_get_string(entry);

                smart_str_append(response, header_name);
                smart_str_appends(response, ": ");
                smart_str_append(response, value);
                smart_str_appends(response, "\r\n");
                zend_string_release(value);
            }
            ZEND_HASH_FOREACH_END();
            continue;
        }

        {
            zend_string *value = zval_get_string(header_value);

            smart_str_append(response, header_name);
            smart_str_appends(response, ": ");
            smart_str_append(response, value);
            smart_str_appends(response, "\r\n");
            zend_string_release(value);
        }
    }
    ZEND_HASH_FOREACH_END();

    return SUCCESS;
}
