#if defined(KING_HTTP3_BACKEND_LSQUIC)
static zend_result king_http3_execute_request_lsquic(
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
    zval effective_headers;
    zval *request_headers_array = headers_array;

    memset(&target, 0, sizeof(target));
    memset(&runtime, 0, sizeof(runtime));
    runtime.socket_fd = -1;
    memset(&response, 0, sizeof(response));
    array_init(&response.headers);
    response.headers_initialized = true;
    ZVAL_UNDEF(&effective_headers);

    if (king_http3_request_target_from_url(
            url_str,
            strlen(url_str),
            &parsed_url,
            &target,
            function_name
        ) != SUCCESS) {
        goto failure;
    }

    if (king_http3_runtime_open_udp_socket(&runtime, &target, function_name) != SUCCESS) {
        goto failure;
    }

    if (king_telemetry_has_injectable_current_context()) {
        if (king_telemetry_inject_current_context_headers(&effective_headers, headers_array) != SUCCESS) {
            goto failure;
        }
        request_headers_array = &effective_headers;
    }

    if (king_http3_prepare_request_headers(
            &target,
            method_str,
            method_len,
            request_headers_array,
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

    if (king_http3_lsquic_runtime_init(&runtime, &target, options, function_name) != SUCCESS) {
        goto failure;
    }

    if (king_http3_lsquic_runtime_prepare_request(
            &runtime,
            request_headers,
            request_header_count,
            body_string,
            &response,
            function_name
        ) != SUCCESS) {
        goto failure;
    }

    while (!response.response_complete) {
        struct pollfd pfd;
        zend_long now_ms = king_http3_now_ms();
        zend_long remaining_overall_ms;
        zend_long remaining_connect_ms;
        zend_long poll_timeout_ms;
        zend_long lsquic_timeout_ms;
        int poll_result;

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

        if (runtime.lsquic_request_failed) {
            if (response.header_overflowed) {
                king_http3_throw(
                    king_ce_protocol_exception,
                    "%s() exceeded the active HTTP/3 response-header size limit.",
                    function_name
                );
            } else if (response.body_overflowed) {
                king_http3_throw(
                    king_ce_protocol_exception,
                    "%s() exceeded the active HTTP/3 response-body size limit.",
                    function_name
                );
            } else {
                king_http3_throw(
                    king_ce_protocol_exception,
                    "%s() failed while driving the active LSQUIC HTTP/3 request stream.",
                    function_name
                );
            }
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

        if (!runtime.lsquic_headers_sent && now_ms >= connect_deadline_ms) {
            king_http3_throw(
                king_ce_timeout_exception,
                "%s() timed out while establishing the QUIC connection.",
                function_name
            );
            goto failure;
        }

        if (king_http3_lsquic_runtime_process_egress(&runtime, function_name) != SUCCESS) {
            goto failure;
        }
        king_http3_refresh_tls_state(&runtime);

        if (runtime.lsquic_request_failed) {
            continue;
        }

        if (response.response_complete) {
            break;
        }

        if (runtime.lsquic_connection_closed) {
            char status[128];

            memset(status, 0, sizeof(status));
            if (runtime.lsquic_conn != NULL
                && king_http3_lsquic.lsquic_conn_status_fn != NULL) {
                (void) king_http3_lsquic.lsquic_conn_status_fn(
                    runtime.lsquic_conn,
                    status,
                    sizeof(status)
                );
            }

            king_http3_throw(
                king_ce_quic_exception,
                "%s() LSQUIC closed the active connection before the HTTP/3 response completed%s%s.",
                function_name,
                status[0] != '\0' ? ": " : "",
                status[0] != '\0' ? status : ""
            );
            goto failure;
        }

        memset(&pfd, 0, sizeof(pfd));
        pfd.fd = runtime.socket_fd;
        pfd.events = POLLIN;

        remaining_overall_ms = overall_deadline_ms - now_ms;
        remaining_connect_ms = connect_deadline_ms - now_ms;
        poll_timeout_ms = remaining_overall_ms;
        if (!runtime.lsquic_headers_sent
            && remaining_connect_ms > 0
            && poll_timeout_ms > remaining_connect_ms) {
            poll_timeout_ms = remaining_connect_ms;
        }
        lsquic_timeout_ms = king_http3_lsquic_poll_timeout_ms(&runtime, poll_timeout_ms);
        if (lsquic_timeout_ms < poll_timeout_ms) {
            poll_timeout_ms = lsquic_timeout_ms;
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
            continue;
        }

        if ((pfd.revents & (POLLERR | POLLHUP | POLLNVAL)) != 0
            && (pfd.revents & POLLIN) == 0) {
            king_http3_throw(
                king_ce_network_exception,
                "%s() received an error event while polling the active HTTP/3 UDP socket.",
                function_name
            );
            goto failure;
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
                    if (errno == EINTR) {
                        continue;
                    }

                    king_http3_throw(
                        king_ce_network_exception,
                        "%s() failed while receiving an HTTP/3 datagram (errno %d).",
                        function_name,
                        errno
                    );
                    goto failure;
                }

                if (king_http3_lsquic_runtime_packet_in(
                        &runtime,
                        buffer,
                        (size_t) received,
                        (struct sockaddr *) &from,
                        0
                    ) < 0) {
                    king_http3_throw(
                        king_ce_quic_exception,
                        "%s() failed while passing an incoming datagram to LSQUIC.",
                        function_name
                    );
                    goto failure;
                }
            }
        }
    }

    if (!response.response_complete) {
        king_http3_throw(
            king_ce_protocol_exception,
            "%s() did not receive a complete HTTP/3 response.",
            function_name
        );
        goto failure;
    }

    king_http3_refresh_tls_state(&runtime);
    if (king_http3_materialize_response(return_value, &response, &runtime, url_str) != SUCCESS) {
        goto failure;
    }

    king_http3_free_request_headers(request_headers, owned_strings, owned_string_count);
    king_http3_request_target_destroy(parsed_url, &target);
    king_http3_runtime_destroy(&runtime);
    smart_str_free(&response.body);
    if (Z_TYPE(effective_headers) != IS_UNDEF) {
        zval_ptr_dtor(&effective_headers);
    }
    king_set_error("");
    return SUCCESS;

failure:
    king_http3_free_request_headers(request_headers, owned_strings, owned_string_count);
    king_http3_request_target_destroy(parsed_url, &target);
    king_http3_runtime_destroy(&runtime);
    king_http3_response_destroy(&response);
    if (Z_TYPE(effective_headers) != IS_UNDEF) {
        zval_ptr_dtor(&effective_headers);
    }
    return FAILURE;
}
#endif
