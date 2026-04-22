PHP_FUNCTION(king_http3_server_listen_once)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *handler;
    king_client_session_t *session = NULL;
    king_server_http3_options_t options;
    king_server_http3_runtime_t runtime;
    king_server_http3_request_state_t request_state;
    king_server_http3_response_state_t response_state;
    zval request;
    zval retval;
    zend_bool request_complete = 0;
    zend_bool handler_invoked = 0;
    zend_bool rc = 0;
    zend_long deadline_ms;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    if (
        king_system_require_admission(
            "king_http3_server_listen_once",
            "http_listener_accepts"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    memset(&runtime, 0, sizeof(runtime));
    runtime.socket_fd = -1;
    request_state.headers_initialized = false;
    memset(&response_state, 0, sizeof(response_state));
    memset(&options, 0, sizeof(options));
    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);

    session = king_server_local_open_session(
        "king_http3_server_listen_once",
        host,
        host_len,
        port,
        config,
        3,
        "h3",
        "server_http3_socket"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (
        king_server_http3_parse_options(
            config,
            &options,
            "king_http3_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

#if defined(KING_HTTP3_BACKEND_LSQUIC)
    rc = king_server_http3_listen_once_lsquic(
        host,
        host_len,
        port,
        handler,
        session,
        &options,
        &runtime,
        &request_state,
        &response_state,
        &request,
        &retval,
        &request_complete,
        &handler_invoked
    ) == SUCCESS;
    goto cleanup;
#endif

    if (king_server_http3_ensure_quiche_ready() != SUCCESS) {
        king_server_local_set_errorf(
            "king_http3_server_listen_once() HTTP/3 runtime is unavailable: %s",
            king_server_http3_quiche.load_error
        );
        goto cleanup;
    }

    if (
        king_server_http3_open_listener_socket(
            host,
            port,
            &runtime,
            "king_http3_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http3_prepare_runtime_config(
            &runtime,
            &options,
            "king_http3_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    king_server_http3_request_state_init(&request_state);
    deadline_ms = king_server_http3_now_ms() + options.timeout_ms;

    while (king_server_http3_now_ms() < deadline_ms) {
        zend_long poll_timeout_ms;

        if (runtime.conn != NULL
            && runtime.h3_conn == NULL
            && king_server_http3_quiche.quiche_conn_is_established_fn(runtime.conn)) {
            runtime.h3_config = king_server_http3_quiche.quiche_h3_config_new_fn();
            if (runtime.h3_config == NULL) {
                king_server_local_set_errorf(
                    "king_http3_server_listen_once() failed to allocate the active HTTP/3 config.",
                    0
                );
                goto cleanup;
            }

            runtime.h3_conn = king_server_http3_quiche.quiche_h3_conn_new_with_transport_fn(
                runtime.conn,
                runtime.h3_config
            );
            if (runtime.h3_conn == NULL) {
                king_server_local_set_errorf(
                    "king_http3_server_listen_once() failed to create the active HTTP/3 connection.",
                    0
                );
                goto cleanup;
            }
        }

        if (runtime.h3_conn != NULL) {
            if (
                king_server_http3_process_events(
                    &runtime,
                    &request_state,
                    &request_complete,
                    "king_http3_server_listen_once"
                ) != SUCCESS
            ) {
                goto cleanup;
            }
        }

        if (request_complete && !handler_invoked) {
            if (
                king_server_http3_build_wire_request(
                    &request,
                    &request_state,
                    session,
                    host,
                    host_len,
                    port,
                    "king_http3_server_listen_once"
                ) != SUCCESS
            ) {
                goto cleanup;
            }

            if (
                king_server_local_invoke_handler(
                    handler,
                    &request,
                    &retval,
                    "king_http3_server_listen_once"
                ) != SUCCESS
            ) {
                goto cleanup;
            }

            if (
                king_server_local_validate_response(
                    &retval,
                    session,
                    "http/3",
                    "king_http3_server_listen_once"
                ) != SUCCESS
            ) {
                goto cleanup;
            }

            if (
                king_server_http3_prepare_response_state(
                    &response_state,
                    &retval,
                    request_state.stream_id,
                    "king_http3_server_listen_once"
                ) != SUCCESS
            ) {
                goto cleanup;
            }

            handler_invoked = 1;
        }

        if (runtime.h3_conn != NULL && response_state.initialized) {
            int send_rc = king_server_http3_try_send_response(
                &runtime,
                &response_state,
                "king_http3_server_listen_once"
            );

            if (send_rc < 0) {
                if (runtime.conn != NULL
                    && king_server_http3_quiche.quiche_conn_is_closed_fn(runtime.conn)) {
                    king_server_local_mark_stream_cancelled_if_registered(
                        session,
                        request_state.stream_id,
                        "king_http3_server_listen_once"
                    );
                }
                goto cleanup;
            }

            if (king_server_http3_flush_egress(&runtime, "king_http3_server_listen_once") != SUCCESS) {
                king_server_http3_mark_cancelled_on_transport_failure(
                    session,
                    &request_state,
                    &response_state,
                    "king_http3_server_listen_once"
                );
                goto cleanup;
            }

            if (response_state.close_sent) {
                if (king_server_http3_quiche.quiche_conn_is_closed_fn(runtime.conn)
                    || king_server_http3_now_ms() >= response_state.close_grace_deadline_ms) {
                    rc = 1;
                    break;
                }
            }
        }

        if (runtime.conn != NULL
            && king_server_http3_quiche.quiche_conn_is_closed_fn(runtime.conn)
            && !response_state.close_sent) {
            king_server_local_mark_stream_cancelled_if_registered(
                session,
                request_state.stream_id,
                "king_http3_server_listen_once"
            );
            king_server_local_set_errorf(
                "king_http3_server_listen_once() saw the QUIC connection close before a complete response was sent."
            );
            goto cleanup;
        }

        poll_timeout_ms = deadline_ms - king_server_http3_now_ms();
        if (poll_timeout_ms > 50) {
            poll_timeout_ms = 50;
        }
        if (runtime.conn != NULL) {
            uint64_t quiche_timeout_ms = king_server_http3_quiche.quiche_conn_timeout_as_millis_fn(runtime.conn);

            if (quiche_timeout_ms < (uint64_t) poll_timeout_ms) {
                poll_timeout_ms = (zend_long) quiche_timeout_ms;
            }
        }
        if (poll_timeout_ms < 0) {
            poll_timeout_ms = 0;
        }

        {
            struct pollfd pfd;
            int poll_rc;

            memset(&pfd, 0, sizeof(pfd));
            pfd.fd = runtime.socket_fd;
            pfd.events = POLLIN;

            poll_rc = poll(&pfd, 1, (int) poll_timeout_ms);
            if (poll_rc < 0) {
                if (errno == EINTR) {
                    continue;
                }

                king_server_local_set_errorf(
                    "king_http3_server_listen_once() failed while polling the HTTP/3 listener socket (errno %d).",
                    errno
                );
                goto cleanup;
            }

            if (poll_rc == 0) {
                if (runtime.conn != NULL) {
                    king_server_http3_quiche.quiche_conn_on_timeout_fn(runtime.conn);
                    if (king_server_http3_flush_egress(&runtime, "king_http3_server_listen_once") != SUCCESS) {
                        king_server_http3_mark_cancelled_on_transport_failure(
                            session,
                            &request_state,
                            &response_state,
                            "king_http3_server_listen_once"
                        );
                        goto cleanup;
                    }
                }
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

                        king_server_local_set_errorf(
                            "king_http3_server_listen_once() failed to receive the active UDP datagram (errno %d).",
                            errno
                        );
                        goto cleanup;
                    }

                    if (runtime.conn == NULL) {
                        int accept_rc = king_server_http3_handle_first_packet(
                            &runtime,
                            session,
                            buffer,
                            (size_t) received,
                            (struct sockaddr *) &from,
                            from_len,
                            "king_http3_server_listen_once"
                        );

                        if (accept_rc < 0) {
                            goto cleanup;
                        }

                        if (accept_rc == 0) {
                            continue;
                        }
                    } else {
                        quiche_recv_info recv_info;
                        ssize_t processed;

                        recv_info.from = (struct sockaddr *) &from;
                        recv_info.from_len = from_len;
                        recv_info.to = (struct sockaddr *) &runtime.local_addr;
                        recv_info.to_len = runtime.local_addr_len;

                        processed = king_server_http3_quiche.quiche_conn_recv_fn(
                            runtime.conn,
                            buffer,
                            (size_t) received,
                            &recv_info
                        );
                        if (processed < 0 && processed != QUICHE_ERR_DONE) {
                            king_server_local_set_errorf(
                                "king_http3_server_listen_once() failed while processing an incoming QUIC packet (%zd).",
                                processed
                            );
                            goto cleanup;
                        }
                    }

                    if (king_server_http3_flush_egress(&runtime, "king_http3_server_listen_once") != SUCCESS) {
                        king_server_http3_mark_cancelled_on_transport_failure(
                            session,
                            &request_state,
                            &response_state,
                            "king_http3_server_listen_once"
                        );
                        goto cleanup;
                    }
                }
            }
        }
    }

    if (!rc) {
        king_server_local_set_errorf(
            "king_http3_server_listen_once() timed out before completing the active one-shot HTTP/3 request."
        );
    }

cleanup:
    if (session != NULL) {
        king_server_local_close_session(session);
        runtime.socket_fd = -1;
    }

    if (request_state.headers_initialized) {
        king_server_http3_request_state_dtor(&request_state);
    }

    if (response_state.initialized) {
        king_server_http3_response_state_dtor(&response_state);
    }

    if (!Z_ISUNDEF(request)) {
        zval_ptr_dtor(&request);
    }

    if (!Z_ISUNDEF(retval)) {
        zval_ptr_dtor(&retval);
    }

    king_server_http3_options_destroy(&options);
    king_server_http3_runtime_destroy(&runtime);

    if (!rc) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}
