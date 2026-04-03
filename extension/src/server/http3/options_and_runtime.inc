static void king_server_http3_options_destroy(king_server_http3_options_t *options)
{
    if (options->owned_config != NULL) {
        king_config_free(options->owned_config);
        options->owned_config = NULL;
    }
}

static zend_result king_server_http3_parse_options(
    zval *config_zv,
    king_server_http3_options_t *options,
    const char *function_name
)
{
    king_cfg_t *cfg = NULL;
    zend_long connect_timeout_ms;

    memset(options, 0, sizeof(*options));

    connect_timeout_ms = king_tcp_transport_config.connect_timeout_ms > 0
        ? king_tcp_transport_config.connect_timeout_ms
        : 5000;
    options->timeout_ms = connect_timeout_ms > KING_SERVER_HTTP3_DEFAULT_TIMEOUT_MS
        ? connect_timeout_ms
        : KING_SERVER_HTTP3_DEFAULT_TIMEOUT_MS;
    options->tls_default_cert_file = king_tls_and_crypto_config.tls_default_cert_file;
    options->tls_default_key_file = king_tls_and_crypto_config.tls_default_key_file;
    options->quic_cc_algorithm = king_quic_transport_config.cc_algorithm;
    options->quic_cc_initial_cwnd_packets = king_quic_transport_config.cc_initial_cwnd_packets;
    options->quic_cc_enable_hystart_plus_plus = king_quic_transport_config.cc_enable_hystart_plus_plus;
    options->quic_pacing_enable = king_quic_transport_config.pacing_enable;
    options->quic_max_ack_delay_ms = king_quic_transport_config.max_ack_delay_ms;
    options->quic_ack_delay_exponent = king_quic_transport_config.ack_delay_exponent;
    options->quic_initial_max_data = king_quic_transport_config.initial_max_data;
    options->quic_initial_max_stream_data_bidi_local = king_quic_transport_config.initial_max_stream_data_bidi_local;
    options->quic_initial_max_stream_data_bidi_remote = king_quic_transport_config.initial_max_stream_data_bidi_remote;
    options->quic_initial_max_stream_data_uni = king_quic_transport_config.initial_max_stream_data_uni;
    options->quic_initial_max_streams_bidi = king_quic_transport_config.initial_max_streams_bidi;
    options->quic_initial_max_streams_uni = king_quic_transport_config.initial_max_streams_uni;
    options->quic_active_connection_id_limit = king_quic_transport_config.active_connection_id_limit;
    options->quic_grease_enable = king_quic_transport_config.grease_enable;
    options->quic_datagrams_enable = king_quic_transport_config.datagrams_enable;
    options->quic_dgram_recv_queue_len = king_quic_transport_config.dgram_recv_queue_len;
    options->quic_dgram_send_queue_len = king_quic_transport_config.dgram_send_queue_len;

    if (config_zv != NULL && Z_TYPE_P(config_zv) != IS_NULL) {
        if (Z_TYPE_P(config_zv) == IS_ARRAY) {
            cfg = king_config_new_from_options(config_zv);
            if (cfg == NULL) {
                return FAILURE;
            }

            options->owned_config = cfg;
        } else {
            cfg = (king_cfg_t *) king_fetch_config(config_zv);
            if (cfg == NULL) {
                zend_argument_type_error(
                    3,
                    "must be null, array, a King\\Config resource, or a King\\Config object"
                );
                return FAILURE;
            }
        }

        connect_timeout_ms = cfg->tcp.connect_timeout_ms > 0
            ? cfg->tcp.connect_timeout_ms
            : connect_timeout_ms;
        options->timeout_ms = connect_timeout_ms > KING_SERVER_HTTP3_DEFAULT_TIMEOUT_MS
            ? connect_timeout_ms
            : KING_SERVER_HTTP3_DEFAULT_TIMEOUT_MS;
        options->tls_default_cert_file = cfg->tls.tls_default_cert_file;
        options->tls_default_key_file = cfg->tls.tls_default_key_file;
        options->quic_cc_algorithm = cfg->quic.cc_algorithm;
        options->quic_cc_initial_cwnd_packets = cfg->quic.cc_initial_cwnd_packets;
        options->quic_cc_enable_hystart_plus_plus = cfg->quic.cc_enable_hystart_plus_plus;
        options->quic_pacing_enable = cfg->quic.pacing_enable;
        options->quic_max_ack_delay_ms = cfg->quic.max_ack_delay_ms;
        options->quic_ack_delay_exponent = cfg->quic.ack_delay_exponent;
        options->quic_initial_max_data = cfg->quic.initial_max_data;
        options->quic_initial_max_stream_data_bidi_local = cfg->quic.initial_max_stream_data_bidi_local;
        options->quic_initial_max_stream_data_bidi_remote = cfg->quic.initial_max_stream_data_bidi_remote;
        options->quic_initial_max_stream_data_uni = cfg->quic.initial_max_stream_data_uni;
        options->quic_initial_max_streams_bidi = cfg->quic.initial_max_streams_bidi;
        options->quic_initial_max_streams_uni = cfg->quic.initial_max_streams_uni;
        options->quic_active_connection_id_limit = cfg->quic.active_connection_id_limit;
        options->quic_grease_enable = cfg->quic.grease_enable;
        options->quic_datagrams_enable = cfg->quic.datagrams_enable;
        options->quic_dgram_recv_queue_len = cfg->quic.dgram_recv_queue_len;
        options->quic_dgram_send_queue_len = cfg->quic.dgram_send_queue_len;
    }

    if (options->tls_default_cert_file == NULL || options->tls_default_cert_file[0] == '\0') {
        king_server_local_set_errorf(
            "%s() requires 'tls_default_cert_file' for the on-wire HTTP/3 listener.",
            function_name
        );
        return FAILURE;
    }

    if (options->tls_default_key_file == NULL || options->tls_default_key_file[0] == '\0') {
        king_server_local_set_errorf(
            "%s() requires 'tls_default_key_file' for the on-wire HTTP/3 listener.",
            function_name
        );
        return FAILURE;
    }

    if (access(options->tls_default_cert_file, R_OK) != 0) {
        king_server_local_set_errorf(
            "%s() could not read the HTTP/3 certificate file '%s'.",
            function_name,
            options->tls_default_cert_file
        );
        return FAILURE;
    }

    if (access(options->tls_default_key_file, R_OK) != 0) {
        king_server_local_set_errorf(
            "%s() could not read the HTTP/3 key file '%s'.",
            function_name,
            options->tls_default_key_file
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_server_http3_runtime_destroy(king_server_http3_runtime_t *runtime)
{
    if (runtime->h3_conn != NULL && king_server_http3_quiche.quiche_h3_conn_free_fn != NULL) {
        king_server_http3_quiche.quiche_h3_conn_free_fn(runtime->h3_conn);
    }
    if (runtime->h3_config != NULL && king_server_http3_quiche.quiche_h3_config_free_fn != NULL) {
        king_server_http3_quiche.quiche_h3_config_free_fn(runtime->h3_config);
    }
    if (runtime->conn != NULL && king_server_http3_quiche.quiche_conn_free_fn != NULL) {
        king_server_http3_quiche.quiche_conn_free_fn(runtime->conn);
    }
    if (runtime->config != NULL && king_server_http3_quiche.quiche_config_free_fn != NULL) {
        king_server_http3_quiche.quiche_config_free_fn(runtime->config);
    }
    if (runtime->socket_fd >= 0) {
        close(runtime->socket_fd);
    }
}

static zend_result king_server_http3_fill_random_bytes(uint8_t *target, size_t target_len)
{
    int fd;
    ssize_t got;

    fd = open("/dev/urandom", O_RDONLY | O_CLOEXEC);
    if (fd < 0) {
        return FAILURE;
    }

    got = read(fd, target, target_len);
    close(fd);

    return got == (ssize_t) target_len ? SUCCESS : FAILURE;
}

static zend_long king_server_http3_now_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (zend_long) (ts.tv_sec * 1000LL + ts.tv_nsec / 1000000LL);
}

static zend_result king_server_http3_make_socket_nonblocking(int socket_fd)
{
    int flags = fcntl(socket_fd, F_GETFL, 0);

    if (flags < 0) {
        return FAILURE;
    }

    return fcntl(socket_fd, F_SETFL, flags | O_NONBLOCK) == 0
        ? SUCCESS
        : FAILURE;
}

static zend_result king_server_http3_set_socket_endpoint(
    zend_string **slot,
    zend_long *port_slot,
    int socket_fd,
    zend_bool peer,
    const char *function_name
)
{
    struct sockaddr_storage address;
    socklen_t address_len = sizeof(address);
    char host[NI_MAXHOST];
    char service[NI_MAXSERV];
    int rc;

    rc = peer
        ? getpeername(socket_fd, (struct sockaddr *) &address, &address_len)
        : getsockname(socket_fd, (struct sockaddr *) &address, &address_len);
    if (rc != 0) {
        king_server_local_set_errorf(
            "%s() failed to capture the accepted HTTP/3 socket endpoints (errno %d).",
            function_name,
            errno
        );
        return FAILURE;
    }

    if (
        getnameinfo(
            (struct sockaddr *) &address,
            address_len,
            host,
            sizeof(host),
            service,
            sizeof(service),
            NI_NUMERICHOST | NI_NUMERICSERV
        ) != 0
    ) {
        king_server_local_set_errorf(
            "%s() failed to normalize the accepted HTTP/3 socket endpoints.",
            function_name
        );
        return FAILURE;
    }

    king_server_local_set_string(slot, host);
    *port_slot = strtol(service, NULL, 10);
    return SUCCESS;
}

static zend_result king_server_http3_apply_transport_snapshot_from_socket(
    king_client_session_t *session,
    int socket_fd,
    const char *function_name
)
{
    session->transport_socket_fd = socket_fd;
    session->transport_has_socket = true;
    session->transport_last_errno = 0;
    session->transport_datagrams_enable = true;
    king_server_local_set_string(&session->negotiated_alpn, "h3");
    king_server_local_set_string(&session->transport_backend, "server_http3_socket");
    king_server_local_set_string(&session->transport_socket_family, "udp");
    king_server_local_set_string(&session->transport_error_scope, "none");

    if (
        king_server_http3_set_socket_endpoint(
            &session->transport_local_address,
            &session->transport_local_port,
            socket_fd,
            0,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    if (
        king_server_http3_set_socket_endpoint(
            &session->transport_peer_address,
            &session->transport_peer_port,
            socket_fd,
            1,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_server_http3_open_listener_socket(
    const char *host,
    zend_long port,
    king_server_http3_runtime_t *runtime,
    const char *function_name
)
{
    struct addrinfo hints;
    struct addrinfo *result = NULL;
    struct addrinfo *cursor;
    char service[16];
    int fd = -1;
    int rc;

    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_DGRAM;
    hints.ai_protocol = IPPROTO_UDP;
    hints.ai_flags = AI_ADDRCONFIG;

    snprintf(service, sizeof(service), "%ld", port);

    rc = getaddrinfo(host, service, &hints, &result);
    if (rc != 0) {
        king_server_local_set_errorf(
            "%s() failed to resolve the HTTP/3 bind address '%s:%ld': %s.",
            function_name,
            host,
            port,
            gai_strerror(rc)
        );
        return FAILURE;
    }

    for (cursor = result; cursor != NULL; cursor = cursor->ai_next) {
        int optval = 1;

        fd = socket(cursor->ai_family, cursor->ai_socktype, cursor->ai_protocol);
        if (fd < 0) {
            continue;
        }

        setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &optval, sizeof(optval));

        if (bind(fd, cursor->ai_addr, cursor->ai_addrlen) == 0) {
            break;
        }

        close(fd);
        fd = -1;
    }

    if (fd < 0) {
        freeaddrinfo(result);
        king_server_local_set_errorf(
            "%s() failed to bind the on-wire HTTP/3 listener socket (errno %d).",
            function_name,
            errno
        );
        return FAILURE;
    }

    if (king_server_http3_make_socket_nonblocking(fd) != SUCCESS) {
        freeaddrinfo(result);
        close(fd);
        king_server_local_set_errorf(
            "%s() failed to switch the HTTP/3 listener socket into non-blocking mode.",
            function_name
        );
        return FAILURE;
    }

    runtime->socket_fd = fd;
    runtime->local_addr_len = sizeof(runtime->local_addr);
    if (getsockname(fd, (struct sockaddr *) &runtime->local_addr, &runtime->local_addr_len) != 0) {
        freeaddrinfo(result);
        close(fd);
        runtime->socket_fd = -1;
        king_server_local_set_errorf(
            "%s() failed to inspect the bound HTTP/3 listener socket (errno %d).",
            function_name,
            errno
        );
        return FAILURE;
    }

    freeaddrinfo(result);
    return SUCCESS;
}

static zend_result king_server_http3_prepare_runtime_config(
    king_server_http3_runtime_t *runtime,
    king_server_http3_options_t *options,
    const char *function_name
)
{
    runtime->config = king_server_http3_quiche.quiche_config_new_fn(QUICHE_PROTOCOL_VERSION);
    if (runtime->config == NULL) {
        king_server_local_set_errorf(
            "%s() failed to allocate the libquiche server config.",
            function_name
        );
        return FAILURE;
    }

    if (king_server_http3_quiche.quiche_config_load_cert_chain_from_pem_file_fn(
            runtime->config,
            options->tls_default_cert_file
        ) != 0) {
        king_server_local_set_errorf(
            "%s() failed to load the HTTP/3 certificate '%s'.",
            function_name,
            options->tls_default_cert_file
        );
        return FAILURE;
    }

    if (king_server_http3_quiche.quiche_config_load_priv_key_from_pem_file_fn(
            runtime->config,
            options->tls_default_key_file
        ) != 0) {
        king_server_local_set_errorf(
            "%s() failed to load the HTTP/3 key '%s'.",
            function_name,
            options->tls_default_key_file
        );
        return FAILURE;
    }

    king_server_http3_quiche.quiche_config_grease_fn(runtime->config, options->quic_grease_enable);
    king_server_http3_quiche.quiche_config_enable_hystart_fn(runtime->config, options->quic_cc_enable_hystart_plus_plus);
    king_server_http3_quiche.quiche_config_enable_pacing_fn(runtime->config, options->quic_pacing_enable);

    if (king_server_http3_quiche.quiche_config_set_application_protos_fn(
            runtime->config,
            (const uint8_t *) QUICHE_H3_APPLICATION_PROTOCOL,
            sizeof(QUICHE_H3_APPLICATION_PROTOCOL) - 1
        ) != 0) {
        king_server_local_set_errorf(
            "%s() failed to configure the HTTP/3 ALPN list.",
            function_name
        );
        return FAILURE;
    }

    king_server_http3_quiche.quiche_config_set_max_idle_timeout_fn(runtime->config, (uint64_t) options->timeout_ms);
    king_server_http3_quiche.quiche_config_set_max_recv_udp_payload_size_fn(runtime->config, KING_SERVER_HTTP3_MAX_DATAGRAM_SIZE);
    king_server_http3_quiche.quiche_config_set_max_send_udp_payload_size_fn(runtime->config, KING_SERVER_HTTP3_MAX_DATAGRAM_SIZE);
    king_server_http3_quiche.quiche_config_set_initial_max_data_fn(runtime->config, (uint64_t) options->quic_initial_max_data);
    king_server_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_local_fn(runtime->config, (uint64_t) options->quic_initial_max_stream_data_bidi_local);
    king_server_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_remote_fn(runtime->config, (uint64_t) options->quic_initial_max_stream_data_bidi_remote);
    king_server_http3_quiche.quiche_config_set_initial_max_stream_data_uni_fn(runtime->config, (uint64_t) options->quic_initial_max_stream_data_uni);
    king_server_http3_quiche.quiche_config_set_initial_max_streams_bidi_fn(runtime->config, (uint64_t) options->quic_initial_max_streams_bidi);
    king_server_http3_quiche.quiche_config_set_initial_max_streams_uni_fn(runtime->config, (uint64_t) options->quic_initial_max_streams_uni);
    king_server_http3_quiche.quiche_config_set_ack_delay_exponent_fn(runtime->config, (uint64_t) options->quic_ack_delay_exponent);
    king_server_http3_quiche.quiche_config_set_max_ack_delay_fn(runtime->config, (uint64_t) options->quic_max_ack_delay_ms);
    king_server_http3_quiche.quiche_config_set_disable_active_migration_fn(runtime->config, true);
    king_server_http3_quiche.quiche_config_set_initial_congestion_window_packets_fn(runtime->config, (size_t) options->quic_cc_initial_cwnd_packets);
    king_server_http3_quiche.quiche_config_set_active_connection_id_limit_fn(runtime->config, (uint64_t) options->quic_active_connection_id_limit);
    king_server_http3_quiche.quiche_config_enable_dgram_fn(
        runtime->config,
        options->quic_datagrams_enable,
        (size_t) options->quic_dgram_recv_queue_len,
        (size_t) options->quic_dgram_send_queue_len
    );

    if (options->quic_cc_algorithm != NULL && options->quic_cc_algorithm[0] != '\0') {
        if (king_server_http3_quiche.quiche_config_set_cc_algorithm_name_fn(runtime->config, options->quic_cc_algorithm) != 0) {
            king_server_local_set_errorf(
                "%s() received an unsupported QUIC congestion-control algorithm '%s'.",
                function_name,
                options->quic_cc_algorithm
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

