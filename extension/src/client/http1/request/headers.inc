static zend_result king_http1_append_request_headers(
    smart_str *request_buffer,
    zval *headers_array,
    zend_string *body_string,
    bool body_was_supplied,
    bool drop_body_headers,
    const char *host_header,
    size_t host_header_len,
    const char *function_name
)
{
    bool has_host = false;
    bool has_connection = false;
    bool has_user_agent = false;
    bool has_accept = false;
    bool has_content_length = false;
    zval *header_value;
    zend_string *header_name;

    if (headers_array != NULL) {
        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers_array), header_name, header_value) {
            zend_string *header_value_string;

            if (header_name == NULL) {
                king_http1_set_error(
                    "%s() headers must use string keys.",
                    function_name
                );
                return FAILURE;
            }

            if (ZSTR_LEN(header_name) == 0) {
                king_http1_set_error(
                    "%s() header names must not be empty.",
                    function_name
                );
                return FAILURE;
            }

            if (king_http1_string_has_crlf(ZSTR_VAL(header_name), ZSTR_LEN(header_name))) {
                king_http1_set_error(
                    "%s() header names must not contain CR or LF bytes.",
                    function_name
                );
                return FAILURE;
            }

            {
                size_t i;

                for (i = 0; i < ZSTR_LEN(header_name); ++i) {
                    if (!king_http1_is_token_char((unsigned char) ZSTR_VAL(header_name)[i])) {
                        king_http1_set_error(
                            "%s() header '%s' contains invalid HTTP token characters.",
                            function_name,
                            ZSTR_VAL(header_name)
                        );
                        return FAILURE;
                    }
                }
            }

            if (strcasecmp(ZSTR_VAL(header_name), "host") == 0) {
                has_host = true;
            } else if (strcasecmp(ZSTR_VAL(header_name), "connection") == 0) {
                has_connection = true;
            } else if (strcasecmp(ZSTR_VAL(header_name), "user-agent") == 0) {
                has_user_agent = true;
            } else if (strcasecmp(ZSTR_VAL(header_name), "accept") == 0) {
                has_accept = true;
            } else if (strcasecmp(ZSTR_VAL(header_name), "content-length") == 0) {
                if (drop_body_headers) {
                    continue;
                }
                has_content_length = true;
            } else if (
                drop_body_headers
                && (
                    strcasecmp(ZSTR_VAL(header_name), "content-type") == 0
                    || strcasecmp(ZSTR_VAL(header_name), "transfer-encoding") == 0
                )
            ) {
                continue;
            }

            header_value_string = zval_get_string(header_value);
            if (king_http1_string_has_crlf(
                    ZSTR_VAL(header_value_string),
                    ZSTR_LEN(header_value_string)
                )) {
                king_http1_set_error(
                    "%s() header '%s' must not contain CR or LF bytes.",
                    function_name,
                    ZSTR_VAL(header_name)
                );
                zend_string_release(header_value_string);
                return FAILURE;
            }

            smart_str_append(request_buffer, header_name);
            smart_str_appends(request_buffer, ": ");
            smart_str_append(request_buffer, header_value_string);
            smart_str_appends(request_buffer, "\r\n");

            zend_string_release(header_value_string);
        } ZEND_HASH_FOREACH_END();
    }

    if (!has_host) {
        smart_str_appends(request_buffer, "Host: ");
        smart_str_appendl(request_buffer, host_header, host_header_len);
        smart_str_appends(request_buffer, "\r\n");
    }

    if (!has_user_agent) {
        smart_str_appends(request_buffer, "User-Agent: King-Client/0.1\r\n");
    }

    if (!has_accept) {
        smart_str_appends(request_buffer, "Accept: */*\r\n");
    }

    if (!has_connection) {
        smart_str_appends(request_buffer, "Connection: keep-alive\r\n");
    }

    if (!has_content_length && body_was_supplied) {
        char content_length_buffer[32];

        snprintf(
            content_length_buffer,
            sizeof(content_length_buffer),
            "%zu",
            body_string != NULL ? ZSTR_LEN(body_string) : 0
        );
        smart_str_appends(request_buffer, "Content-Length: ");
        smart_str_appends(request_buffer, content_length_buffer);
        smart_str_appends(request_buffer, "\r\n");
    }

    return SUCCESS;
}
