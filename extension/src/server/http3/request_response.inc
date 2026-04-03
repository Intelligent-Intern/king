static zend_result king_server_http3_add_header_value(
    zval *headers,
    const char *name,
    size_t name_len,
    const char *value,
    size_t value_len
)
{
    zend_string *normalized_name;
    zval *existing;
    zval array_value;
    zval old_value;

    normalized_name = zend_string_alloc(name_len, 0);
    for (size_t i = 0; i < name_len; ++i) {
        ZSTR_VAL(normalized_name)[i] = (char) tolower((unsigned char) name[i]);
    }
    ZSTR_VAL(normalized_name)[name_len] = '\0';

    existing = zend_hash_find(Z_ARRVAL_P(headers), normalized_name);
    if (existing == NULL) {
        add_assoc_stringl_ex(
            headers,
            ZSTR_VAL(normalized_name),
            ZSTR_LEN(normalized_name),
            (char *) value,
            value_len
        );
        zend_string_release(normalized_name);
        return SUCCESS;
    }

    if (Z_TYPE_P(existing) == IS_ARRAY) {
        add_next_index_stringl(existing, (char *) value, value_len);
        zend_string_release(normalized_name);
        return SUCCESS;
    }

    array_init(&array_value);
    ZVAL_COPY(&old_value, existing);
    add_next_index_zval(&array_value, &old_value);
    add_next_index_stringl(&array_value, (char *) value, value_len);
    zend_hash_update(Z_ARRVAL_P(headers), normalized_name, &array_value);
    zend_string_release(normalized_name);
    return SUCCESS;
}

static void king_server_http3_request_state_init(king_server_http3_request_state_t *state)
{
    memset(state, 0, sizeof(*state));
    array_init(&state->headers);
    state->headers_initialized = true;
}

static void king_server_http3_request_state_dtor(king_server_http3_request_state_t *state)
{
    if (state->method != NULL) {
        zend_string_release(state->method);
    }
    if (state->path != NULL) {
        zend_string_release(state->path);
    }
    if (state->scheme != NULL) {
        zend_string_release(state->scheme);
    }
    if (state->authority != NULL) {
        zend_string_release(state->authority);
    }
    if (state->headers_initialized) {
        zval_ptr_dtor(&state->headers);
    }
    smart_str_free(&state->body);
}

static int king_server_http3_collect_request_header(
    uint8_t *name,
    size_t name_len,
    uint8_t *value,
    size_t value_len,
    void *argp
)
{
    king_server_http3_request_header_context_t *context = (king_server_http3_request_header_context_t *) argp;
    king_server_http3_request_state_t *state = context->state;
    zend_string **slot = NULL;

    state->header_bytes += name_len + value_len;
    if (state->header_bytes > KING_SERVER_HTTP3_MAX_HEADER_BYTES) {
        king_server_local_set_errorf(
            "%s() received an HTTP/3 request whose headers exceed the active one-shot limit.",
            context->function_name
        );
        return -1;
    }

    if (name_len == sizeof(":method") - 1 && memcmp(name, ":method", sizeof(":method") - 1) == 0) {
        slot = &state->method;
    } else if (name_len == sizeof(":path") - 1 && memcmp(name, ":path", sizeof(":path") - 1) == 0) {
        slot = &state->path;
    } else if (name_len == sizeof(":scheme") - 1 && memcmp(name, ":scheme", sizeof(":scheme") - 1) == 0) {
        slot = &state->scheme;
    } else if (name_len == sizeof(":authority") - 1 && memcmp(name, ":authority", sizeof(":authority") - 1) == 0) {
        slot = &state->authority;
    }

    if (slot != NULL) {
        if (*slot != NULL) {
            zend_string_release(*slot);
        }
        *slot = zend_string_init((const char *) value, value_len, 0);
    }

    if (king_server_http3_add_header_value(
            &state->headers,
            (const char *) name,
            name_len,
            (const char *) value,
            value_len
        ) != SUCCESS) {
        king_server_local_set_errorf(
            "%s() failed to collect the active HTTP/3 request headers.",
            context->function_name
        );
        return -1;
    }

    return 0;
}

static zend_result king_server_http3_build_wire_request(
    zval *request,
    king_server_http3_request_state_t *state,
    king_client_session_t *session,
    const char *host,
    size_t host_len,
    zend_long port,
    const char *function_name
)
{
    zend_string *authority;
    const char *scheme = "https";

    if (state->method == NULL || state->path == NULL) {
        king_server_local_set_errorf(
            "%s() requires ':method' and ':path' pseudo headers on the on-wire HTTP/3 leaf.",
            function_name
        );
        return FAILURE;
    }

    authority = state->authority != NULL
        ? zend_string_copy(state->authority)
        : strpprintf(0, "%.*s:%ld", (int) host_len, host, port);
    if (state->scheme != NULL && ZSTR_LEN(state->scheme) > 0) {
        scheme = ZSTR_VAL(state->scheme);
    }

    array_init(request);
    add_assoc_str(request, "method", zend_string_copy(state->method));
    add_assoc_str(request, "uri", zend_string_copy(state->path));
    add_assoc_string(request, "version", "HTTP/3");
    add_assoc_str(request, "host", zend_string_copy(authority));
    add_assoc_zval(request, "headers", &state->headers);
    state->headers_initialized = false;

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
        "http/3",
        scheme,
        (zend_long) state->stream_id
    );

    zend_string_release(authority);
    return SUCCESS;
}

static void king_server_http3_response_state_dtor(king_server_http3_response_state_t *state)
{
    size_t i;

    if (state->headers != NULL) {
        efree(state->headers);
    }

    if (state->owned_strings != NULL) {
        for (i = 0; i < state->owned_string_count; ++i) {
            zend_string_release(state->owned_strings[i]);
        }
        efree(state->owned_strings);
    }

    if (state->body != NULL) {
        zend_string_release(state->body);
    }

    memset(state, 0, sizeof(*state));
}

static zend_result king_server_http3_response_append_header(
    king_server_http3_response_state_t *state,
    size_t *index,
    zend_string *header_name,
    zval *header_value
)
{
    zend_string *normalized_name;
    zend_string *value_string;

    if (header_name == NULL || ZSTR_LEN(header_name) == 0) {
        return SUCCESS;
    }

    normalized_name = zend_string_tolower(header_name);
    if (ZSTR_LEN(normalized_name) == 0
        || ZSTR_VAL(normalized_name)[0] == ':'
        || zend_string_equals_literal(normalized_name, "connection")
        || zend_string_equals_literal(normalized_name, "content-length")) {
        zend_string_release(normalized_name);
        return SUCCESS;
    }

    value_string = zval_get_string(header_value);
    state->headers[*index].name = (const uint8_t *) ZSTR_VAL(normalized_name);
    state->headers[*index].name_len = ZSTR_LEN(normalized_name);
    state->headers[*index].value = (const uint8_t *) ZSTR_VAL(value_string);
    state->headers[*index].value_len = ZSTR_LEN(value_string);
    state->owned_strings[state->owned_string_count++] = normalized_name;
    state->owned_strings[state->owned_string_count++] = value_string;
    (*index)++;
    return SUCCESS;
}

static zend_result king_server_http3_prepare_response_state(
    king_server_http3_response_state_t *state,
    zval *retval,
    uint64_t stream_id,
    const char *function_name
)
{
    zval *status_zv;
    zval *headers_zv;
    zval *body_zv;
    zend_long status = 200;
    zend_string *status_string;
    size_t header_count = 1;
    size_t index = 0;

    memset(state, 0, sizeof(*state));
    state->stream_id = stream_id;

    status_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "status", sizeof("status") - 1);
    headers_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "headers", sizeof("headers") - 1);
    body_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "body", sizeof("body") - 1);

    if (status_zv != NULL && Z_TYPE_P(status_zv) != IS_NULL) {
        status = zval_get_long(status_zv);
    }

    if (body_zv != NULL && Z_TYPE_P(body_zv) != IS_NULL) {
        state->body = zval_get_string(body_zv);
    } else {
        state->body = zend_string_init("", 0, 0);
    }

    if (headers_zv != NULL && Z_TYPE_P(headers_zv) == IS_ARRAY) {
        zend_string *header_name;
        zval *header_value;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers_zv), header_name, header_value)
        {
            if (header_name == NULL) {
                continue;
            }

            if (Z_TYPE_P(header_value) == IS_ARRAY) {
                zval *entry;

                ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(header_value), entry)
                {
                    header_count++;
                }
                ZEND_HASH_FOREACH_END();
            } else {
                header_count++;
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    state->headers = safe_emalloc(header_count, sizeof(*state->headers), 0);
    state->owned_strings = safe_emalloc(header_count * 2, sizeof(*state->owned_strings), 0);

    status_string = strpprintf(0, "%ld", status);
    state->headers[index].name = (const uint8_t *) ":status";
    state->headers[index].name_len = sizeof(":status") - 1;
    state->headers[index].value = (const uint8_t *) ZSTR_VAL(status_string);
    state->headers[index].value_len = ZSTR_LEN(status_string);
    state->owned_strings[state->owned_string_count++] = status_string;
    index++;

    if (headers_zv != NULL && Z_TYPE_P(headers_zv) == IS_ARRAY) {
        zend_string *header_name;
        zval *header_value;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers_zv), header_name, header_value)
        {
            if (header_name == NULL) {
                continue;
            }

            if (Z_TYPE_P(header_value) == IS_ARRAY) {
                zval *entry;

                ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(header_value), entry)
                {
                    if (king_server_http3_response_append_header(state, &index, header_name, entry) != SUCCESS) {
                        king_server_http3_response_state_dtor(state);
                        king_server_local_set_errorf(
                            "%s() failed to normalize the HTTP/3 response headers.",
                            function_name
                        );
                        return FAILURE;
                    }
                }
                ZEND_HASH_FOREACH_END();
            } else {
                if (king_server_http3_response_append_header(state, &index, header_name, header_value) != SUCCESS) {
                    king_server_http3_response_state_dtor(state);
                    king_server_local_set_errorf(
                        "%s() failed to normalize the HTTP/3 response headers.",
                        function_name
                    );
                    return FAILURE;
                }
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    state->headers_len = index;
    state->initialized = true;
    return SUCCESS;
}

