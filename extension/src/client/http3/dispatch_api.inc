static zend_result king_http3_execute_request(
    zval *return_value,
    const char *url_str,
    const char *method_str,
    size_t method_len,
    zval *headers_array,
    zend_string *body_string,
    const king_http3_request_options_t *options,
    const char *function_name)
{
    php_url *parsed_url = NULL;
    king_http3_request_target_t target;
    king_http3_request_runtime_t runtime;
    king_http3_response_t response;
    quiche_h3_header *request_headers = NULL;
    zend_string **owned_strings = NULL;
    size_t request_header_count = 0;
    size_t owned_string_count = 0;
    zend_long started_at_ms;
    zend_long connect_deadline_ms;
    zend_long overall_deadline_ms;
    uint64_t request_stream_id = UINT64_MAX;
    size_t body_offset = 0;
    bool request_headers_sent = false;

    memset(&target, 0, sizeof(target));
    memset(&runtime, 0, sizeof(runtime));
    runtime.socket_fd = -1;
    memset(&response, 0, sizeof(response));
    array_init(&response.headers);
    response.headers_initialized = true;

    if (king_http3_request_target_from_url(
            url_str,
            strlen(url_str),
            &parsed_url,
            &target,
            function_name
        ) != SUCCESS) {
        goto failure;
    }

    if (king_http3_runtime_init(&runtime, &target, options, function_name) != SUCCESS) {
        goto failure;
    }

    if (king_http3_prepare_request_headers(
            &target,
            method_str,
            method_len,
            headers_array,
            &request_headers,
            &request_header_count,
            &owned_strings,
            &owned_string_count,
            function_name
        ) != SUCCESS) {
        if (EG(exception) == NULL && king_get_error()[0] != '\0') {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", king_get_error());
        }
        goto failure;
    }

    started_at_ms = king_http3_now_ms();
    connect_deadline_ms = started_at_ms + options->connect_timeout_ms;
    overall_deadline_ms = started_at_ms + options->timeout_ms;

    if (king_http3_flush_egress(&runtime, function_name) != SUCCESS) {
        goto failure;
    }

    while (!response.response_complete) {
        struct pollfd pfd;
        zend_long now_ms = king_http3_now_ms();
        zend_long remaining_overall_ms;
        zend_long remaining_connect_ms;
        zend_long poll_timeout_ms;
        int poll_result;
        uint64_t quiche_timeout_ms;

        king_process_pending_interrupts();
        if (EG(exception) != NULL) {
            goto failure;
        }
        if (king_transport_maybe_throw_cancel(
                options->cancel_token,
                function_name,
                options->cancel_function_name,
                options->cancel_exception_ce,
                "HTTP/3"
            ) != SUCCESS) {
            goto failure;
        }

        if (now_ms >= overall_deadline_ms) {
            king_http3_throw(
                king_ce_timeout_exception,
                "%s() timed out while waiting for the HTTP/3 response.",
                function_name
            );
            goto failure;
        }

        if (!king_http3_quiche.quiche_conn_is_established_fn(runtime.conn)
            && now_ms >= connect_deadline_ms) {
            king_http3_throw(
                king_ce_timeout_exception,
                "%s() timed out while establishing the QUIC connection.",
                function_name
            );
            goto failure;
        }

        if (king_http3_runtime_can_open_h3(&runtime) && runtime.h3_conn == NULL) {
            runtime.h3_config = king_http3_quiche.quiche_h3_config_new_fn();
            if (runtime.h3_config == NULL) {
                king_http3_throw(
                    king_ce_system_exception,
                    "%s() failed to allocate the active HTTP/3 config.",
                    function_name
                );
                goto failure;
            }

            runtime.h3_conn = king_http3_quiche.quiche_h3_conn_new_with_transport_fn(runtime.conn, runtime.h3_config);
            if (runtime.h3_conn == NULL) {
                king_http3_throw(
                    king_ce_protocol_exception,
                    "%s() failed to initialize the HTTP/3 layer on the active QUIC connection.",
                    function_name
                );
                goto failure;
            }
        }

        if (runtime.h3_conn != NULL && !request_headers_sent) {
            int64_t stream_id = king_http3_quiche.quiche_h3_send_request_fn(
                runtime.h3_conn,
                runtime.conn,
                request_headers,
                request_header_count,
                body_string == NULL || ZSTR_LEN(body_string) == 0
            );
            if (stream_id < 0) {
                king_http3_throw(
                    king_ce_protocol_exception,
                    "%s() failed to send the active HTTP/3 request headers (%" PRId64 ").",
                    function_name,
                    stream_id
                );
                goto failure;
            }

            request_stream_id = (uint64_t) stream_id;
            request_headers_sent = true;
            if (king_http3_runtime_is_in_early_data(&runtime)
                && !king_http3_quiche.quiche_conn_is_established_fn(runtime.conn)) {
                runtime.tls_request_sent_in_early_data = true;
            }

            if (king_http3_flush_egress(&runtime, function_name) != SUCCESS) {
                goto failure;
            }
        }

        if (runtime.h3_conn != NULL
            && request_headers_sent
            && body_string != NULL
            && body_offset < ZSTR_LEN(body_string)) {
            ssize_t wrote = king_http3_quiche.quiche_h3_send_body_fn(
                runtime.h3_conn,
                runtime.conn,
                request_stream_id,
                (const uint8_t *) (ZSTR_VAL(body_string) + body_offset),
                ZSTR_LEN(body_string) - body_offset,
                true
            );

            if (wrote > 0) {
                body_offset += (size_t) wrote;
                if (king_http3_flush_egress(&runtime, function_name) != SUCCESS) {
                    goto failure;
                }
            } else if (wrote != QUICHE_H3_ERR_DONE && wrote != QUICHE_H3_ERR_STREAM_BLOCKED) {
                king_http3_throw_request_body_send_error(function_name, wrote);
                goto failure;
            }
        }

        if (runtime.h3_conn != NULL) {
            for (;;) {
                quiche_h3_event *event = NULL;
                int64_t stream_id = king_http3_quiche.quiche_h3_conn_poll_fn(
                    runtime.h3_conn,
                    runtime.conn,
                    &event
                );

                if (stream_id == QUICHE_H3_ERR_DONE) {
                    break;
                }

                if (stream_id < 0) {
                    king_http3_throw(
                        king_ce_protocol_exception,
                        "%s() failed while polling the active HTTP/3 layer (%" PRId64 ").",
                        function_name,
                        stream_id
                    );
                    if (event != NULL) {
                        king_http3_quiche.quiche_h3_event_free_fn(event);
                    }
                    goto failure;
                }

                if ((uint64_t) stream_id != request_stream_id) {
                    if (event != NULL) {
                        king_http3_quiche.quiche_h3_event_free_fn(event);
                    }
                    continue;
                }

                switch (king_http3_quiche.quiche_h3_event_type_fn(event)) {
                    case QUICHE_H3_EVENT_HEADERS: {
                        king_http3_response_header_context_t context;
                        int rc;

                        context.response = &response;
                        rc = king_http3_quiche.quiche_h3_event_for_each_header_fn(
                            event,
                            king_http3_collect_response_header,
                            &context
                        );
                        if (rc != 0) {
                            king_http3_quiche.quiche_h3_event_free_fn(event);
                            if (response.header_overflowed) {
                                king_http3_throw(
                                    king_ce_protocol_exception,
                                    "%s() exceeded the active HTTP/3 response-header size limit.",
                                    function_name
                                );
                            } else {
                                king_http3_throw(
                                    king_ce_protocol_exception,
                                    "%s() failed while decoding the active HTTP/3 response headers.",
                                    function_name
                                );
                            }
                            goto failure;
                        }
                        break;
                    }

                    case QUICHE_H3_EVENT_DATA: {
                        uint8_t buffer[4096];

                        for (;;) {
                            ssize_t read = king_http3_quiche.quiche_h3_recv_body_fn(
                                runtime.h3_conn,
                                runtime.conn,
                                request_stream_id,
                                buffer,
                                sizeof(buffer)
                            );

                            if (read == QUICHE_H3_ERR_DONE || read == 0) {
                                break;
                            }

                            if (read < 0) {
                                king_http3_quiche.quiche_h3_event_free_fn(event);
                                king_http3_throw(
                                    king_ce_protocol_exception,
                                    "%s() failed while decoding the active HTTP/3 response body (%zd).",
                                    function_name,
                                    read
                                );
                                goto failure;
                            }

                            response.body_bytes += (size_t) read;
                            if (response.body_bytes > KING_HTTP3_MAX_RESPONSE_BYTES) {
                                response.body_overflowed = true;
                                king_http3_quiche.quiche_h3_event_free_fn(event);
                                king_http3_throw(
                                    king_ce_protocol_exception,
                                    "%s() exceeded the active HTTP/3 response-body size limit.",
                                    function_name
                                );
                                goto failure;
                            }

                            smart_str_appendl(&response.body, (const char *) buffer, (size_t) read);
                        }
                        break;
                    }

                    case QUICHE_H3_EVENT_FINISHED:
                        response.response_complete = true;
                        break;

                    case QUICHE_H3_EVENT_RESET:
                        king_http3_throw_active_request_stream_reset(function_name, event);
                        king_http3_quiche.quiche_h3_event_free_fn(event);
                        goto failure;

                    case QUICHE_H3_EVENT_GOAWAY:
                    case QUICHE_H3_EVENT_PRIORITY_UPDATE:
                    default:
                        break;
                }

                if (event != NULL) {
                    king_http3_quiche.quiche_h3_event_free_fn(event);
                }
            }
        }

        king_http3_refresh_tls_state(&runtime);

        if (response.response_complete) {
            break;
        }

        if (king_http3_quiche.quiche_conn_is_closed_fn(runtime.conn)) {
            king_http3_throw_closed_connection(runtime.conn, function_name);
            goto failure;
        }

        memset(&pfd, 0, sizeof(pfd));
        pfd.fd = runtime.socket_fd;
        pfd.events = POLLIN;

        remaining_overall_ms = overall_deadline_ms - now_ms;
        remaining_connect_ms = connect_deadline_ms - now_ms;
        quiche_timeout_ms = king_http3_quiche.quiche_conn_timeout_as_millis_fn(runtime.conn);
        poll_timeout_ms = (zend_long) quiche_timeout_ms;
        if (poll_timeout_ms <= 0 || poll_timeout_ms > remaining_overall_ms) {
            poll_timeout_ms = remaining_overall_ms;
        }
        if (!king_http3_quiche.quiche_conn_is_established_fn(runtime.conn)
            && remaining_connect_ms > 0
            && poll_timeout_ms > remaining_connect_ms) {
            poll_timeout_ms = remaining_connect_ms;
        }
        if (poll_timeout_ms < 0) {
            poll_timeout_ms = 0;
        }
        if (poll_timeout_ms > KING_TRANSPORT_INTERRUPT_SLICE_MS) {
            poll_timeout_ms = KING_TRANSPORT_INTERRUPT_SLICE_MS;
        }

        poll_result = poll(&pfd, 1, (int) poll_timeout_ms);
        if (poll_result < 0) {
            if (errno == EINTR) {
                continue;
            }
            king_http3_throw(
                king_ce_network_exception,
                "%s() failed while polling the active HTTP/3 UDP socket (errno %d).",
                function_name,
                errno
            );
            goto failure;
        }

        if (poll_result == 0) {
            king_http3_quiche.quiche_conn_on_timeout_fn(runtime.conn);
            if (king_http3_flush_egress(&runtime, function_name) != SUCCESS) {
                goto failure;
            }
            king_http3_refresh_tls_state(&runtime);
            continue;
        }

        if ((pfd.revents & POLLIN) != 0) {
            for (;;) {
                uint8_t buffer[65535];
                struct sockaddr_storage from;
                socklen_t from_len = sizeof(from);
                ssize_t received = recvfrom(
                    runtime.socket_fd,
                    buffer,
                    sizeof(buffer),
                    0,
                    (struct sockaddr *) &from,
                    &from_len
                );

                if (received < 0) {
                    if (errno == EAGAIN || errno == EWOULDBLOCK) {
                        break;
                    }

                    king_http3_throw(
                        king_ce_network_exception,
                        "%s() failed while receiving an HTTP/3 datagram (errno %d).",
                        function_name,
                        errno
                    );
                    goto failure;
                }

                {
                    quiche_recv_info recv_info;
                    ssize_t done;

                    recv_info.from = (struct sockaddr *) &from;
                    recv_info.from_len = from_len;
                    recv_info.to = (struct sockaddr *) &runtime.local_addr;
                    recv_info.to_len = runtime.local_addr_len;

                    done = king_http3_quiche.quiche_conn_recv_fn(
                        runtime.conn,
                        buffer,
                        (size_t) received,
                        &recv_info
                    );
                    if (done < 0 && done != QUICHE_ERR_DONE) {
                        king_http3_throw_recv_error(runtime.conn, function_name, done);
                        goto failure;
                    }
                }
            }
        }

        if (king_http3_flush_egress(&runtime, function_name) != SUCCESS) {
            goto failure;
        }
        king_http3_refresh_tls_state(&runtime);
    }

    if (!response.response_complete) {
        king_http3_throw(
            king_ce_protocol_exception,
            "%s() did not receive a complete HTTP/3 response.",
            function_name
        );
        goto failure;
    }

    if (king_http3_quiche.quiche_conn_close_fn != NULL) {
        king_http3_capture_post_response_tls_state(&runtime);
        king_http3_quiche.quiche_conn_close_fn(runtime.conn, true, 0, NULL, 0);
        king_http3_flush_egress_best_effort(&runtime);
    }

    if (king_http3_materialize_response(return_value, &response, &runtime, url_str) != SUCCESS) {
        goto failure;
    }

    king_http3_free_request_headers(request_headers, owned_strings, owned_string_count);
    king_http3_request_target_destroy(parsed_url, &target);
    king_http3_runtime_destroy(&runtime);
    smart_str_free(&response.body);
    king_set_error("");
    return SUCCESS;

failure:
    king_http3_free_request_headers(request_headers, owned_strings, owned_string_count);
    king_http3_request_target_destroy(parsed_url, &target);
    king_http3_runtime_destroy(&runtime);
    king_http3_response_destroy(&response);
    return FAILURE;
}

zend_result king_http3_request_dispatch(
    zval *return_value,
    const char *url_str,
    size_t url_len,
    const char *method_str,
    size_t method_len,
    zval *headers_array,
    zval *body_zval,
    zval *options_array,
    const char *function_name)
{
    king_http3_request_options_t options;
    zend_string *body_string = NULL;
    zend_result result;

    if (url_len == 0) {
        king_http3_set_error("%s() requires a non-empty URL.", function_name);
        return FAILURE;
    }

    if (king_http3_validate_method(method_str, method_len, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (king_http3_parse_options(options_array, &options, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (king_http3_ensure_quiche_ready() != SUCCESS) {
        king_http3_throw(
            king_ce_protocol_exception,
            "%s() HTTP/3 runtime is unavailable: %s",
            function_name,
            king_http3_quiche.load_error
        );
        return FAILURE;
    }

    if (body_zval != NULL && Z_TYPE_P(body_zval) != IS_NULL) {
        body_string = zval_get_string(body_zval);
    }

    result = king_http3_execute_request(
        return_value,
        url_str,
        method_str,
        method_len,
        headers_array,
        body_string,
        &options,
        function_name
    );

    if (body_string != NULL) {
        zend_string_release(body_string);
    }

    return result;
}

static zend_result king_http3_multi_request_dispatch(
    zval *return_value,
    zval *requests_array,
    zval *options_array,
    const char *function_name)
{
    king_http3_request_options_t options;

    if (king_http3_parse_options(options_array, &options, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (king_http3_ensure_quiche_ready() != SUCCESS) {
        king_http3_throw(
            king_ce_protocol_exception,
            "%s() HTTP/3 runtime is unavailable: %s",
            function_name,
            king_http3_quiche.load_error
        );
        return FAILURE;
    }

    return king_http3_execute_multi_requests(
        return_value,
        requests_array,
        &options,
        function_name
    );
}

PHP_FUNCTION(king_http3_request_send)
{
    char *url_str;
    size_t url_len;
    char *method_str = "GET";
    size_t method_len = sizeof("GET") - 1;
    zval *headers_array = NULL;
    zval *body_zval = NULL;
    zval *options_array = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 5)
        Z_PARAM_STRING(url_str, url_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING(method_str, method_len)
        Z_PARAM_ARRAY_OR_NULL(headers_array)
        Z_PARAM_ZVAL_OR_NULL(body_zval)
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    if (king_http3_request_dispatch(
            return_value,
            url_str,
            url_len,
            method_str,
            method_len,
            headers_array,
            body_zval,
            options_array,
            "king_http3_request_send"
        ) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }
}

PHP_FUNCTION(king_http3_request_send_multi)
{
    zval *requests_array;
    zval *options_array = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(requests_array)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    if (king_http3_multi_request_dispatch(
            return_value,
            requests_array,
            options_array,
            "king_http3_request_send_multi"
        ) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }
}
