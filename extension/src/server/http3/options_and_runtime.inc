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
    options->quic_cc_min_cwnd_packets = king_quic_transport_config.cc_min_cwnd_packets;
    options->quic_cc_enable_hystart_plus_plus = king_quic_transport_config.cc_enable_hystart_plus_plus;
    options->quic_pacing_enable = king_quic_transport_config.pacing_enable;
    options->quic_pacing_max_burst_packets = king_quic_transport_config.pacing_max_burst_packets;
    options->quic_max_ack_delay_ms = king_quic_transport_config.max_ack_delay_ms;
    options->quic_ack_delay_exponent = king_quic_transport_config.ack_delay_exponent;
    options->quic_pto_timeout_ms_initial = king_quic_transport_config.pto_timeout_ms_initial;
    options->quic_pto_timeout_ms_max = king_quic_transport_config.pto_timeout_ms_max;
    options->quic_max_pto_probes = king_quic_transport_config.max_pto_probes;
    options->quic_ping_interval_ms = king_quic_transport_config.ping_interval_ms;
    options->quic_initial_max_data = king_quic_transport_config.initial_max_data;
    options->quic_initial_max_stream_data_bidi_local = king_quic_transport_config.initial_max_stream_data_bidi_local;
    options->quic_initial_max_stream_data_bidi_remote = king_quic_transport_config.initial_max_stream_data_bidi_remote;
    options->quic_initial_max_stream_data_uni = king_quic_transport_config.initial_max_stream_data_uni;
    options->quic_initial_max_streams_bidi = king_quic_transport_config.initial_max_streams_bidi;
    options->quic_initial_max_streams_uni = king_quic_transport_config.initial_max_streams_uni;
    options->quic_active_connection_id_limit = king_quic_transport_config.active_connection_id_limit;
    options->quic_stateless_retry_enable = king_quic_transport_config.stateless_retry_enable;
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
        options->quic_cc_min_cwnd_packets = cfg->quic.cc_min_cwnd_packets;
        options->quic_cc_enable_hystart_plus_plus = cfg->quic.cc_enable_hystart_plus_plus;
        options->quic_pacing_enable = cfg->quic.pacing_enable;
        options->quic_pacing_max_burst_packets = cfg->quic.pacing_max_burst_packets;
        options->quic_max_ack_delay_ms = cfg->quic.max_ack_delay_ms;
        options->quic_ack_delay_exponent = cfg->quic.ack_delay_exponent;
        options->quic_pto_timeout_ms_initial = cfg->quic.pto_timeout_ms_initial;
        options->quic_pto_timeout_ms_max = cfg->quic.pto_timeout_ms_max;
        options->quic_max_pto_probes = cfg->quic.max_pto_probes;
        options->quic_ping_interval_ms = cfg->quic.ping_interval_ms;
        options->quic_initial_max_data = cfg->quic.initial_max_data;
        options->quic_initial_max_stream_data_bidi_local = cfg->quic.initial_max_stream_data_bidi_local;
        options->quic_initial_max_stream_data_bidi_remote = cfg->quic.initial_max_stream_data_bidi_remote;
        options->quic_initial_max_stream_data_uni = cfg->quic.initial_max_stream_data_uni;
        options->quic_initial_max_streams_bidi = cfg->quic.initial_max_streams_bidi;
        options->quic_initial_max_streams_uni = cfg->quic.initial_max_streams_uni;
        options->quic_active_connection_id_limit = cfg->quic.active_connection_id_limit;
        options->quic_stateless_retry_enable = cfg->quic.stateless_retry_enable;
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
#if defined(KING_HTTP3_BACKEND_LSQUIC)
    if (runtime->lsquic_backend_active) {
        if (runtime->lsquic_conn != NULL
            && !runtime->lsquic_connection_closed
            && king_server_http3_lsquic.lsquic_conn_close_fn != NULL) {
            king_server_http3_lsquic.lsquic_conn_close_fn(runtime->lsquic_conn);
            if (runtime->lsquic_engine != NULL
                && king_server_http3_lsquic.lsquic_engine_process_conns_fn != NULL) {
                king_server_http3_lsquic.lsquic_engine_process_conns_fn(runtime->lsquic_engine);
            }
        }

        if (runtime->lsquic_engine != NULL
            && king_server_http3_lsquic.lsquic_engine_destroy_fn != NULL) {
            king_server_http3_lsquic.lsquic_engine_destroy_fn(runtime->lsquic_engine);
        }

        if (runtime->lsquic_ssl_ctx != NULL) {
            SSL_CTX_free(runtime->lsquic_ssl_ctx);
        }

        if (runtime->lsquic_stream_state != NULL) {
            efree(runtime->lsquic_stream_state);
        }

        runtime->lsquic_engine = NULL;
        runtime->lsquic_conn = NULL;
        runtime->lsquic_ssl_ctx = NULL;
        runtime->lsquic_session = NULL;
        runtime->lsquic_stream_state = NULL;
        runtime->lsquic_backend_active = false;
    }
#endif

    if (runtime->socket_fd >= 0) {
        close(runtime->socket_fd);
    }
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

static zend_result king_server_http3_set_address_endpoint(
    zend_string **slot,
    zend_long *port_slot,
    const struct sockaddr *address,
    socklen_t address_len,
    const char *function_name
)
{
    char host[NI_MAXHOST];
    char service[NI_MAXSERV];

    if (
        getnameinfo(
            address,
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

static zend_result king_server_http3_apply_transport_snapshot_from_runtime(
    king_client_session_t *session,
    const king_server_http3_runtime_t *runtime,
    const char *backend_name,
    const char *function_name
)
{
    session->transport_socket_fd = runtime->socket_fd;
    session->transport_has_socket = true;
    session->transport_last_errno = 0;
    session->transport_datagrams_enable = true;
    king_server_local_set_string(&session->negotiated_alpn, "h3");
    king_server_local_set_string(&session->transport_backend, backend_name);
    king_server_local_set_string(&session->transport_socket_family, "udp");
    king_server_local_set_string(&session->transport_error_scope, "none");

    if (
        king_server_http3_set_address_endpoint(
            &session->transport_local_address,
            &session->transport_local_port,
            (const struct sockaddr *) &runtime->local_addr,
            runtime->local_addr_len,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    if (
        king_server_http3_set_address_endpoint(
            &session->transport_peer_address,
            &session->transport_peer_port,
            (const struct sockaddr *) &runtime->peer_addr,
            runtime->peer_addr_len,
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
