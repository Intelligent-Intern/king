static zend_result king_http3_execute_multi_requests(
    zval *return_value,
    zval *requests_array,
    const king_http3_request_options_t *options,
    const char *function_name)
{
    king_http3_multi_request_t *requests = NULL;
    size_t request_count;
    size_t i = 0;
    zval *request_entry;
    king_http3_request_runtime_t runtime;
    zend_long started_at_ms;
    zend_long connect_deadline_ms;
    zend_long overall_deadline_ms;

    request_count = zend_hash_num_elements(Z_ARRVAL_P(requests_array));
    if (request_count == 0) {
        king_http3_set_error("%s() requires at least one request definition.", function_name);
        return FAILURE;
    }

    requests = ecalloc(request_count, sizeof(*requests));
    memset(&runtime, 0, sizeof(runtime));
    runtime.socket_fd = -1;

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(requests_array), request_entry) {
        zval *url_value;
        zval *method_value;
        zval *headers_value;
        zval *body_value;

        if (Z_TYPE_P(request_entry) != IS_ARRAY) {
            king_http3_set_error(
                "%s() request #%zu must be provided as an array.",
                function_name,
                i + 1
            );
            goto failure;
        }

        url_value = zend_hash_str_find(
            Z_ARRVAL_P(request_entry),
            "url",
            sizeof("url") - 1
        );
        if (url_value == NULL || Z_TYPE_P(url_value) == IS_NULL) {
            king_http3_set_error(
                "%s() request #%zu requires a non-empty 'url'.",
                function_name,
                i + 1
            );
            goto failure;
        }

        requests[i].url = zval_get_string(url_value);
        if (ZSTR_LEN(requests[i].url) == 0) {
            king_http3_set_error(
                "%s() request #%zu requires a non-empty 'url'.",
                function_name,
                i + 1
            );
            goto failure;
        }

        if (king_http3_request_target_from_url(
                ZSTR_VAL(requests[i].url),
                ZSTR_LEN(requests[i].url),
                &requests[i].parsed_url,
                &requests[i].target,
                function_name
            ) != SUCCESS) {
            goto failure;
        }

        if (i > 0
            && !zend_string_equals(
                requests[0].target.authority,
                requests[i].target.authority
            )) {
            king_http3_set_error(
                "%s() currently requires all requests to target the same HTTP/3 origin and TLS profile.",
                function_name
            );
            goto failure;
        }

        method_value = zend_hash_str_find(
            Z_ARRVAL_P(request_entry),
            "method",
            sizeof("method") - 1
        );
        if (method_value == NULL || Z_TYPE_P(method_value) == IS_NULL) {
            requests[i].method = zend_string_init("GET", sizeof("GET") - 1, 0);
        } else {
            requests[i].method = zval_get_string(method_value);
        }

        if (king_http3_validate_method(
                ZSTR_VAL(requests[i].method),
                ZSTR_LEN(requests[i].method),
                function_name
            ) != SUCCESS) {
            goto failure;
        }

        headers_value = zend_hash_str_find(
            Z_ARRVAL_P(request_entry),
            "headers",
            sizeof("headers") - 1
        );
        if (headers_value != NULL
            && Z_TYPE_P(headers_value) != IS_NULL
            && Z_TYPE_P(headers_value) != IS_ARRAY) {
            king_http3_set_error(
                "%s() request #%zu option 'headers' must be provided as an array or null.",
                function_name,
                i + 1
            );
            goto failure;
        }

        body_value = zend_hash_str_find(
            Z_ARRVAL_P(request_entry),
            "body",
            sizeof("body") - 1
        );
        if (body_value != NULL && Z_TYPE_P(body_value) != IS_NULL) {
            requests[i].body_string = zval_get_string(body_value);
        }

        if (king_http3_prepare_request_headers(
                &requests[i].target,
                ZSTR_VAL(requests[i].method),
                ZSTR_LEN(requests[i].method),
                headers_value != NULL && Z_TYPE_P(headers_value) == IS_ARRAY ? headers_value : NULL,
                &requests[i].request_headers,
                &requests[i].request_header_count,
                &requests[i].owned_strings,
                &requests[i].owned_string_count,
                function_name
            ) != SUCCESS) {
            goto failure;
        }

        array_init(&requests[i].response.headers);
        requests[i].response.headers_initialized = true;
        requests[i].request_stream_id = UINT64_MAX;
        i++;
    } ZEND_HASH_FOREACH_END();

    if (king_http3_runtime_init(&runtime, &requests[0].target, options, function_name) != SUCCESS) {
        goto failure;
    }

    started_at_ms = king_http3_now_ms();
    connect_deadline_ms = started_at_ms + options->connect_timeout_ms;
    overall_deadline_ms = started_at_ms + options->timeout_ms;

    if (king_http3_flush_egress(&runtime, function_name) != SUCCESS) {
        goto failure;
    }

    while (!king_http3_multi_all_responses_complete(requests, request_count)) {
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
            king_http3_propagate_cancel_close(&runtime);
            goto failure;
        }

        if (now_ms >= overall_deadline_ms) {
            king_http3_throw(
                king_ce_timeout_exception,
                "%s() timed out while waiting for the HTTP/3 responses.",
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

        if (runtime.h3_conn != NULL) {
            bool egress_progress = false;

            for (i = 0; i < request_count; ++i) {
                int64_t stream_id;

                if (requests[i].request_headers_sent) {
                    continue;
                }

                stream_id = king_http3_quiche.quiche_h3_send_request_fn(
                    runtime.h3_conn,
                    runtime.conn,
                    requests[i].request_headers,
                    requests[i].request_header_count,
                    requests[i].body_string == NULL || ZSTR_LEN(requests[i].body_string) == 0
                );
                if (stream_id >= 0) {
                    requests[i].request_stream_id = (uint64_t) stream_id;
                    requests[i].request_headers_sent = true;
                    if (king_http3_runtime_is_in_early_data(&runtime)
                        && !king_http3_quiche.quiche_conn_is_established_fn(runtime.conn)) {
                        runtime.tls_request_sent_in_early_data = true;
                    }
                    egress_progress = true;
                    continue;
                }

                if (stream_id == QUICHE_H3_ERR_DONE || stream_id == QUICHE_H3_ERR_STREAM_BLOCKED) {
                    break;
                }

                king_http3_throw(
                    king_ce_protocol_exception,
                    "%s() failed to send HTTP/3 request headers for request #%zu (%" PRId64 ").",
                    function_name,
                    i + 1,
                    stream_id
                );
                goto failure;
            }

            for (i = 0; i < request_count; ++i) {
                ssize_t wrote;

                if (!requests[i].request_headers_sent
                    || requests[i].body_string == NULL
                    || requests[i].response.response_complete
                    || requests[i].body_offset >= ZSTR_LEN(requests[i].body_string)) {
                    continue;
                }

                wrote = king_http3_quiche.quiche_h3_send_body_fn(
                    runtime.h3_conn,
                    runtime.conn,
                    requests[i].request_stream_id,
                    (const uint8_t *) (ZSTR_VAL(requests[i].body_string) + requests[i].body_offset),
                    ZSTR_LEN(requests[i].body_string) - requests[i].body_offset,
                    true
                );

                if (wrote > 0) {
                    requests[i].body_offset += (size_t) wrote;
                    egress_progress = true;
                    continue;
                }

                if (wrote == QUICHE_H3_ERR_DONE || wrote == QUICHE_H3_ERR_STREAM_BLOCKED) {
                    continue;
                }

                king_http3_throw_multi_request_body_send_error(
                    function_name,
                    requests[i].url,
                    wrote
                );
                goto failure;
            }

            if (egress_progress && king_http3_flush_egress(&runtime, function_name) != SUCCESS) {
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
                king_http3_multi_request_t *request;

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

                request = king_http3_multi_find_request_by_stream_id(
                    requests,
                    request_count,
                    (uint64_t) stream_id
                );
                if (request == NULL) {
                    if (event != NULL) {
                        king_http3_quiche.quiche_h3_event_free_fn(event);
                    }
                    continue;
                }

                switch (king_http3_quiche.quiche_h3_event_type_fn(event)) {
                    case QUICHE_H3_EVENT_HEADERS: {
                        king_http3_response_header_context_t context;
                        int rc;

                        context.response = &request->response;
                        rc = king_http3_quiche.quiche_h3_event_for_each_header_fn(
                            event,
                            king_http3_collect_response_header,
                            &context
                        );
                        if (rc != 0) {
                            king_http3_quiche.quiche_h3_event_free_fn(event);
                            if (request->response.header_overflowed) {
                                king_http3_throw(
                                    king_ce_protocol_exception,
                                    "%s() exceeded the active HTTP/3 response-header size limit.",
                                    function_name
                                );
                            } else {
                                king_http3_throw(
                                    king_ce_protocol_exception,
                                    "%s() failed while decoding HTTP/3 response headers.",
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
                                request->request_stream_id,
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
                                    "%s() failed while decoding an HTTP/3 response body (%zd).",
                                    function_name,
                                    read
                                );
                                goto failure;
                            }

                            request->response.body_bytes += (size_t) read;
                            if (request->response.body_bytes > KING_HTTP3_MAX_RESPONSE_BYTES) {
                                request->response.body_overflowed = true;
                                king_http3_quiche.quiche_h3_event_free_fn(event);
                                king_http3_throw(
                                    king_ce_protocol_exception,
                                    "%s() exceeded the active HTTP/3 response-body size limit.",
                                    function_name
                                );
                                goto failure;
                            }

                            smart_str_appendl(&request->response.body, (const char *) buffer, (size_t) read);
                        }
                        break;
                    }

                    case QUICHE_H3_EVENT_FINISHED:
                        request->response.response_complete = true;
                        break;

                    case QUICHE_H3_EVENT_RESET:
                        king_http3_throw_multi_request_stream_reset(
                            function_name,
                            request->url,
                            event
                        );
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

        if (king_http3_multi_all_responses_complete(requests, request_count)) {
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

    if (king_http3_quiche.quiche_conn_close_fn != NULL) {
        king_http3_capture_post_response_tls_state(&runtime);
        king_http3_quiche.quiche_conn_close_fn(runtime.conn, true, 0, NULL, 0);
        king_http3_flush_egress_best_effort(&runtime);
    }

    array_init(return_value);
    for (i = 0; i < request_count; ++i) {
        zval response_zv;

        ZVAL_UNDEF(&response_zv);
        if (king_http3_materialize_response(
                &response_zv,
                &requests[i].response,
                &runtime,
                ZSTR_VAL(requests[i].url)
            ) != SUCCESS) {
            if (!Z_ISUNDEF(response_zv)) {
                zval_ptr_dtor(&response_zv);
            }
            goto failure;
        }

        smart_str_free(&requests[i].response.body);
        add_next_index_zval(return_value, &response_zv);
    }

    king_http3_runtime_destroy(&runtime);
    king_http3_multi_requests_destroy(requests, request_count);
    king_set_error("");
    return SUCCESS;

failure:
    king_http3_runtime_destroy(&runtime);
    king_http3_multi_requests_destroy(requests, request_count);
    return FAILURE;
}
