#if defined(KING_HTTP3_BACKEND_LSQUIC)

static void king_http3_lsquic_seed_ticket_from_ring(
    king_http3_request_runtime_t *runtime)
{
    size_t ticket_len;

    if (runtime == NULL) {
        return;
    }

    ticket_len = sizeof(runtime->lsquic_session_resume);
    if (king_ticket_ring_get(runtime->lsquic_session_resume, &ticket_len) == 1
        && ticket_len > 0
        && ticket_len <= sizeof(runtime->lsquic_session_resume)) {
        runtime->lsquic_session_resume_len = ticket_len;
        runtime->tls_ticket_source = "ring";
        return;
    }

    runtime->lsquic_session_resume_len = 0;
}

static zend_long king_http3_lsquic_uint64_to_zend_long(uint64_t value)
{
    return (zend_long) MIN(value, (uint64_t) ZEND_LONG_MAX);
}

static zend_long king_http3_lsquic_estimate_packet_bytes(
    uint64_t total_bytes,
    uint64_t event_packets,
    uint64_t total_packets)
{
    uint64_t estimated;

    if (total_bytes == 0 || event_packets == 0 || total_packets == 0) {
        return 0;
    }

    if (event_packets >= total_packets) {
        return king_http3_lsquic_uint64_to_zend_long(total_bytes);
    }

    estimated = (total_bytes / total_packets) * event_packets;
    if (estimated == 0) {
        estimated = 1;
    }

    return king_http3_lsquic_uint64_to_zend_long(estimated);
}

static bool king_http3_lsquic_has_path_option(const char *path)
{
    return path != NULL && path[0] != '\0';
}

static int king_http3_lsquic_load_verify_store(
    X509_STORE *store,
    const char *ca_file)
{
    struct stat st;

    if (store == NULL) {
        return -1;
    }

    if (!king_http3_lsquic_has_path_option(ca_file)) {
        return X509_STORE_set_default_paths(store) == 1 ? 0 : -1;
    }

    if (stat(ca_file, &st) != 0) {
        return -1;
    }

    if (S_ISDIR(st.st_mode)) {
        return X509_STORE_load_locations(store, NULL, ca_file) == 1 ? 0 : -1;
    }

    return X509_STORE_load_locations(store, ca_file, NULL) == 1 ? 0 : -1;
}

static bool king_http3_lsquic_verify_host(
    X509 *cert,
    const char *host)
{
    unsigned char ip_buffer[16];

    if (cert == NULL || host == NULL || host[0] == '\0') {
        return false;
    }

    if (inet_pton(AF_INET, host, ip_buffer) == 1
        || inet_pton(AF_INET6, host, ip_buffer) == 1) {
        return X509_check_ip_asc(cert, host, 0) == 1;
    }

    return X509_check_host(cert, host, strlen(host), 0, NULL) == 1;
}

static int king_http3_lsquic_verify_server_cert(
    void *verify_ctx,
    STACK_OF(X509) *chain)
{
    king_http3_request_runtime_t *runtime = (king_http3_request_runtime_t *) verify_ctx;
    X509_STORE *store = NULL;
    X509_STORE_CTX *store_ctx = NULL;
    STACK_OF(X509) *untrusted_chain = NULL;
    X509 *cert = NULL;
    int verified = 0;

    if (runtime == NULL || !runtime->lsquic_tls_verify_peer) {
        return 0;
    }

    if (chain == NULL || sk_X509_num(chain) <= 0) {
        runtime->lsquic_tls_verify_failed = true;
        return -1;
    }

    store = X509_STORE_new();
    store_ctx = X509_STORE_CTX_new();
    untrusted_chain = sk_X509_dup(chain);
    cert = sk_X509_value(chain, 0);

    if (store == NULL
        || store_ctx == NULL
        || untrusted_chain == NULL
        || cert == NULL
        || king_http3_lsquic_load_verify_store(store, runtime->lsquic_tls_ca_file) != 0) {
        goto done;
    }

    (void) sk_X509_shift(untrusted_chain);
    if (X509_STORE_CTX_init(store_ctx, store, cert, untrusted_chain) != 1) {
        goto done;
    }

    if (X509_verify_cert(store_ctx) != 1) {
        goto done;
    }

    if (!king_http3_lsquic_verify_host(cert, runtime->lsquic_tls_verify_host)) {
        goto done;
    }

    verified = 1;

done:
    if (untrusted_chain != NULL) {
        sk_X509_free(untrusted_chain);
    }
    if (store_ctx != NULL) {
        X509_STORE_CTX_free(store_ctx);
    }
    if (store != NULL) {
        X509_STORE_free(store);
    }

    if (verified != 1) {
        runtime->lsquic_tls_verify_failed = true;
        return -1;
    }

    return 0;
}

static void king_http3_lsquic_refresh_transport_stats(
    king_http3_request_runtime_t *runtime)
{
    struct lsquic_conn_info info;

    if (runtime == NULL
        || !runtime->lsquic_backend_active
        || runtime->lsquic_conn == NULL
        || king_http3_lsquic.lsquic_conn_get_info_fn == NULL) {
        return;
    }

    memset(&info, 0, sizeof(info));
    if (king_http3_lsquic.lsquic_conn_get_info_fn(runtime->lsquic_conn, &info) != 0) {
        return;
    }

    runtime->quic_packets_sent = king_http3_lsquic_uint64_to_zend_long(info.lci_pkts_sent);
    runtime->quic_packets_received = king_http3_lsquic_uint64_to_zend_long(info.lci_pkts_rcvd);
    runtime->quic_packets_lost = king_http3_lsquic_uint64_to_zend_long(info.lci_pkts_lost);
    runtime->quic_packets_retransmitted = king_http3_lsquic_uint64_to_zend_long(info.lci_pkts_retx);
    runtime->quic_lost_bytes = king_http3_lsquic_estimate_packet_bytes(
        info.lci_bytes_sent,
        info.lci_pkts_lost,
        info.lci_pkts_sent
    );
    runtime->quic_stream_retransmitted_bytes = king_http3_lsquic_estimate_packet_bytes(
        info.lci_bytes_sent,
        info.lci_pkts_retx,
        info.lci_pkts_sent
    );
}

static int king_http3_lsquic_runtime_packet_in(
    king_http3_request_runtime_t *runtime,
    const unsigned char *packet,
    size_t packet_len,
    const struct sockaddr *from,
    int ecn)
{
    int result;

    if (runtime == NULL
        || runtime->lsquic_engine == NULL
        || packet == NULL
        || packet_len == 0) {
        errno = EINVAL;
        return -1;
    }

    result = king_http3_lsquic.lsquic_engine_packet_in_fn(
        runtime->lsquic_engine,
        packet,
        packet_len,
        (struct sockaddr *) &runtime->local_addr,
        from,
        runtime,
        ecn
    );

    if (result >= 0) {
        runtime->quic_packets_received++;
    }

    return result;
}

static zend_result king_http3_lsquic_runtime_process_egress(
    king_http3_request_runtime_t *runtime,
    const char *function_name)
{
    unsigned guard = 0;

    if (runtime == NULL || runtime->lsquic_engine == NULL) {
        king_http3_throw(
            king_ce_quic_exception,
            "%s() cannot process LSQUIC egress without an initialized engine.",
            function_name
        );
        return FAILURE;
    }

    king_http3_lsquic.lsquic_engine_process_conns_fn(runtime->lsquic_engine);
    while (king_http3_lsquic.lsquic_engine_has_unsent_packets_fn(runtime->lsquic_engine)) {
        king_http3_lsquic.lsquic_engine_send_unsent_packets_fn(runtime->lsquic_engine);
        if (++guard > 1024) {
            king_http3_throw(
                king_ce_quic_exception,
                "%s() LSQUIC egress did not drain within the safety limit.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static void king_http3_lsquic_request_state_init(
    king_http3_lsquic_request_state_t *request,
    king_http3_request_runtime_t *runtime,
    const king_http3_header_t *headers,
    size_t header_count,
    zend_string *body_string,
    king_http3_response_t *response)
{
    memset(request, 0, sizeof(*request));
    request->runtime = runtime;
    request->headers = headers;
    request->header_count = header_count;
    request->body_string = body_string;
    request->body_offset = 0;
    request->response = response;
}

static zend_result king_http3_lsquic_runtime_prepare_requests(
    king_http3_request_runtime_t *runtime,
    king_http3_lsquic_request_state_t *requests,
    size_t request_count,
    const char *function_name)
{
    if (runtime == NULL
        || requests == NULL
        || request_count == 0
        || runtime->lsquic_conn == NULL
        || king_http3_lsquic.lsquic_conn_make_stream_fn == NULL) {
        king_http3_throw(
            king_ce_protocol_exception,
            "%s() cannot prepare LSQUIC request streams without request state and connection context.",
            function_name
        );
        return FAILURE;
    }

    for (size_t i = 0; i < request_count; ++i) {
        if (requests[i].headers == NULL
            || requests[i].header_count == 0
            || requests[i].response == NULL) {
            king_http3_throw(
                king_ce_protocol_exception,
                "%s() cannot prepare LSQUIC request stream #%zu without headers and response state.",
                function_name,
                i + 1
            );
            return FAILURE;
        }
    }

    runtime->lsquic_pending_requests = requests;
    runtime->lsquic_pending_request_count = request_count;
    runtime->lsquic_pending_request_offset = 0;

    for (size_t i = 0; i < request_count; ++i) {
        king_http3_lsquic.lsquic_conn_make_stream_fn(runtime->lsquic_conn);
    }

    return king_http3_lsquic_runtime_process_egress(runtime, function_name);
}

static zend_result king_http3_lsquic_runtime_prepare_request(
    king_http3_request_runtime_t *runtime,
    king_http3_lsquic_request_state_t *request,
    const king_http3_header_t *headers,
    size_t header_count,
    zend_string *body_string,
    king_http3_response_t *response,
    const char *function_name)
{
    if (runtime == NULL
        || request == NULL
        || headers == NULL
        || header_count == 0
        || response == NULL) {
        king_http3_throw(
            king_ce_protocol_exception,
            "%s() cannot prepare LSQUIC request state without headers, response, and connection context.",
            function_name
        );
        return FAILURE;
    }

    king_http3_lsquic_request_state_init(
        request,
        runtime,
        headers,
        header_count,
        body_string,
        response
    );

    return king_http3_lsquic_runtime_prepare_requests(
        runtime,
        request,
        1,
        function_name
    );
}

static zend_long king_http3_lsquic_poll_timeout_ms(
    king_http3_request_runtime_t *runtime,
    zend_long fallback_ms)
{
    int diff_us = 0;

    if (runtime == NULL
        || runtime->lsquic_engine == NULL
        || king_http3_lsquic.lsquic_engine_earliest_adv_tick_fn == NULL
        || !king_http3_lsquic.lsquic_engine_earliest_adv_tick_fn(runtime->lsquic_engine, &diff_us)) {
        return fallback_ms;
    }

    if (diff_us <= 0) {
        return 0;
    }

    return (zend_long) ((diff_us + 999) / 1000);
}

static zend_result king_http3_lsquic_runtime_init(
    king_http3_request_runtime_t *runtime,
    const king_http3_request_target_t *target,
    const king_http3_request_options_t *options,
    const char *function_name)
{
    const unsigned engine_flags = LSENG_HTTP;
    char settings_error[256];
    unsigned idle_timeout_seconds;

    if (runtime == NULL || target == NULL || options == NULL) {
        king_http3_throw(
            king_ce_quic_exception,
            "%s() cannot initialize LSQUIC with an incomplete runtime context.",
            function_name
        );
        return FAILURE;
    }

    runtime->lsquic_backend_active = true;
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
    runtime->lsquic_tls_verify_peer = options->tls_verify_peer;
    runtime->lsquic_tls_verify_failed = false;
    runtime->lsquic_tls_ca_file = options->tls_default_ca_file;
    runtime->lsquic_tls_verify_host = ZSTR_VAL(target->host);

    king_http3_lsquic.lsquic_engine_init_settings_fn(&runtime->lsquic_settings, engine_flags);
    if (king_http3_lsquic_apply_quic_options(runtime, options, function_name) != SUCCESS) {
        return FAILURE;
    }

    idle_timeout_seconds = (unsigned) ((options->timeout_ms + 999) / 1000);
    if (idle_timeout_seconds == 0) {
        idle_timeout_seconds = 1;
    } else if (idle_timeout_seconds > 600) {
        idle_timeout_seconds = 600;
    }
    runtime->lsquic_settings.es_idle_timeout = idle_timeout_seconds;
    runtime->lsquic_settings.es_idle_conn_to = (unsigned long) options->timeout_ms * 1000UL;

    memset(settings_error, 0, sizeof(settings_error));
    if (king_http3_lsquic.lsquic_engine_check_settings_fn(
            &runtime->lsquic_settings,
            engine_flags,
            settings_error,
            sizeof(settings_error)
        ) != 0) {
        king_http3_throw(
            king_ce_validation_exception,
            "%s() received an invalid LSQUIC settings profile: %s",
            function_name,
            settings_error[0] != '\0' ? settings_error : "unknown settings error"
        );
        return FAILURE;
    }

    memset(&runtime->lsquic_api, 0, sizeof(runtime->lsquic_api));
    runtime->lsquic_api.ea_settings = &runtime->lsquic_settings;
    runtime->lsquic_api.ea_stream_if = &king_http3_lsquic_stream_if;
    runtime->lsquic_api.ea_stream_if_ctx = runtime;
    runtime->lsquic_api.ea_packets_out = king_http3_lsquic_packets_out;
    runtime->lsquic_api.ea_packets_out_ctx = runtime;
    runtime->lsquic_api.ea_hsi_if = &king_http3_lsquic_hset_if;
    runtime->lsquic_api.ea_hsi_ctx = runtime;
    if (options->tls_verify_peer) {
        runtime->lsquic_api.ea_verify_cert = king_http3_lsquic_verify_server_cert;
        runtime->lsquic_api.ea_verify_ctx = runtime;
    }

    runtime->lsquic_engine = (lsquic_engine_t *) king_http3_lsquic.lsquic_engine_new_fn(
        engine_flags,
        &runtime->lsquic_api
    );
    if (runtime->lsquic_engine == NULL) {
        king_http3_throw(
            king_ce_quic_exception,
            "%s() failed to allocate the active LSQUIC engine.",
            function_name
        );
        return FAILURE;
    }

    king_http3_lsquic_seed_ticket_from_ring(runtime);
    runtime->lsquic_conn = (lsquic_conn_t *) king_http3_lsquic.lsquic_engine_connect_fn(
        runtime->lsquic_engine,
        N_LSQVER,
        (struct sockaddr *) &runtime->local_addr,
        (struct sockaddr *) &runtime->peer_addr,
        runtime,
        (lsquic_conn_ctx_t *) runtime,
        ZSTR_VAL(target->host),
        0,
        runtime->lsquic_session_resume_len > 0 ? runtime->lsquic_session_resume : NULL,
        runtime->lsquic_session_resume_len,
        NULL,
        0
    );
    if (runtime->lsquic_conn == NULL) {
        king_http3_throw(
            king_ce_quic_exception,
            "%s() failed to create the active LSQUIC client connection.",
            function_name
        );
        return FAILURE;
    }

    return king_http3_lsquic_runtime_process_egress(runtime, function_name);
}

static void king_http3_lsquic_runtime_destroy(
    king_http3_request_runtime_t *runtime)
{
    if (runtime == NULL || !runtime->lsquic_backend_active) {
        return;
    }

    if (runtime->lsquic_conn != NULL
        && !runtime->lsquic_connection_closed
        && king_http3_lsquic.lsquic_conn_close_fn != NULL) {
        king_http3_lsquic.lsquic_conn_close_fn(runtime->lsquic_conn);
        (void) king_http3_lsquic_runtime_process_egress(runtime, "king_http3_runtime_destroy");
    }

    if (runtime->lsquic_engine != NULL
        && king_http3_lsquic.lsquic_engine_destroy_fn != NULL) {
        king_http3_lsquic.lsquic_engine_destroy_fn(runtime->lsquic_engine);
        runtime->lsquic_engine = NULL;
    }

    king_secure_zero(runtime->lsquic_session_resume, sizeof(runtime->lsquic_session_resume));
    runtime->lsquic_session_resume_len = 0;
    runtime->lsquic_conn = NULL;
    runtime->lsquic_pending_requests = NULL;
    runtime->lsquic_pending_request_count = 0;
    runtime->lsquic_pending_request_offset = 0;
    runtime->lsquic_backend_active = false;
}

#endif
