#if defined(KING_HTTP3_BACKEND_LSQUIC)

typedef struct _king_http3_lsquic_header_set {
    king_http3_request_runtime_t *runtime;
    char *buffer;
    size_t buffer_size;
    struct lsxpack_header header;
} king_http3_lsquic_header_set_t;

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

static void king_http3_lsquic_header_set_destroy(
    king_http3_lsquic_header_set_t *set)
{
    if (set == NULL) {
        return;
    }

    if (set->buffer != NULL) {
        efree(set->buffer);
    }
    efree(set);
}

static void *king_http3_lsquic_hsi_create_header_set(
    void *hsi_ctx,
    lsquic_stream_t *stream,
    int is_push_promise)
{
    king_http3_request_runtime_t *runtime = (king_http3_request_runtime_t *) hsi_ctx;
    king_http3_lsquic_header_set_t *set;

    (void) stream;
    (void) is_push_promise;

    set = ecalloc(1, sizeof(*set));
    set->runtime = runtime;
    return set;
}

static struct lsxpack_header *king_http3_lsquic_hsi_prepare_decode(
    void *hdr_set,
    struct lsxpack_header *hdr,
    size_t space)
{
    king_http3_lsquic_header_set_t *set = (king_http3_lsquic_header_set_t *) hdr_set;

    (void) hdr;

    if (set == NULL) {
        return NULL;
    }

    if (space == 0) {
        space = 1;
    }

    if (space > set->buffer_size) {
        set->buffer = set->buffer == NULL
            ? emalloc(space)
            : erealloc(set->buffer, space);
        set->buffer_size = space;
    }

    lsxpack_header_prepare_decode(&set->header, set->buffer, 0, space);
    return &set->header;
}

static int king_http3_lsquic_hsi_process_header(
    void *hdr_set,
    struct lsxpack_header *hdr)
{
    king_http3_lsquic_header_set_t *set = (king_http3_lsquic_header_set_t *) hdr_set;
    king_http3_response_header_context_t context;
    const char *name;
    const char *value;

    if (hdr == NULL) {
        return 0;
    }

    if (set == NULL
        || set->runtime == NULL
        || set->runtime->lsquic_response == NULL) {
        return -1;
    }

    name = lsxpack_header_get_name(hdr);
    value = lsxpack_header_get_value(hdr);
    if (name == NULL || value == NULL) {
        return -1;
    }

    context.response = set->runtime->lsquic_response;
    return king_http3_collect_response_header(
        (uint8_t *) name,
        hdr->name_len,
        (uint8_t *) value,
        hdr->val_len,
        &context
    );
}

static void king_http3_lsquic_hsi_discard_header_set(void *hdr_set)
{
    king_http3_lsquic_header_set_destroy((king_http3_lsquic_header_set_t *) hdr_set);
}

static const struct lsquic_hset_if king_http3_lsquic_hset_if = {
    .hsi_create_header_set = king_http3_lsquic_hsi_create_header_set,
    .hsi_prepare_decode = king_http3_lsquic_hsi_prepare_decode,
    .hsi_process_header = king_http3_lsquic_hsi_process_header,
    .hsi_discard_header_set = king_http3_lsquic_hsi_discard_header_set,
};

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

static zend_result king_http3_lsquic_send_headers(
    king_http3_request_runtime_t *runtime,
    lsquic_stream_t *stream)
{
    struct lsxpack_header *headers;
    char **buffers;
    lsquic_http_headers_t header_list;
    int result;

    if (runtime == NULL
        || stream == NULL
        || runtime->lsquic_request_headers == NULL
        || runtime->lsquic_request_header_count == 0) {
        return FAILURE;
    }

    headers = safe_emalloc(runtime->lsquic_request_header_count, sizeof(*headers), 0);
    buffers = safe_emalloc(runtime->lsquic_request_header_count, sizeof(*buffers), 0);

    for (size_t i = 0; i < runtime->lsquic_request_header_count; ++i) {
        const quiche_h3_header *source = &runtime->lsquic_request_headers[i];
        size_t buffer_len = source->name_len + source->value_len;

        buffers[i] = safe_emalloc(buffer_len, sizeof(char), 0);
        memcpy(buffers[i], source->name, source->name_len);
        memcpy(buffers[i] + source->name_len, source->value, source->value_len);
        lsxpack_header_set_offset2(
            &headers[i],
            buffers[i],
            0,
            source->name_len,
            source->name_len,
            source->value_len
        );
    }

    header_list.count = (int) runtime->lsquic_request_header_count;
    header_list.headers = headers;
    result = king_http3_lsquic.lsquic_stream_send_headers_fn(
        stream,
        &header_list,
        runtime->lsquic_body_string == NULL || ZSTR_LEN(runtime->lsquic_body_string) == 0
    );

    for (size_t i = 0; i < runtime->lsquic_request_header_count; ++i) {
        efree(buffers[i]);
    }
    efree(buffers);
    efree(headers);

    if (result != 0) {
        runtime->lsquic_request_failed = true;
        return FAILURE;
    }

    runtime->lsquic_headers_sent = true;
    return SUCCESS;
}

static zend_result king_http3_lsquic_write_body(
    king_http3_request_runtime_t *runtime,
    lsquic_stream_t *stream)
{
    if (runtime == NULL || stream == NULL) {
        return FAILURE;
    }

    if (runtime->lsquic_body_string != NULL) {
        while (runtime->lsquic_body_offset < ZSTR_LEN(runtime->lsquic_body_string)) {
            ssize_t written = king_http3_lsquic.lsquic_stream_write_fn(
                stream,
                ZSTR_VAL(runtime->lsquic_body_string) + runtime->lsquic_body_offset,
                ZSTR_LEN(runtime->lsquic_body_string) - runtime->lsquic_body_offset
            );

            if (written < 0) {
                runtime->lsquic_request_failed = true;
                return FAILURE;
            }
            if (written == 0) {
                break;
            }

            runtime->lsquic_body_offset += (size_t) written;
        }

        if (runtime->lsquic_body_offset < ZSTR_LEN(runtime->lsquic_body_string)) {
            return SUCCESS;
        }
    }

    if (king_http3_lsquic.lsquic_stream_shutdown_fn(stream, 1) != 0) {
        runtime->lsquic_request_failed = true;
        return FAILURE;
    }

    king_http3_lsquic.lsquic_stream_wantwrite_fn(stream, 0);
    king_http3_lsquic.lsquic_stream_wantread_fn(stream, 1);
    return king_http3_lsquic.lsquic_stream_flush_fn(stream) == 0 ? SUCCESS : FAILURE;
}

static zend_result king_http3_lsquic_read_response(
    king_http3_request_runtime_t *runtime,
    lsquic_stream_t *stream)
{
    uint8_t buffer[4096];

    if (runtime == NULL || stream == NULL || runtime->lsquic_response == NULL) {
        return FAILURE;
    }

    if (!runtime->lsquic_hset_claimed) {
        king_http3_lsquic_header_set_t *set =
            (king_http3_lsquic_header_set_t *) king_http3_lsquic.lsquic_stream_get_hset_fn(stream);
        king_http3_lsquic_header_set_destroy(set);
        runtime->lsquic_hset_claimed = true;
    }

    for (;;) {
        ssize_t read = king_http3_lsquic.lsquic_stream_read_fn(stream, buffer, sizeof(buffer));

        if (read < 0) {
            if (errno == EWOULDBLOCK || errno == EAGAIN) {
                return SUCCESS;
            }
            runtime->lsquic_request_failed = true;
            return FAILURE;
        }

        if (read == 0) {
            runtime->lsquic_response->response_complete = true;
            king_http3_lsquic.lsquic_stream_wantread_fn(stream, 0);
            return SUCCESS;
        }

        runtime->lsquic_response->body_bytes += (size_t) read;
        if (runtime->lsquic_response->body_bytes > KING_HTTP3_MAX_RESPONSE_BYTES) {
            runtime->lsquic_response->body_overflowed = true;
            runtime->lsquic_request_failed = true;
            return FAILURE;
        }

        smart_str_appendl(&runtime->lsquic_response->body, (const char *) buffer, (size_t) read);
    }
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
    king_http3_request_runtime_t *runtime = king_http3_lsquic_runtime_from_stream_ctx(stream_ctx);

    if (king_http3_lsquic_read_response(runtime, stream) != SUCCESS && runtime != NULL) {
        runtime->lsquic_request_failed = true;
    }
}

static void king_http3_lsquic_on_write(
    lsquic_stream_t *stream,
    lsquic_stream_ctx_t *stream_ctx)
{
    king_http3_request_runtime_t *runtime = king_http3_lsquic_runtime_from_stream_ctx(stream_ctx);

    if (runtime == NULL || stream == NULL || runtime->lsquic_request_failed) {
        return;
    }

    if (!runtime->lsquic_headers_sent
        && king_http3_lsquic_send_headers(runtime, stream) != SUCCESS) {
        runtime->lsquic_request_failed = true;
        return;
    }

    if (king_http3_lsquic_write_body(runtime, stream) != SUCCESS) {
        runtime->lsquic_request_failed = true;
    }
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

static zend_result king_http3_lsquic_runtime_prepare_request(
    king_http3_request_runtime_t *runtime,
    const quiche_h3_header *headers,
    size_t header_count,
    zend_string *body_string,
    king_http3_response_t *response,
    const char *function_name)
{
    if (runtime == NULL
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

    runtime->lsquic_request_headers = headers;
    runtime->lsquic_request_header_count = header_count;
    runtime->lsquic_body_string = body_string;
    runtime->lsquic_body_offset = 0;
    runtime->lsquic_response = response;
    runtime->lsquic_headers_sent = false;
    runtime->lsquic_request_failed = false;
    runtime->lsquic_hset_claimed = false;
    runtime->lsquic_stream_closed = false;

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
    runtime->lsquic_stream = NULL;
    runtime->lsquic_backend_active = false;
}

#endif
