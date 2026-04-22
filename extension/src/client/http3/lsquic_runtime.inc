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

static void king_http3_lsquic_refresh_transport_stats(
    king_http3_request_runtime_t *runtime)
{
    if (runtime == NULL || !runtime->lsquic_backend_active) {
        return;
    }

    runtime->quic_packets_lost = 0;
    runtime->quic_packets_retransmitted = 0;
    runtime->quic_lost_bytes = 0;
    runtime->quic_stream_retransmitted_bytes = 0;
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

static zend_result king_http3_lsquic_runtime_prepare_request(
    king_http3_request_runtime_t *runtime,
    king_http3_lsquic_request_state_t *request,
    const quiche_h3_header *headers,
    size_t header_count,
    zend_string *body_string,
    king_http3_response_t *response,
    const char *function_name)
{
    if (runtime == NULL
        || request == NULL
        || runtime->lsquic_conn == NULL
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

    memset(request, 0, sizeof(*request));
    request->runtime = runtime;
    request->headers = headers;
    request->header_count = header_count;
    request->body_string = body_string;
    request->body_offset = 0;
    request->response = response;
    runtime->lsquic_pending_request = request;

    king_http3_lsquic.lsquic_conn_make_stream_fn(runtime->lsquic_conn);
    return king_http3_lsquic_runtime_process_egress(runtime, function_name);
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

    king_http3_lsquic.lsquic_engine_init_settings_fn(&runtime->lsquic_settings, engine_flags);
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
    runtime->lsquic_pending_request = NULL;
    runtime->lsquic_backend_active = false;
}

#endif
