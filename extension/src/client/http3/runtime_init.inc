static zend_result king_http3_runtime_open_udp_socket(
    king_http3_request_runtime_t *runtime,
    const king_http3_request_target_t *target,
    const char *function_name)
{
    struct addrinfo hints;
    struct addrinfo *result = NULL;
    struct addrinfo *candidate;
    char port_buffer[16];

    memset(runtime, 0, sizeof(*runtime));
    runtime->socket_fd = -1;

    snprintf(port_buffer, sizeof(port_buffer), "%ld", (long) target->port);

    memset(&hints, 0, sizeof(hints));
    hints.ai_socktype = SOCK_DGRAM;
    hints.ai_family = AF_UNSPEC;
    hints.ai_flags = AI_ADDRCONFIG;

    if (getaddrinfo(ZSTR_VAL(target->host), port_buffer, &hints, &result) != 0) {
        king_http3_throw(
            king_ce_network_exception,
            "%s() failed to resolve the active HTTP/3 endpoint '%s'.",
            function_name,
            ZSTR_VAL(target->host)
        );
        return FAILURE;
    }

    for (candidate = result; candidate != NULL; candidate = candidate->ai_next) {
        runtime->socket_fd = socket(candidate->ai_family, candidate->ai_socktype, candidate->ai_protocol);
        if (runtime->socket_fd >= 0) {
            memcpy(&runtime->peer_addr, candidate->ai_addr, candidate->ai_addrlen);
            runtime->peer_addr_len = (socklen_t) candidate->ai_addrlen;
            break;
        }
    }

    if (runtime->socket_fd < 0) {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_network_exception,
            "%s() failed to open a UDP socket for the active HTTP/3 runtime.",
            function_name
        );
        return FAILURE;
    }

    if (king_http3_make_socket_nonblocking(runtime->socket_fd) != SUCCESS) {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_network_exception,
            "%s() failed to switch the active HTTP/3 socket into non-blocking mode.",
            function_name
        );
        return FAILURE;
    }

    if (candidate->ai_family == AF_INET) {
        struct sockaddr_in local4;

        memset(&local4, 0, sizeof(local4));
        local4.sin_family = AF_INET;
        local4.sin_addr.s_addr = htonl(INADDR_ANY);
        local4.sin_port = htons(0);

        if (bind(runtime->socket_fd, (struct sockaddr *) &local4, sizeof(local4)) != 0) {
            freeaddrinfo(result);
            king_http3_throw(
                king_ce_network_exception,
                "%s() failed to bind the active HTTP/3 UDP socket.",
                function_name
            );
            return FAILURE;
        }
    } else if (candidate->ai_family == AF_INET6) {
        struct sockaddr_in6 local6;

        memset(&local6, 0, sizeof(local6));
        local6.sin6_family = AF_INET6;
        local6.sin6_addr = in6addr_any;
        local6.sin6_port = htons(0);

        if (bind(runtime->socket_fd, (struct sockaddr *) &local6, sizeof(local6)) != 0) {
            freeaddrinfo(result);
            king_http3_throw(
                king_ce_network_exception,
                "%s() failed to bind the active HTTP/3 UDP socket.",
                function_name
            );
            return FAILURE;
        }
    } else {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_network_exception,
            "%s() resolved the active HTTP/3 endpoint to an unsupported socket family.",
            function_name
        );
        return FAILURE;
    }

    runtime->local_addr_len = sizeof(runtime->local_addr);
    if (getsockname(
            runtime->socket_fd,
            (struct sockaddr *) &runtime->local_addr,
            &runtime->local_addr_len
        ) != 0) {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_network_exception,
            "%s() failed to inspect the local HTTP/3 UDP socket binding.",
            function_name
        );
        return FAILURE;
    }

    freeaddrinfo(result);
    return SUCCESS;
}

static zend_result king_http3_runtime_init(
    king_http3_request_runtime_t *runtime,
    const king_http3_request_target_t *target,
    const king_http3_request_options_t *options,
    const char *function_name)
{
    uint8_t scid[QUICHE_MAX_CONN_ID_LEN];

    if (king_http3_runtime_open_udp_socket(runtime, target, function_name) != SUCCESS) {
        return FAILURE;
    }

    runtime->config = king_http3_quiche.quiche_config_new_fn(QUICHE_PROTOCOL_VERSION);
    if (runtime->config == NULL) {
        king_http3_throw(
            king_ce_system_exception,
            "%s() failed to allocate the active libquiche config.",
            function_name
        );
        return FAILURE;
    }

    if (options->tls_verify_peer && options->tls_default_ca_file != NULL && options->tls_default_ca_file[0] != '\0') {
        if (king_http3_quiche.quiche_config_load_verify_locations_from_file_fn(
                runtime->config,
                options->tls_default_ca_file
            ) != 0) {
            king_http3_throw(
                king_ce_tls_exception,
                "%s() failed to load the active HTTP/3 CA file '%s'.",
                function_name,
                options->tls_default_ca_file
            );
            return FAILURE;
        }
    }

    if (options->tls_default_cert_file != NULL
        && options->tls_default_cert_file[0] != '\0'
        && options->tls_default_key_file != NULL
        && options->tls_default_key_file[0] != '\0') {
        if (king_http3_quiche.quiche_config_load_cert_chain_from_pem_file_fn(
                runtime->config,
                options->tls_default_cert_file
            ) != 0) {
            king_http3_throw(
                king_ce_tls_exception,
                "%s() failed to load the active HTTP/3 client certificate '%s'.",
                function_name,
                options->tls_default_cert_file
            );
            return FAILURE;
        }

        if (king_http3_quiche.quiche_config_load_priv_key_from_pem_file_fn(
                runtime->config,
                options->tls_default_key_file
            ) != 0) {
            king_http3_throw(
                king_ce_tls_exception,
                "%s() failed to load the active HTTP/3 client key '%s'.",
                function_name,
                options->tls_default_key_file
            );
            return FAILURE;
        }
    }

    king_http3_quiche.quiche_config_verify_peer_fn(runtime->config, options->tls_verify_peer);
    king_http3_quiche.quiche_config_grease_fn(runtime->config, options->quic_grease_enable);
    if (options->tls_enable_early_data) {
        king_http3_quiche.quiche_config_enable_early_data_fn(runtime->config);
    }
    king_http3_quiche.quiche_config_enable_hystart_fn(runtime->config, options->quic_cc_enable_hystart_plus_plus);
    king_http3_quiche.quiche_config_enable_pacing_fn(runtime->config, options->quic_pacing_enable);

    if (king_http3_quiche.quiche_config_set_application_protos_fn(
            runtime->config,
            (const uint8_t *) QUICHE_H3_APPLICATION_PROTOCOL,
            sizeof(QUICHE_H3_APPLICATION_PROTOCOL) - 1
        ) != 0) {
        king_http3_throw(
            king_ce_protocol_exception,
            "%s() failed to configure the active HTTP/3 ALPN list.",
            function_name
        );
        return FAILURE;
    }

    king_http3_quiche.quiche_config_set_max_idle_timeout_fn(runtime->config, (uint64_t) options->timeout_ms);
    king_http3_quiche.quiche_config_set_max_recv_udp_payload_size_fn(runtime->config, KING_HTTP3_MAX_DATAGRAM_SIZE);
    king_http3_quiche.quiche_config_set_max_send_udp_payload_size_fn(runtime->config, KING_HTTP3_MAX_DATAGRAM_SIZE);
    king_http3_quiche.quiche_config_set_initial_max_data_fn(runtime->config, (uint64_t) options->quic_initial_max_data);
    king_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_local_fn(runtime->config, (uint64_t) options->quic_initial_max_stream_data_bidi_local);
    king_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_remote_fn(runtime->config, (uint64_t) options->quic_initial_max_stream_data_bidi_remote);
    king_http3_quiche.quiche_config_set_initial_max_stream_data_uni_fn(runtime->config, (uint64_t) options->quic_initial_max_stream_data_uni);
    king_http3_quiche.quiche_config_set_initial_max_streams_bidi_fn(runtime->config, (uint64_t) options->quic_initial_max_streams_bidi);
    king_http3_quiche.quiche_config_set_initial_max_streams_uni_fn(runtime->config, (uint64_t) options->quic_initial_max_streams_uni);
    king_http3_quiche.quiche_config_set_ack_delay_exponent_fn(runtime->config, (uint64_t) options->quic_ack_delay_exponent);
    king_http3_quiche.quiche_config_set_max_ack_delay_fn(runtime->config, (uint64_t) options->quic_max_ack_delay_ms);
    king_http3_quiche.quiche_config_set_disable_active_migration_fn(runtime->config, true);
    king_http3_quiche.quiche_config_set_initial_congestion_window_packets_fn(runtime->config, (size_t) options->quic_cc_initial_cwnd_packets);
    king_http3_quiche.quiche_config_set_active_connection_id_limit_fn(runtime->config, (uint64_t) options->quic_active_connection_id_limit);
    king_http3_quiche.quiche_config_enable_dgram_fn(
        runtime->config,
        options->quic_datagrams_enable,
        (size_t) options->quic_dgram_recv_queue_len,
        (size_t) options->quic_dgram_send_queue_len
    );

    if (options->quic_cc_algorithm != NULL && options->quic_cc_algorithm[0] != '\0') {
        if (king_http3_quiche.quiche_config_set_cc_algorithm_name_fn(runtime->config, options->quic_cc_algorithm) != 0) {
            king_http3_throw(
                king_ce_validation_exception,
                "%s() received an unsupported QUIC congestion-control algorithm '%s'.",
                function_name,
                options->quic_cc_algorithm
            );
            return FAILURE;
        }
    }

    if (king_http3_fill_random_bytes(scid, sizeof(scid)) != SUCCESS) {
        king_http3_throw(
            king_ce_system_exception,
            "%s() failed to initialize a QUIC source connection ID.",
            function_name
        );
        return FAILURE;
    }

    runtime->conn = king_http3_quiche.quiche_connect_fn(
        ZSTR_VAL(target->host),
        scid,
        sizeof(scid),
        (struct sockaddr *) &runtime->local_addr,
        runtime->local_addr_len,
        (struct sockaddr *) &runtime->peer_addr,
        runtime->peer_addr_len,
        runtime->config
    );

    if (runtime->conn == NULL) {
        king_http3_throw(
            king_ce_quic_exception,
            "%s() failed to create the active QUIC connection.",
            function_name
        );
        return FAILURE;
    }

    runtime->tls_ticket_source = "none";
    runtime->tls_session_ticket_length = 0;
    runtime->tls_has_session_ticket = false;
    runtime->tls_enable_early_data = options->tls_enable_early_data;
    runtime->tls_session_resumed = false;
    runtime->tls_ticket_published = false;
    runtime->tls_request_sent_in_early_data = false;
    runtime->quic_packets_sent = 0;
    runtime->quic_packets_received = 0;
    runtime->quic_packets_lost = 0;
    runtime->quic_packets_retransmitted = 0;
    runtime->quic_lost_bytes = 0;
    runtime->quic_stream_retransmitted_bytes = 0;
    king_http3_seed_ticket_from_ring(runtime);

    return SUCCESS;
}

static zend_result king_http3_flush_egress(
    king_http3_request_runtime_t *runtime,
    const char *function_name)
{
    uint8_t out[KING_HTTP3_MAX_DATAGRAM_SIZE];
    quiche_send_info send_info;
    ssize_t written;

    while ((written = king_http3_quiche.quiche_conn_send_fn(
                runtime->conn,
                out,
                sizeof(out),
                &send_info
            )) != QUICHE_ERR_DONE) {
        ssize_t sent;

        if (written < 0) {
            king_http3_throw(
                king_ce_quic_exception,
                "%s() failed to create an outgoing QUIC datagram (%zd).",
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
            if (errno == EAGAIN || errno == EWOULDBLOCK) {
                struct pollfd pfd;
                int poll_result;

                memset(&pfd, 0, sizeof(pfd));
                pfd.fd = runtime->socket_fd;
                pfd.events = POLLOUT;
                poll_result = poll(&pfd, 1, 50);
                if (poll_result >= 0) {
                    continue;
                }
            }

            king_http3_throw(
                king_ce_network_exception,
                "%s() failed while sending an outgoing QUIC datagram (errno %d).",
                function_name,
                errno
            );
            return FAILURE;
        }

        if (sent != written) {
            king_http3_throw(
                king_ce_network_exception,
                "%s() sent a short QUIC datagram on the active UDP socket.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}
