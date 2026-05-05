static zend_result king_http3_prepare_request_headers(
    const king_http3_request_target_t *target,
    const char *method_str,
    size_t method_len,
    zval *headers_array,
    king_http3_header_t **headers_out,
    size_t *header_count_out,
    zend_string ***owned_strings_out,
    size_t *owned_string_count_out,
    const char *function_name)
{
    size_t header_count = 4;
    size_t owned_count = 0;
    king_http3_header_t *headers;
    zend_string **owned_strings;
    size_t index = 0;
    zend_string *value_string;

    if (headers_array != NULL && Z_TYPE_P(headers_array) == IS_ARRAY) {
        header_count += zend_hash_num_elements(Z_ARRVAL_P(headers_array));
    }

    headers = safe_emalloc(header_count, sizeof(*headers), 0);
    owned_strings = safe_emalloc(header_count * 2, sizeof(*owned_strings), 0);

    headers[index].name = (const uint8_t *) ":method";
    headers[index].name_len = sizeof(":method") - 1;
    headers[index].value = (const uint8_t *) method_str;
    headers[index].value_len = method_len;
    index++;

    headers[index].name = (const uint8_t *) ":scheme";
    headers[index].name_len = sizeof(":scheme") - 1;
    headers[index].value = (const uint8_t *) "https";
    headers[index].value_len = sizeof("https") - 1;
    index++;

    headers[index].name = (const uint8_t *) ":path";
    headers[index].name_len = sizeof(":path") - 1;
    headers[index].value = (const uint8_t *) ZSTR_VAL(target->path);
    headers[index].value_len = ZSTR_LEN(target->path);
    index++;

    headers[index].name = (const uint8_t *) ":authority";
    headers[index].name_len = sizeof(":authority") - 1;
    headers[index].value = (const uint8_t *) ZSTR_VAL(target->authority);
    headers[index].value_len = ZSTR_LEN(target->authority);
    index++;

    if (headers_array != NULL && Z_TYPE_P(headers_array) == IS_ARRAY) {
        zend_string *header_name;
        zval *header_value;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers_array), header_name, header_value) {
            zend_string *normalized_name;

            if (header_name == NULL) {
                king_http3_set_error(
                    "%s() requires request headers to use string keys.",
                    function_name
                );
                goto failure;
            }

            normalized_name = zend_string_tolower(header_name);
            if (ZSTR_LEN(normalized_name) == 0) {
                zend_string_release(normalized_name);
                king_http3_set_error(
                    "%s() requires non-empty request header names.",
                    function_name
                );
                goto failure;
            }

            if (ZSTR_VAL(normalized_name)[0] == ':') {
                zend_string_release(normalized_name);
                king_http3_set_error(
                    "%s() does not allow overriding HTTP/3 pseudo headers.",
                    function_name
                );
                goto failure;
            }

            for (size_t i = 0; i < ZSTR_LEN(normalized_name); ++i) {
                if (!king_http3_is_token_char((unsigned char) ZSTR_VAL(normalized_name)[i])) {
                    zend_string_release(normalized_name);
                    king_http3_set_error(
                        "%s() request header '%s' contains invalid HTTP token characters.",
                        function_name,
                        ZSTR_VAL(header_name)
                    );
                    goto failure;
                }
            }

            if (zend_string_equals_literal(normalized_name, "host")) {
                zend_string_release(normalized_name);
                continue;
            }

            value_string = zval_get_string(header_value);
            if (king_http3_string_has_crlf(ZSTR_VAL(value_string), ZSTR_LEN(value_string))) {
                zend_string_release(normalized_name);
                zend_string_release(value_string);
                king_http3_set_error(
                    "%s() request header '%s' contains CRLF characters.",
                    function_name,
                    ZSTR_VAL(header_name)
                );
                goto failure;
            }

            headers[index].name = (const uint8_t *) ZSTR_VAL(normalized_name);
            headers[index].name_len = ZSTR_LEN(normalized_name);
            headers[index].value = (const uint8_t *) ZSTR_VAL(value_string);
            headers[index].value_len = ZSTR_LEN(value_string);
            owned_strings[owned_count++] = normalized_name;
            owned_strings[owned_count++] = value_string;
            index++;
        } ZEND_HASH_FOREACH_END();
    }

    *headers_out = headers;
    *header_count_out = index;
    *owned_strings_out = owned_strings;
    *owned_string_count_out = owned_count;
    return SUCCESS;

failure:
    for (size_t i = 0; i < owned_count; ++i) {
        zend_string_release(owned_strings[i]);
    }
    efree(owned_strings);
    efree(headers);
    return FAILURE;
}

static void king_http3_free_request_headers(
    king_http3_header_t *headers,
    zend_string **owned_strings,
    size_t owned_string_count)
{
    size_t i;

    if (owned_strings != NULL) {
        for (i = 0; i < owned_string_count; ++i) {
            zend_string_release(owned_strings[i]);
        }
        efree(owned_strings);
    }

    if (headers != NULL) {
        efree(headers);
    }
}

static zend_result king_http3_materialize_response(
    zval *return_value,
    king_http3_response_t *response,
    const king_http3_request_runtime_t *runtime,
    const char *url_str)
{
    char status_line_buffer[64];

    if ((zend_long) response->status_code <= 0) {
        king_http3_throw(
            king_ce_protocol_exception,
            "king_http3_request_send() did not receive a valid HTTP/3 status code."
        );
        return FAILURE;
    }

    smart_str_0(&response->body);

    snprintf(status_line_buffer, sizeof(status_line_buffer), "HTTP/3 " ZEND_LONG_FMT, (zend_long) response->status_code);
    response->status_line = zend_string_init(status_line_buffer, strlen(status_line_buffer), 0);

    array_init(return_value);
    add_assoc_long(return_value, "status", (zend_long) response->status_code);
    add_assoc_str(return_value, "status_line", response->status_line);
    response->status_line = NULL;
    add_assoc_zval(return_value, "headers", &response->headers);
    response->headers_initialized = false;
    add_assoc_stringl(
        return_value,
        "body",
        response->body.s != NULL ? ZSTR_VAL(response->body.s) : "",
        response->body.s != NULL ? ZSTR_LEN(response->body.s) : 0
    );
    add_assoc_string(return_value, "protocol", "http/3");
    add_assoc_string(
        return_value,
        "transport_backend",
#if defined(KING_HTTP3_BACKEND_LSQUIC)
        runtime != NULL && runtime->lsquic_backend_active ? "lsquic_h3" :
#endif
        "unavailable"
    );
    add_assoc_string(return_value, "effective_url", (char *) url_str);
    add_assoc_long(
        return_value,
        "quic_packets_sent",
        runtime != NULL ? runtime->quic_packets_sent : 0
    );
    add_assoc_long(
        return_value,
        "quic_packets_received",
        runtime != NULL ? runtime->quic_packets_received : 0
    );
    add_assoc_long(
        return_value,
        "quic_packets_lost",
        runtime != NULL ? runtime->quic_packets_lost : 0
    );
    add_assoc_long(
        return_value,
        "quic_packets_retransmitted",
        runtime != NULL ? runtime->quic_packets_retransmitted : 0
    );
    add_assoc_long(
        return_value,
        "quic_lost_bytes",
        runtime != NULL ? runtime->quic_lost_bytes : 0
    );
    add_assoc_long(
        return_value,
        "quic_stream_retransmitted_bytes",
        runtime != NULL ? runtime->quic_stream_retransmitted_bytes : 0
    );
    add_assoc_bool(return_value, "response_complete", response->response_complete ? 1 : 0);
    add_assoc_long(return_value, "body_bytes", (zend_long) response->body_bytes);
    add_assoc_long(return_value, "header_bytes", (zend_long) response->header_bytes);
    add_assoc_string(return_value, "stream_kind", "request");
    add_assoc_bool(
        return_value,
        "tls_has_session_ticket",
        runtime != NULL && runtime->tls_has_session_ticket
    );
    add_assoc_long(
        return_value,
        "tls_session_ticket_length",
        runtime != NULL ? runtime->tls_session_ticket_length : 0
    );
    add_assoc_bool(
        return_value,
        "tls_enable_early_data",
        runtime != NULL && runtime->tls_enable_early_data
    );
    add_assoc_bool(
        return_value,
        "tls_request_sent_in_early_data",
        runtime != NULL && runtime->tls_request_sent_in_early_data
    );
    add_assoc_string(
        return_value,
        "tls_ticket_source",
        (char *) (
            runtime != NULL && runtime->tls_ticket_source != NULL
                ? runtime->tls_ticket_source
                : "none"
        )
    );
    add_assoc_bool(
        return_value,
        "tls_session_resumed",
        runtime != NULL && runtime->tls_session_resumed
    );

    return SUCCESS;
}
