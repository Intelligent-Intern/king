static zend_result king_http2_append_header_line(
    struct curl_slist **request_headers,
    const char *header_name,
    size_t header_name_len,
    const char *header_value,
    size_t header_value_len,
    const char *function_name)
{
    zend_string *lower_header_name;
    char *header_line = NULL;
    struct curl_slist *updated_list;

    if (header_name_len == 0) {
        king_http2_set_error(
            "%s() header names must not be empty.",
            function_name
        );
        return FAILURE;
    }

    if (king_http2_string_has_crlf(header_name, header_name_len)) {
        king_http2_set_error(
            "%s() header names must not contain CR or LF bytes.",
            function_name
        );
        return FAILURE;
    }

    if (king_http2_string_has_crlf(header_value, header_value_len)) {
        king_http2_set_error(
            "%s() header '%.*s' must not contain CR or LF bytes.",
            function_name,
            (int) header_name_len,
            header_name
        );
        return FAILURE;
    }

    lower_header_name = zend_string_init(header_name, header_name_len, 0);
    zend_str_tolower(ZSTR_VAL(lower_header_name), ZSTR_LEN(lower_header_name));

    if (ZSTR_VAL(lower_header_name)[0] == ':') {
        king_http2_set_error(
            "%s() does not allow userland pseudo-headers in the active HTTP/2 runtime.",
            function_name
        );
        zend_string_release(lower_header_name);
        return FAILURE;
    }

    if (strcmp(ZSTR_VAL(lower_header_name), "connection") == 0
        || strcmp(ZSTR_VAL(lower_header_name), "keep-alive") == 0
        || strcmp(ZSTR_VAL(lower_header_name), "proxy-connection") == 0
        || strcmp(ZSTR_VAL(lower_header_name), "transfer-encoding") == 0
        || strcmp(ZSTR_VAL(lower_header_name), "upgrade") == 0) {
        king_http2_set_error(
            "%s() header '%s' is not valid on the active HTTP/2 runtime.",
            function_name,
            ZSTR_VAL(lower_header_name)
        );
        zend_string_release(lower_header_name);
        return FAILURE;
    }

    {
        size_t i;

        for (i = 0; i < ZSTR_LEN(lower_header_name); ++i) {
            if (!king_http2_is_token_char((unsigned char) ZSTR_VAL(lower_header_name)[i])) {
                king_http2_set_error(
                    "%s() header '%s' contains invalid HTTP token characters.",
                    function_name,
                    ZSTR_VAL(lower_header_name)
                );
                zend_string_release(lower_header_name);
                return FAILURE;
            }
        }
    }

    spprintf(
        &header_line,
        0,
        "%s: %.*s",
        ZSTR_VAL(lower_header_name),
        (int) header_value_len,
        header_value
    );
    zend_string_release(lower_header_name);

    if (header_line == NULL) {
        king_http2_set_error("%s() failed to allocate an HTTP/2 header line.", function_name);
        return FAILURE;
    }

    updated_list = king_http2_libcurl.curl_slist_append_fn(*request_headers, header_line);
    efree(header_line);

    if (updated_list == NULL) {
        king_http2_set_error("%s() failed to append a libcurl HTTP/2 header.", function_name);
        return FAILURE;
    }

    *request_headers = updated_list;
    return SUCCESS;
}

static zend_result king_http2_prepare_headers(
    struct curl_slist **request_headers,
    zval *headers_array,
    zend_string *body_string,
    bool body_was_supplied,
    const char *function_name)
{
    bool has_accept = false;
    bool has_user_agent = false;
    bool has_content_length = false;
    zval *header_value;
    zend_string *header_name;

    if (headers_array != NULL) {
        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers_array), header_name, header_value) {
            zend_string *header_value_string;

            if (header_name == NULL) {
                king_http2_set_error(
                    "%s() headers must use string keys.",
                    function_name
                );
                return FAILURE;
            }

            header_value_string = zval_get_string(header_value);

            if (strcasecmp(ZSTR_VAL(header_name), "accept") == 0) {
                has_accept = true;
            } else if (strcasecmp(ZSTR_VAL(header_name), "user-agent") == 0) {
                has_user_agent = true;
            } else if (strcasecmp(ZSTR_VAL(header_name), "content-length") == 0) {
                has_content_length = true;
            }

            if (king_http2_append_header_line(
                    request_headers,
                    ZSTR_VAL(header_name),
                    ZSTR_LEN(header_name),
                    ZSTR_VAL(header_value_string),
                    ZSTR_LEN(header_value_string),
                    function_name
                ) != SUCCESS) {
                zend_string_release(header_value_string);
                return FAILURE;
            }

            zend_string_release(header_value_string);
        } ZEND_HASH_FOREACH_END();
    }

    if (!has_user_agent
        && king_http2_append_header_line(
            request_headers,
            "user-agent",
            sizeof("user-agent") - 1,
            "King-Client/0.1",
            sizeof("King-Client/0.1") - 1,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    if (!has_accept
        && king_http2_append_header_line(
            request_headers,
            "accept",
            sizeof("accept") - 1,
            "*/*",
            sizeof("*/*") - 1,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    if (!has_content_length && body_was_supplied) {
        char content_length_buffer[32];

        snprintf(
            content_length_buffer,
            sizeof(content_length_buffer),
            "%zu",
            body_string != NULL ? ZSTR_LEN(body_string) : 0
        );

        if (king_http2_append_header_line(
                request_headers,
                "content-length",
                sizeof("content-length") - 1,
                content_length_buffer,
                strlen(content_length_buffer),
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    return SUCCESS;
}
