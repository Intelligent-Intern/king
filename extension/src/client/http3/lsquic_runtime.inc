#if defined(KING_HTTP3_BACKEND_LSQUIC)

static socklen_t king_http3_lsquic_sockaddr_len(const struct sockaddr *addr)
{
    if (addr == NULL) {
        return 0;
    }

    switch (addr->sa_family) {
        case AF_INET:
            return sizeof(struct sockaddr_in);
        case AF_INET6:
            return sizeof(struct sockaddr_in6);
        default:
            return 0;
    }
}

static king_http3_request_runtime_t *king_http3_lsquic_runtime_from_conn(
    lsquic_conn_t *conn)
{
    if (conn == NULL || king_http3_lsquic.lsquic_conn_get_ctx_fn == NULL) {
        return NULL;
    }

    return (king_http3_request_runtime_t *) king_http3_lsquic.lsquic_conn_get_ctx_fn(conn);
}

static king_http3_request_runtime_t *king_http3_lsquic_runtime_from_stream_ctx(
    lsquic_stream_ctx_t *stream_ctx)
{
    return (king_http3_request_runtime_t *) stream_ctx;
}

static int king_http3_lsquic_packets_out(
    void *packets_out_ctx,
    const struct lsquic_out_spec *out_spec,
    unsigned n_packets_out)
{
    king_http3_request_runtime_t *runtime = (king_http3_request_runtime_t *) packets_out_ctx;
    unsigned sent_count = 0;

    if (runtime == NULL || runtime->socket_fd < 0 || out_spec == NULL) {
        errno = EINVAL;
        return -1;
    }

    for (unsigned i = 0; i < n_packets_out; ++i) {
        struct msghdr message;
        socklen_t dest_len = king_http3_lsquic_sockaddr_len(out_spec[i].dest_sa);
        ssize_t sent;

        if (dest_len == 0) {
            errno = EINVAL;
            return sent_count == 0 ? -1 : (int) sent_count;
        }

        memset(&message, 0, sizeof(message));
        message.msg_name = (void *) out_spec[i].dest_sa;
        message.msg_namelen = dest_len;
        message.msg_iov = out_spec[i].iov;
        message.msg_iovlen = out_spec[i].iovlen;

        sent = sendmsg(runtime->socket_fd, &message, 0);
        if (sent < 0) {
            return sent_count == 0 ? -1 : (int) sent_count;
        }

        runtime->quic_packets_sent++;
        sent_count++;
    }

    return (int) sent_count;
}

static lsquic_conn_ctx_t *king_http3_lsquic_on_new_conn(
    void *stream_if_ctx,
    lsquic_conn_t *conn)
{
    king_http3_request_runtime_t *runtime = (king_http3_request_runtime_t *) stream_if_ctx;

    if (runtime != NULL) {
        runtime->lsquic_conn = conn;
    }

    return (lsquic_conn_ctx_t *) runtime;
}

static void king_http3_lsquic_on_conn_closed(lsquic_conn_t *conn)
{
    king_http3_request_runtime_t *runtime = king_http3_lsquic_runtime_from_conn(conn);

    if (runtime != NULL) {
        runtime->lsquic_connection_closed = true;
    }
}

static lsquic_stream_ctx_t *king_http3_lsquic_on_new_stream(
    void *stream_if_ctx,
    lsquic_stream_t *stream)
{
    king_http3_request_runtime_t *runtime = (king_http3_request_runtime_t *) stream_if_ctx;

    if (runtime != NULL) {
        runtime->lsquic_stream = stream;
        runtime->lsquic_stream_closed = stream == NULL;
        if (stream != NULL && king_http3_lsquic.lsquic_stream_wantwrite_fn != NULL) {
            king_http3_lsquic.lsquic_stream_wantwrite_fn(stream, 1);
        }
    }

    return (lsquic_stream_ctx_t *) runtime;
}

static void king_http3_lsquic_on_read(
    lsquic_stream_t *stream,
    lsquic_stream_ctx_t *stream_ctx)
{
    (void) stream;
    (void) stream_ctx;
}

static void king_http3_lsquic_on_write(
    lsquic_stream_t *stream,
    lsquic_stream_ctx_t *stream_ctx)
{
    (void) stream;
    (void) stream_ctx;
}

static void king_http3_lsquic_on_close(
    lsquic_stream_t *stream,
    lsquic_stream_ctx_t *stream_ctx)
{
    king_http3_request_runtime_t *runtime = king_http3_lsquic_runtime_from_stream_ctx(stream_ctx);

    (void) stream;

    if (runtime != NULL) {
        runtime->lsquic_stream_closed = true;
        if (runtime->lsquic_stream == stream) {
            runtime->lsquic_stream = NULL;
        }
    }
}

static void king_http3_lsquic_on_hsk_done(
    lsquic_conn_t *conn,
    enum lsquic_hsk_status status)
{
    king_http3_request_runtime_t *runtime = king_http3_lsquic_runtime_from_conn(conn);

    if (runtime == NULL) {
        return;
    }

    runtime->tls_session_resumed = status == LSQ_HSK_RESUMED_OK;
}

static void king_http3_lsquic_on_sess_resume_info(
    lsquic_conn_t *conn,
    const unsigned char *session,
    size_t session_len)
{
    king_http3_request_runtime_t *runtime = king_http3_lsquic_runtime_from_conn(conn);

    if (runtime == NULL
        || session == NULL
        || session_len == 0
        || session_len > KING_MAX_TICKET_SIZE) {
        return;
    }

    runtime->tls_has_session_ticket = true;
    runtime->tls_session_ticket_length = (zend_long) session_len;

    if (!runtime->tls_ticket_published) {
        king_ticket_ring_put(session, session_len);
        runtime->tls_ticket_published = true;
    }
}

static const struct lsquic_stream_if king_http3_lsquic_stream_if = {
    .on_new_conn = king_http3_lsquic_on_new_conn,
    .on_conn_closed = king_http3_lsquic_on_conn_closed,
    .on_new_stream = king_http3_lsquic_on_new_stream,
    .on_read = king_http3_lsquic_on_read,
    .on_write = king_http3_lsquic_on_write,
    .on_close = king_http3_lsquic_on_close,
    .on_hsk_done = king_http3_lsquic_on_hsk_done,
    .on_sess_resume_info = king_http3_lsquic_on_sess_resume_info,
};

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
    runtime->lsquic_stream = NULL;
    runtime->lsquic_backend_active = false;
}

#endif
