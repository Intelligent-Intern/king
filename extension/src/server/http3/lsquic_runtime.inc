#if defined(KING_HTTP3_BACKEND_LSQUIC)
static zend_long king_server_http3_lsquic_poll_timeout_ms(
    king_server_http3_runtime_t *runtime,
    zend_long fallback_ms)
{
    int diff_us = 0;

    if (runtime == NULL
        || runtime->lsquic_engine == NULL
        || king_server_http3_lsquic.lsquic_engine_earliest_adv_tick_fn == NULL
        || !king_server_http3_lsquic.lsquic_engine_earliest_adv_tick_fn(runtime->lsquic_engine, &diff_us)) {
        return fallback_ms;
    }

    if (diff_us <= 0) {
        return 0;
    }

    return (zend_long) ((diff_us + 999) / 1000);
}

static zend_result king_server_http3_lsquic_runtime_process_egress(
    king_server_http3_runtime_t *runtime,
    const char *function_name)
{
    unsigned guard = 0;

    if (runtime == NULL || runtime->lsquic_engine == NULL) {
        king_server_local_set_errorf(
            "%s() cannot process LSQUIC server egress without an initialized engine.",
            function_name
        );
        return FAILURE;
    }

    king_server_http3_lsquic.lsquic_engine_process_conns_fn(runtime->lsquic_engine);
    while (king_server_http3_lsquic.lsquic_engine_has_unsent_packets_fn(runtime->lsquic_engine)) {
        king_server_http3_lsquic.lsquic_engine_send_unsent_packets_fn(runtime->lsquic_engine);
        if (++guard > 1024) {
            king_server_local_set_errorf(
                "%s() LSQUIC server egress did not drain within the safety limit.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static int king_server_http3_lsquic_runtime_packet_in(
    king_server_http3_runtime_t *runtime,
    const unsigned char *packet,
    size_t packet_len,
    const struct sockaddr *from,
    int ecn)
{
    if (runtime == NULL
        || runtime->lsquic_engine == NULL
        || packet == NULL
        || packet_len == 0) {
        errno = EINVAL;
        return -1;
    }

    return king_server_http3_lsquic.lsquic_engine_packet_in_fn(
        runtime->lsquic_engine,
        packet,
        packet_len,
        (struct sockaddr *) &runtime->local_addr,
        from,
        runtime,
        ecn
    );
}

static zend_result king_server_http3_lsquic_runtime_init(
    king_server_http3_runtime_t *runtime,
    const king_server_http3_options_t *options,
    const char *function_name)
{
    const unsigned engine_flags = LSENG_HTTP_SERVER;
    char settings_error[256];
    unsigned idle_timeout_seconds;

    if (runtime == NULL || options == NULL) {
        king_server_local_set_errorf(
            "%s() cannot initialize LSQUIC with an incomplete server runtime context.",
            function_name
        );
        return FAILURE;
    }

    runtime->lsquic_backend_active = true;
    runtime->lsquic_ssl_ctx = king_server_http3_lsquic_create_ssl_ctx(options, function_name);
    if (runtime->lsquic_ssl_ctx == NULL) {
        return FAILURE;
    }

    king_server_http3_lsquic.lsquic_engine_init_settings_fn(&runtime->lsquic_settings, engine_flags);
    if (king_server_http3_lsquic_apply_quic_options(runtime, options, function_name) != SUCCESS) {
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
    runtime->lsquic_settings.es_max_header_list_size = KING_SERVER_HTTP3_MAX_HEADER_BYTES;
    runtime->lsquic_settings.es_max_udp_payload_size_rx = KING_SERVER_HTTP3_MAX_DATAGRAM_SIZE;
    runtime->lsquic_settings.es_allow_migration = 0;
    runtime->lsquic_settings.es_support_push = 0;

    memset(settings_error, 0, sizeof(settings_error));
    if (king_server_http3_lsquic.lsquic_engine_check_settings_fn(
            &runtime->lsquic_settings,
            engine_flags,
            settings_error,
            sizeof(settings_error)
        ) != 0) {
        king_server_local_set_errorf(
            "%s() received an invalid LSQUIC server settings profile: %s",
            function_name,
            settings_error[0] != '\0' ? settings_error : "unknown settings error"
        );
        return FAILURE;
    }

    memset(&runtime->lsquic_api, 0, sizeof(runtime->lsquic_api));
    runtime->lsquic_api.ea_settings = &runtime->lsquic_settings;
    runtime->lsquic_api.ea_stream_if = &king_server_http3_lsquic_stream_if;
    runtime->lsquic_api.ea_stream_if_ctx = runtime;
    runtime->lsquic_api.ea_packets_out = king_server_http3_lsquic_packets_out;
    runtime->lsquic_api.ea_packets_out_ctx = runtime;
    runtime->lsquic_api.ea_lookup_cert = king_server_http3_lsquic_lookup_cert;
    runtime->lsquic_api.ea_cert_lu_ctx = runtime;
    runtime->lsquic_api.ea_get_ssl_ctx = king_server_http3_lsquic_get_ssl_ctx;
    runtime->lsquic_api.ea_hsi_if = &king_server_http3_lsquic_hset_if;
    runtime->lsquic_api.ea_hsi_ctx = runtime;

    runtime->lsquic_engine = (lsquic_engine_t *) king_server_http3_lsquic.lsquic_engine_new_fn(
        engine_flags,
        &runtime->lsquic_api
    );
    if (runtime->lsquic_engine == NULL) {
        king_server_local_set_errorf(
            "%s() failed to allocate the active LSQUIC server engine.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

#endif
