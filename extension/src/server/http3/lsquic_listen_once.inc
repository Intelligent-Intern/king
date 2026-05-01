#if defined(KING_HTTP3_BACKEND_LSQUIC)

static zend_result king_server_http3_listen_once_lsquic(
    const char *host,
    size_t host_len,
    zend_long port,
    zval *handler,
    king_client_session_t *session,
    const king_server_http3_options_t *options,
    king_server_http3_runtime_t *runtime,
    king_server_http3_request_state_t *request_state,
    king_server_http3_response_state_t *response_state,
    zval *request,
    zval *retval,
    zend_bool *request_complete,
    zend_bool *handler_invoked
)
{
    const char *function_name = "king_http3_server_listen_once";
    zend_long deadline_ms;

    if (king_server_http3_ensure_lsquic_ready() != SUCCESS) {
        king_server_local_set_errorf(
            "%s() HTTP/3 LSQUIC runtime is unavailable: %s",
            function_name,
            king_server_http3_lsquic.load_error
        );
        return FAILURE;
    }

    if (king_server_http3_open_listener_socket(host, port, runtime, function_name) != SUCCESS) {
        return FAILURE;
    }

    runtime->lsquic_function_name = function_name;
    runtime->lsquic_session = session;
    runtime->lsquic_request_state = request_state;
    runtime->lsquic_response_state = response_state;
    runtime->lsquic_request_complete = request_complete;

    if (king_server_http3_lsquic_runtime_init(runtime, options, function_name) != SUCCESS) {
        return FAILURE;
    }

    king_server_http3_request_state_init(request_state);
    deadline_ms = king_server_http3_now_ms() + options->timeout_ms;

    while (king_server_http3_now_ms() < deadline_ms) {
        struct pollfd pfd;
        zend_long poll_timeout_ms;
        int poll_rc;

        if (king_server_http3_lsquic_runtime_process_egress(runtime, function_name) != SUCCESS) {
            return FAILURE;
        }

        if (runtime->lsquic_request_failed) {
            if (king_get_error()[0] == '\0') {
                king_server_local_set_errorf(
                    "%s() failed while reading the active LSQUIC HTTP/3 request.",
                    function_name
                );
            }
            return FAILURE;
        }

        if (*request_complete && !*handler_invoked) {
            if (
                king_server_http3_build_wire_request(
                    request,
                    request_state,
                    session,
                    host,
                    host_len,
                    port,
                    function_name
                ) != SUCCESS
            ) {
                return FAILURE;
            }

            if (king_server_local_invoke_handler(handler, request, retval, function_name) != SUCCESS) {
                return FAILURE;
            }

            if (king_server_local_validate_response(retval, session, "http/3", function_name) != SUCCESS) {
                return FAILURE;
            }

            if (
                king_server_http3_prepare_response_state(
                    response_state,
                    retval,
                    request_state->stream_id,
                    function_name
                ) != SUCCESS
            ) {
                return FAILURE;
            }

            runtime->lsquic_response_state = response_state;
            if (runtime->lsquic_stream_state != NULL
                && runtime->lsquic_stream_state->stream != NULL) {
                king_server_http3_lsquic.lsquic_stream_wantwrite_fn(
                    runtime->lsquic_stream_state->stream,
                    1
                );
            }
            *handler_invoked = true;
        }

        if (runtime->lsquic_response_failed) {
            king_server_http3_lsquic_mark_stream_cancelled_if_registered(runtime);
            king_server_local_set_errorf(
                "%s() failed while writing the active LSQUIC HTTP/3 response.",
                function_name
            );
            return FAILURE;
        }

        if (runtime->lsquic_connection_closed
            && (!response_state->initialized || !response_state->close_sent)) {
            king_server_http3_lsquic_mark_stream_cancelled_if_registered(runtime);
            king_server_local_set_errorf(
                "%s() saw the LSQUIC HTTP/3 connection close before a complete response was sent.",
                function_name
            );
            return FAILURE;
        }

        if (response_state->initialized && response_state->close_sent) {
            if (king_server_http3_lsquic_runtime_process_egress(runtime, function_name) != SUCCESS) {
                return FAILURE;
            }
            return SUCCESS;
        }

        poll_timeout_ms = deadline_ms - king_server_http3_now_ms();
        if (poll_timeout_ms > 50) {
            poll_timeout_ms = 50;
        }
        poll_timeout_ms = king_server_http3_lsquic_poll_timeout_ms(runtime, poll_timeout_ms);
        if (poll_timeout_ms < 0) {
            poll_timeout_ms = 0;
        }

        memset(&pfd, 0, sizeof(pfd));
        pfd.fd = runtime->socket_fd;
        pfd.events = POLLIN;

        poll_rc = poll(&pfd, 1, (int) poll_timeout_ms);
        if (poll_rc < 0) {
            if (errno == EINTR) {
                continue;
            }

            king_server_local_set_errorf(
                "%s() failed while polling the LSQUIC HTTP/3 listener socket (errno %d).",
                function_name,
                errno
            );
            return FAILURE;
        }

        if (poll_rc == 0) {
            continue;
        }

        if ((pfd.revents & (POLLERR | POLLHUP | POLLNVAL)) != 0
            && (pfd.revents & POLLIN) == 0) {
            king_server_local_set_errorf(
                "%s() received an error event while polling the LSQUIC HTTP/3 listener socket.",
                function_name
            );
            return FAILURE;
        }

        if ((pfd.revents & POLLIN) != 0) {
            for (;;) {
                uint8_t buffer[65535];
                struct sockaddr_storage from;
                socklen_t from_len = sizeof(from);
                ssize_t received = recvfrom(
                    runtime->socket_fd,
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

                    king_server_local_set_errorf(
                        "%s() failed to receive the active LSQUIC HTTP/3 datagram (errno %d).",
                        function_name,
                        errno
                    );
                    return FAILURE;
                }

                if (runtime->peer_addr_len == 0) {
                    memcpy(&runtime->peer_addr, &from, from_len);
                    runtime->peer_addr_len = from_len;
                    if (
                        king_server_http3_apply_transport_snapshot_from_runtime(
                            session,
                            runtime,
                            "server_http3_lsquic_socket",
                            function_name
                        ) != SUCCESS
                    ) {
                        return FAILURE;
                    }
                }

                if (king_server_http3_lsquic_runtime_packet_in(
                        runtime,
                        buffer,
                        (size_t) received,
                        (struct sockaddr *) &from,
                        0
                    ) < 0) {
                    king_server_local_set_errorf(
                        "%s() failed while passing an incoming datagram to LSQUIC.",
                        function_name
                    );
                    return FAILURE;
                }
            }
        }
    }

    king_server_local_set_errorf(
        "%s() timed out before completing the active one-shot LSQUIC HTTP/3 request.",
        function_name
    );
    return FAILURE;
}

#endif
