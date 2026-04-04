/*
 * =========================================================================
 * FILENAME:   src/client/http1/request/dispatch.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Core HTTP/1 request dispatcher. This slice owns absolute-URL validation,
 * request assembly, socket acquisition, buffered vs. `response_stream`
 * branching, redirect replay, chunked decoding, and the final normalized
 * response payload returned to higher client surfaces.
 * =========================================================================
 */

static king_http1_request_context *king_http1_request_context_create(
    int fd,
    char *origin,
    zend_string *effective_url,
    zend_long timeout_ms,
    bool request_allows_keep_alive,
    bool expect_no_body)
{
    king_http1_request_context *context;

    context = ecalloc(1, sizeof(*context));
    context->fd = fd;
    context->origin = origin;
    context->effective_url = zend_string_copy(effective_url);
    context->timeout_ms = timeout_ms;
    context->request_allows_keep_alive = request_allows_keep_alive;
    context->expect_no_body = expect_no_body;
    ZVAL_UNDEF(&context->pending_early_hints);

    return context;
}

zend_result king_http1_request_dispatch(
    zval *return_value,
    const char *url_str,
    size_t url_len,
    const char *method_str,
    size_t method_len,
    zval *headers_array,
    zval *body_zval,
    zval *options_array,
    const char *function_name
)
{
    php_url *parsed_url = NULL;
    smart_str request_target = {0};
    smart_str request_buffer = {0};
    smart_str response_buffer = {0};
    smart_str decoded_body = {0};
    zend_string *current_url = NULL;
    zend_string *current_method = NULL;
    zend_string *current_body = NULL;
    zend_string *redirect_url = NULL;
    char *origin = NULL;
    king_http1_request_options_t options;
    king_http1_response_meta_t response_meta;
    zend_long port;
    zend_long redirect_count = 0;
    bool current_body_was_supplied = false;
    bool drop_body_headers = false;
    bool request_allows_keep_alive = true;
    int fd = -1;
    char host_header[NI_MAXHOST + 16];
    zval effective_headers;
    zval *request_headers_array = headers_array;

    memset(&response_meta, 0, sizeof(response_meta));
    ZVAL_UNDEF(&effective_headers);

    if (url_len == 0) {
        king_http1_set_error("%s() requires a non-empty URL.", function_name);
        return FAILURE;
    }

    if (king_http1_validate_method(method_str, method_len, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (king_http1_parse_options(options_array, &options, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (king_telemetry_has_injectable_current_context()) {
        if (king_telemetry_inject_current_context_headers(&effective_headers, headers_array) != SUCCESS) {
            goto cleanup;
        }
        request_headers_array = &effective_headers;
    }

    if (king_http1_request_allows_keep_alive(request_headers_array, &request_allows_keep_alive) != SUCCESS) {
        goto cleanup;
    }

    current_url = zend_string_init(url_str, url_len, 0);
    current_method = zend_string_init(method_str, method_len, 0);

    if (body_zval != NULL && Z_TYPE_P(body_zval) != IS_NULL) {
        current_body = zval_get_string(body_zval);
        current_body_was_supplied = true;
    }

    while (1) {
        size_t header_end;
        char *body_ptr;
        size_t body_len;
        zval *location_header;
        bool expect_no_body;
        bool keep_alive_reusable;

        parsed_url = php_url_parse_ex(ZSTR_VAL(current_url), ZSTR_LEN(current_url));
        if (parsed_url == NULL || parsed_url->scheme == NULL || parsed_url->host == NULL) {
            if (parsed_url != NULL) {
                php_url_free(parsed_url);
                parsed_url = NULL;
            }

            king_http1_set_error(
                "%s() requires a valid absolute http:// URL.",
                function_name
            );
            goto cleanup;
        }

        if (strcasecmp(ZSTR_VAL(parsed_url->scheme), "https") == 0) {
            king_http1_throw(
                king_ce_tls_exception,
                "%s() HTTPS requests are not available in the active runtime HTTP/1 runtime.",
                function_name
            );
            goto cleanup;
        }

        if (strcasecmp(ZSTR_VAL(parsed_url->scheme), "http") != 0) {
            king_http1_set_error(
                "%s() currently supports only absolute http:// URLs.",
                function_name
            );
            goto cleanup;
        }

        if (parsed_url->user != NULL || parsed_url->pass != NULL) {
            king_http1_set_error(
                "%s() does not support embedding credentials in the request URL.",
                function_name
            );
            goto cleanup;
        }

        if (king_http1_prepare_request_target(parsed_url, &request_target, function_name) != SUCCESS) {
            goto cleanup;
        }

        port = parsed_url->port > 0 ? parsed_url->port : 80;
        expect_no_body = king_http1_method_expects_no_response_body(
            ZSTR_VAL(current_method),
            ZSTR_LEN(current_method)
        );

        if (king_http1_build_origin_key(parsed_url, port, &options, &origin) != SUCCESS) {
            king_http1_set_error("%s() failed to build an HTTP/1 origin key.", function_name);
            goto cleanup;
        }

        if (strchr(ZSTR_VAL(parsed_url->host), ':') != NULL) {
            if (port == 80) {
                snprintf(host_header, sizeof(host_header), "[%s]", ZSTR_VAL(parsed_url->host));
            } else {
                snprintf(host_header, sizeof(host_header), "[%s]:%ld", ZSTR_VAL(parsed_url->host), port);
            }
        } else if (port == 80) {
            snprintf(host_header, sizeof(host_header), "%s", ZSTR_VAL(parsed_url->host));
        } else {
            snprintf(host_header, sizeof(host_header), "%s:%ld", ZSTR_VAL(parsed_url->host), port);
        }

        smart_str_append(&request_buffer, current_method);
        smart_str_appends(&request_buffer, " ");
        smart_str_appendl(
            &request_buffer,
            ZSTR_VAL(request_target.s),
            ZSTR_LEN(request_target.s)
        );
        smart_str_appends(&request_buffer, " HTTP/1.1\r\n");

        if (king_http1_append_request_headers(
                &request_buffer,
                request_headers_array,
                current_body,
                current_body_was_supplied,
                drop_body_headers,
                host_header,
                strlen(host_header),
                function_name
            ) != SUCCESS) {
            goto cleanup;
        }

        smart_str_appends(&request_buffer, "\r\n");
        if (current_body != NULL) {
            smart_str_append(&request_buffer, current_body);
        }
        smart_str_0(&request_buffer);

        fd = request_allows_keep_alive
            ? king_http1_pool_take_socket(origin, &options)
            : -1;
        if (fd < 0) {
            if (king_http1_open_socket(
                    ZSTR_VAL(parsed_url->host),
                    port,
                    &options,
                    function_name,
                    &fd
                ) != SUCCESS) {
                goto cleanup;
            }
        }

        if (king_http1_send_all(
                fd,
                ZSTR_VAL(request_buffer.s),
                ZSTR_LEN(request_buffer.s),
                &options,
                function_name
            ) != SUCCESS) {
            goto cleanup;
        }

        if (options.response_stream) {
            king_http1_request_context *context;
            zend_resource *resource;

            context = king_http1_request_context_create(
                fd,
                origin,
                current_url,
                options.timeout_ms,
                request_allows_keep_alive,
                expect_no_body
            );
            fd = -1;
            origin = NULL;

            if (king_http1_request_context_ensure_headers(
                    context,
                    false,
                    function_name,
                    options.cancel_token,
                    options.cancel_function_name,
                    options.cancel_exception_ce
                ) != SUCCESS) {
                king_http1_request_context_free(context);
                goto cleanup;
            }

            location_header = context->meta.headers_initialized
                ? zend_hash_str_find(
                    Z_ARRVAL(context->meta.headers),
                    "location",
                    sizeof("location") - 1
                )
                : NULL;

            if (
                options.follow_redirects
                && king_http1_status_is_redirect(context->meta.status_code)
                && location_header != NULL
            ) {
                if (redirect_count >= options.max_redirects) {
                    king_http1_request_context_free(context);
                    king_http1_throw(
                        king_ce_protocol_exception,
                        "%s() exceeded the configured HTTP/1 redirect hop limit.",
                        function_name
                    );
                    goto cleanup;
                }

                if (Z_TYPE_P(location_header) != IS_STRING || Z_STRLEN_P(location_header) == 0) {
                    king_http1_request_context_free(context);
                    king_http1_throw(
                        king_ce_protocol_exception,
                        "%s() received a malformed HTTP/1 redirect Location header.",
                        function_name
                    );
                    goto cleanup;
                }

                if (king_http1_request_context_ensure_body(
                        context,
                        0,
                        true,
                        function_name,
                        options.cancel_token,
                        options.cancel_function_name,
                        options.cancel_exception_ce
                    ) != SUCCESS) {
                    king_http1_request_context_free(context);
                    goto cleanup;
                }

                if (king_http1_build_redirect_url(
                        parsed_url,
                        Z_STRVAL_P(location_header),
                        Z_STRLEN_P(location_header),
                        &redirect_url,
                        function_name
                    ) != SUCCESS) {
                    king_http1_request_context_free(context);
                    goto cleanup;
                }

                redirect_count++;

                if (king_http1_redirect_rewrites_to_get(
                        context->meta.status_code,
                        ZSTR_VAL(current_method),
                        ZSTR_LEN(current_method)
                    )) {
                    zend_string_release(current_method);
                    current_method = zend_string_init("GET", sizeof("GET") - 1, 0);

                    if (current_body != NULL) {
                        zend_string_release(current_body);
                        current_body = NULL;
                    }

                    current_body_was_supplied = false;
                    drop_body_headers = true;
                }

                zend_string_release(current_url);
                current_url = redirect_url;
                redirect_url = NULL;

                king_http1_request_context_free(context);
                context = NULL;

                if (parsed_url != NULL) {
                    php_url_free(parsed_url);
                    parsed_url = NULL;
                }

                smart_str_free(&request_target);
                smart_str_free(&request_buffer);
                continue;
            }

            resource = zend_register_resource(context, le_king_request_context);
            if (resource == NULL) {
                king_http1_request_context_free(context);
                king_http1_throw(
                    king_ce_system_exception,
                    "%s() failed to allocate an HTTP/1 request context resource.",
                    function_name
                );
                goto cleanup;
            }

            ZVAL_RES(return_value, resource);
            king_set_error("");
            break;
        }

        if (king_http1_recv_response(
                fd,
                &response_buffer,
                &response_meta,
                expect_no_body,
                &options,
                function_name
            ) != SUCCESS) {
            goto cleanup;
        }

        header_end = king_http1_find_header_end(
            ZSTR_VAL(response_buffer.s),
            ZSTR_LEN(response_buffer.s)
        );
        if (header_end == 0) {
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() did not receive a complete HTTP/1 response header block.",
                function_name
            );
            goto cleanup;
        }

        location_header = response_meta.headers_initialized
            ? zend_hash_str_find(
                Z_ARRVAL(response_meta.headers),
                "location",
                sizeof("location") - 1
            )
            : NULL;
        keep_alive_reusable = request_allows_keep_alive
            && king_http1_response_allows_keep_alive(&response_meta)
            && king_http1_response_is_self_delimited(&response_meta, expect_no_body);

        if (
            options.follow_redirects
            && king_http1_status_is_redirect(response_meta.status_code)
            && location_header != NULL
        ) {
            if (redirect_count >= options.max_redirects) {
                king_http1_throw(
                    king_ce_protocol_exception,
                    "%s() exceeded the configured HTTP/1 redirect hop limit.",
                    function_name
                );
                goto cleanup;
            }

            if (Z_TYPE_P(location_header) != IS_STRING || Z_STRLEN_P(location_header) == 0) {
                king_http1_throw(
                    king_ce_protocol_exception,
                    "%s() received a malformed HTTP/1 redirect Location header.",
                    function_name
                );
                goto cleanup;
            }

            if (king_http1_build_redirect_url(
                    parsed_url,
                    Z_STRVAL_P(location_header),
                    Z_STRLEN_P(location_header),
                    &redirect_url,
                    function_name
                ) != SUCCESS) {
                goto cleanup;
            }

            redirect_count++;

            if (king_http1_redirect_rewrites_to_get(
                    response_meta.status_code,
                    ZSTR_VAL(current_method),
                    ZSTR_LEN(current_method)
                )) {
                zend_string_release(current_method);
                current_method = zend_string_init("GET", sizeof("GET") - 1, 0);

                if (current_body != NULL) {
                    zend_string_release(current_body);
                    current_body = NULL;
                }

                current_body_was_supplied = false;
                drop_body_headers = true;
            }

            zend_string_release(current_url);
            current_url = redirect_url;
            redirect_url = NULL;

            if (fd >= 0) {
                if (keep_alive_reusable) {
                    king_http1_pool_store_socket(origin, fd);
                } else {
                    close(fd);
                }
                fd = -1;
            }
            if (parsed_url != NULL) {
                php_url_free(parsed_url);
                parsed_url = NULL;
            }
            if (origin != NULL) {
                efree(origin);
                origin = NULL;
            }

            king_http1_response_meta_destroy(&response_meta);
            smart_str_free(&request_target);
            smart_str_free(&request_buffer);
            smart_str_free(&response_buffer);
            continue;
        }

        body_ptr = ZSTR_VAL(response_buffer.s) + header_end;
        body_len = ZSTR_LEN(response_buffer.s) - header_end;
        if (
            (expect_no_body || king_http1_status_has_no_body(response_meta.status_code))
            && body_len > 0
        ) {
            keep_alive_reusable = false;
        }

        array_init(return_value);
        add_assoc_long(return_value, "status", response_meta.status_code);
        add_assoc_str(return_value, "status_line", response_meta.status_line);
        response_meta.status_line = NULL;
        add_assoc_zval(return_value, "headers", &response_meta.headers);
        response_meta.headers_initialized = false;
        if (expect_no_body || king_http1_status_has_no_body(response_meta.status_code)) {
            add_assoc_string(return_value, "body", "");
        } else if (response_meta.chunked_transfer_encoding) {
            if (king_http1_decode_chunked_body(
                    ZSTR_VAL(response_buffer.s),
                    ZSTR_LEN(response_buffer.s),
                    header_end,
                    &decoded_body,
                    function_name
                ) != SUCCESS) {
                zval_ptr_dtor(return_value);
                ZVAL_UNDEF(return_value);
                goto cleanup;
            }

            add_assoc_stringl(
                return_value,
                "body",
                decoded_body.s != NULL ? ZSTR_VAL(decoded_body.s) : "",
                decoded_body.s != NULL ? ZSTR_LEN(decoded_body.s) : 0
            );
        } else {
            add_assoc_stringl(return_value, "body", body_ptr, body_len);
        }
        add_assoc_string(return_value, "protocol", "http/1.1");
        add_assoc_string(return_value, "transport_backend", "tcp_socket");
        add_assoc_stringl(
            return_value,
            "effective_url",
            ZSTR_VAL(current_url),
            ZSTR_LEN(current_url)
        );
        if (fd >= 0) {
            if (keep_alive_reusable) {
                king_http1_pool_store_socket(origin, fd);
            } else {
                close(fd);
            }
            fd = -1;
        }
        king_set_error("");
        break;
    }

cleanup:
    if (fd >= 0) {
        close(fd);
    }
    if (parsed_url != NULL) {
        php_url_free(parsed_url);
    }
    if (current_url != NULL) {
        zend_string_release(current_url);
    }
    if (current_method != NULL) {
        zend_string_release(current_method);
    }
    if (current_body != NULL) {
        zend_string_release(current_body);
    }
    if (redirect_url != NULL) {
        zend_string_release(redirect_url);
    }
    if (origin != NULL) {
        efree(origin);
    }
    if (Z_TYPE(effective_headers) != IS_UNDEF) {
        zval_ptr_dtor(&effective_headers);
    }
    king_http1_response_meta_destroy(&response_meta);
    smart_str_free(&decoded_body);
    smart_str_free(&request_target);
    smart_str_free(&request_buffer);
    smart_str_free(&response_buffer);

    return EG(exception) == NULL
        && (Z_TYPE_P(return_value) == IS_ARRAY || Z_TYPE_P(return_value) == IS_RESOURCE)
        ? SUCCESS
        : FAILURE;
}
