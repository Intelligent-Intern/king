static zend_result king_server_http3_flush_egress(
    king_server_http3_runtime_t *runtime,
    const char *function_name
)
{
    uint8_t out[KING_SERVER_HTTP3_MAX_DATAGRAM_SIZE];
    lsquic_send_info send_info;
    ssize_t written;

    if (runtime->conn == NULL || runtime->socket_fd < 0) {
        return SUCCESS;
    }

    while ((written = king_server_http3_lsquic.lsquic_conn_send_fn(
                runtime->conn,
                out,
                sizeof(out),
                &send_info
            )) != LSQUIC_ERR_DONE) {
        ssize_t sent;

        if (written < 0) {
            king_server_local_set_errorf(
                "%s() failed while preparing an HTTP/3 datagram for send (%zd).",
                function_name,
                written
            );
            return FAILURE;
        }

        sent = sendto(
            runtime->socket_fd,
            out,
            (size_t) written,
            0,
            (struct sockaddr *) &send_info.to,
            send_info.to_len
        );
        if (sent < 0) {
            if (errno == EAGAIN || errno == EWOULDBLOCK || errno == EINTR) {
                continue;
            }

            king_server_local_set_errorf(
                "%s() failed to send the active HTTP/3 datagram (errno %d).",
                function_name,
                errno
            );
            return FAILURE;
        }

        if (sent != written) {
            king_server_local_set_errorf(
                "%s() sent a truncated HTTP/3 datagram.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static void king_server_http3_mark_cancelled_on_transport_failure(
    king_client_session_t *session,
    const king_server_http3_request_state_t *request_state,
    const king_server_http3_response_state_t *response_state,
    const char *function_name
)
{
    if (!king_server_local_errno_is_peer_disconnect(errno)) {
        return;
    }

    if (request_state == NULL || response_state == NULL) {
        return;
    }

    if (!request_state->request_stream_seen || !response_state->initialized) {
        return;
    }

    king_server_local_mark_stream_cancelled_if_registered(
        session,
        (zend_long) request_state->stream_id,
        function_name
    );
}

static int king_server_http3_try_send_response(
    king_server_http3_runtime_t *runtime,
    king_server_http3_response_state_t *response,
    const char *function_name
)
{
    int rc;

    if (!response->initialized) {
        return 1;
    }

    if (!response->headers_sent) {
        rc = king_server_http3_lsquic.lsquic_h3_send_response_fn(
            runtime->h3_conn,
            runtime->conn,
            response->stream_id,
            response->headers,
            response->headers_len,
            ZSTR_LEN(response->body) == 0
        );
        if (rc == 0) {
            response->headers_sent = true;
        } else if (rc == LSQUIC_H3_ERR_DONE || rc == LSQUIC_H3_ERR_STREAM_BLOCKED) {
            return 0;
        } else {
            king_server_local_set_errorf(
                "%s() failed to send the HTTP/3 response headers (%d).",
                function_name,
                rc
            );
            return -1;
        }
    }

    if (response->headers_sent && response->body_offset < ZSTR_LEN(response->body)) {
        ssize_t wrote = king_server_http3_lsquic.lsquic_h3_send_body_fn(
            runtime->h3_conn,
            runtime->conn,
            response->stream_id,
            (const uint8_t *) (ZSTR_VAL(response->body) + response->body_offset),
            ZSTR_LEN(response->body) - response->body_offset,
            true
        );
        if (wrote > 0) {
            response->body_offset += (size_t) wrote;
            return 0;
        }

        if (wrote == LSQUIC_H3_ERR_DONE || wrote == LSQUIC_H3_ERR_STREAM_BLOCKED) {
            return 0;
        }

        king_server_local_set_errorf(
            "%s() failed to send the HTTP/3 response body (%zd).",
            function_name,
            wrote
        );
        return -1;
    }

    if (response->headers_sent && response->body_offset == ZSTR_LEN(response->body) && !response->goaway_sent) {
        rc = king_server_http3_lsquic.lsquic_h3_send_goaway_fn(
            runtime->h3_conn,
            runtime->conn,
            response->stream_id
        );
        if (rc == 0) {
            response->goaway_sent = true;
        } else if (rc == LSQUIC_H3_ERR_DONE || rc == LSQUIC_H3_ERR_STREAM_BLOCKED) {
            return 0;
        } else {
            king_server_local_set_errorf(
                "%s() failed to send the HTTP/3 GOAWAY (%d).",
                function_name,
                rc
            );
            return -1;
        }
    }

    if (response->goaway_sent && !response->close_sent) {
        /* Once the full response and GOAWAY are on wire, closing the QUIC
         * connection is best-effort: the peer may already be closing or the
         * local transport may already be in the closed/draining state. */
        (void) king_server_http3_lsquic.lsquic_conn_close_fn(runtime->conn, false, 0, NULL, 0);
        response->close_sent = true;
        response->close_grace_deadline_ms = king_server_http3_now_ms() + KING_SERVER_HTTP3_CLOSE_GRACE_MS;
    }

    return 1;
}

static int king_server_http3_handle_first_packet(
    king_server_http3_runtime_t *runtime,
    king_client_session_t *session,
    const uint8_t *packet,
    size_t packet_len,
    const struct sockaddr *from,
    socklen_t from_len,
    const char *function_name
)
{
    uint32_t version = 0;
    uint8_t packet_type = 0;
    uint8_t scid[LSQUIC_MAX_CONN_ID_LEN];
    size_t scid_len = sizeof(scid);
    uint8_t dcid[LSQUIC_MAX_CONN_ID_LEN];
    size_t dcid_len = sizeof(dcid);
    uint8_t token[KING_MAX_TICKET_SIZE];
    size_t token_len = sizeof(token);
    uint8_t server_scid[LSQUIC_MAX_CONN_ID_LEN];
    lsquic_recv_info recv_info;
    ssize_t processed;

    if (king_server_http3_lsquic.lsquic_header_info_fn(
            packet,
            packet_len,
            LSQUIC_MAX_CONN_ID_LEN,
            &version,
            &packet_type,
            scid,
            &scid_len,
            dcid,
            &dcid_len,
            token,
            &token_len
        ) != 0) {
        king_server_local_set_errorf(
            "%s() failed to parse the first incoming QUIC packet.",
            function_name
        );
        return -1;
    }

    if (!king_server_http3_lsquic.lsquic_version_is_supported_fn(version)) {
        uint8_t out[KING_SERVER_HTTP3_MAX_DATAGRAM_SIZE];
        ssize_t written = king_server_http3_lsquic.lsquic_negotiate_version_fn(
            scid,
            scid_len,
            dcid,
            dcid_len,
            out,
            sizeof(out)
        );

        if (written > 0) {
            (void) sendto(runtime->socket_fd, out, (size_t) written, 0, from, from_len);
        }

        return 0;
    }

    if (king_server_http3_fill_random_bytes(server_scid, sizeof(server_scid)) != SUCCESS) {
        king_server_local_set_errorf(
            "%s() failed to initialize an HTTP/3 source connection ID.",
            function_name
        );
        return -1;
    }

    runtime->conn = king_server_http3_lsquic.lsquic_accept_fn(
        server_scid,
        sizeof(server_scid),
        NULL,
        0,
        (struct sockaddr *) &runtime->local_addr,
        runtime->local_addr_len,
        from,
        from_len,
        runtime->config
    );
    if (runtime->conn == NULL) {
        king_server_local_set_errorf(
            "%s() failed to accept the active QUIC connection.",
            function_name
        );
        return -1;
    }

    memcpy(&runtime->peer_addr, from, from_len);
    runtime->peer_addr_len = from_len;

    if (connect(runtime->socket_fd, from, from_len) != 0) {
        king_server_local_set_errorf(
            "%s() failed to connect the accepted HTTP/3 UDP socket to the peer (errno %d).",
            function_name,
            errno
        );
        return -1;
    }

    if (
        king_server_http3_apply_transport_snapshot_from_socket(
            session,
            runtime->socket_fd,
            function_name
        ) != SUCCESS
    ) {
        return -1;
    }

    recv_info.from = (struct sockaddr *) from;
    recv_info.from_len = from_len;
    recv_info.to = (struct sockaddr *) &runtime->local_addr;
    recv_info.to_len = runtime->local_addr_len;

    processed = king_server_http3_lsquic.lsquic_conn_recv_fn(
        runtime->conn,
        (uint8_t *) packet,
        packet_len,
        &recv_info
    );
    if (processed < 0 && processed != LSQUIC_ERR_DONE) {
        king_server_local_set_errorf(
            "%s() failed while processing the first incoming QUIC packet (%zd).",
            function_name,
            processed
        );
        return -1;
    }

    if (king_server_http3_flush_egress(runtime, function_name) != SUCCESS) {
        return -1;
    }

    return 1;
}

static zend_result king_server_http3_process_events(
    king_server_http3_runtime_t *runtime,
    king_server_http3_request_state_t *request_state,
    zend_bool *request_complete,
    const char *function_name
)
{
    for (;;) {
        lsquic_h3_event *event = NULL;
        int64_t stream_id = king_server_http3_lsquic.lsquic_h3_conn_poll_fn(
            runtime->h3_conn,
            runtime->conn,
            &event
        );

        if (stream_id == LSQUIC_H3_ERR_DONE) {
            break;
        }

        if (stream_id < 0) {
            king_server_local_set_errorf(
                "%s() failed while polling HTTP/3 server events (%" PRId64 ").",
                function_name,
                stream_id
            );
            return FAILURE;
        }

        switch (king_server_http3_lsquic.lsquic_h3_event_type_fn(event)) {
            case LSQUIC_H3_EVENT_HEADERS: {
                king_server_http3_request_header_context_t context;
                int rc;

                if (!request_state->request_stream_seen) {
                    request_state->request_stream_seen = true;
                    request_state->stream_id = (uint64_t) stream_id;
                } else if (request_state->stream_id != (uint64_t) stream_id) {
                    king_server_http3_lsquic.lsquic_h3_event_free_fn(event);
                    king_server_local_set_errorf(
                        "%s() received multiple request streams on the one-shot HTTP/3 listener.",
                        function_name
                    );
                    return FAILURE;
                }

                context.state = request_state;
                context.function_name = function_name;
                rc = king_server_http3_lsquic.lsquic_h3_event_for_each_header_fn(
                    event,
                    king_server_http3_collect_request_header,
                    &context
                );
                if (rc != 0) {
                    king_server_http3_lsquic.lsquic_h3_event_free_fn(event);
                    return FAILURE;
                }

                break;
            }

            case LSQUIC_H3_EVENT_DATA: {
                if (request_state->request_stream_seen && request_state->stream_id == (uint64_t) stream_id) {
                    for (;;) {
                        uint8_t buffer[4096];
                        ssize_t read = king_server_http3_lsquic.lsquic_h3_recv_body_fn(
                            runtime->h3_conn,
                            runtime->conn,
                            (uint64_t) stream_id,
                            buffer,
                            sizeof(buffer)
                        );

                        if (read == LSQUIC_H3_ERR_DONE || read == 0) {
                            break;
                        }

                        if (read < 0) {
                            king_server_http3_lsquic.lsquic_h3_event_free_fn(event);
                            king_server_local_set_errorf(
                                "%s() failed while reading the active HTTP/3 request body (%zd).",
                                function_name,
                                read
                            );
                            return FAILURE;
                        }

                        request_state->body_bytes += (size_t) read;
                        if (request_state->body_bytes > KING_SERVER_HTTP3_MAX_REQUEST_BODY_BYTES) {
                            king_server_http3_lsquic.lsquic_h3_event_free_fn(event);
                            king_server_local_set_errorf(
                                "%s() received an HTTP/3 request body that exceeds the active one-shot limit.",
                                function_name
                            );
                            return FAILURE;
                        }

                        smart_str_appendl(&request_state->body, (const char *) buffer, (size_t) read);
                    }
                }

                break;
            }

            case LSQUIC_H3_EVENT_FINISHED:
                if (request_state->request_stream_seen && request_state->stream_id == (uint64_t) stream_id) {
                    *request_complete = true;
                }
                break;

            case LSQUIC_H3_EVENT_RESET:
                king_server_http3_lsquic.lsquic_h3_event_free_fn(event);
                king_server_local_set_errorf(
                    "%s() observed an HTTP/3 stream reset before the one-shot request completed.",
                    function_name
                );
                return FAILURE;

            case LSQUIC_H3_EVENT_GOAWAY:
            case LSQUIC_H3_EVENT_PRIORITY_UPDATE:
                break;
        }

        king_server_http3_lsquic.lsquic_h3_event_free_fn(event);
    }

    return SUCCESS;
}

