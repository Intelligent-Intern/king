#if defined(KING_HTTP3_BACKEND_LSQUIC)

#define KING_SERVER_HTTP3_LSQUIC_CC_CUBIC 1U
#define KING_SERVER_HTTP3_LSQUIC_CC_BBR 2U

static zend_result king_server_http3_lsquic_reject_unsupported_long_if_changed(
    const char *function_name,
    const char *option_name,
    zend_long value,
    zend_long default_value)
{
    if (value == default_value) {
        return SUCCESS;
    }

    king_server_local_set_errorf(
        "%s() cannot apply quic.%s=" ZEND_LONG_FMT " with the active LSQUIC HTTP/3 server backend; LSQUIC does not expose this transport setting. Remove the override or use a backend that supports it.",
        function_name,
        option_name,
        value
    );
    return FAILURE;
}

static zend_result king_server_http3_lsquic_reject_unsupported_bool_if_changed(
    const char *function_name,
    const char *option_name,
    bool value,
    bool default_value)
{
    if (value == default_value) {
        return SUCCESS;
    }

    king_server_local_set_errorf(
        "%s() cannot apply quic.%s=%s with the active LSQUIC HTTP/3 server backend; LSQUIC does not expose this transport setting. Remove the override or use a backend that supports it.",
        function_name,
        option_name,
        value ? "true" : "false"
    );
    return FAILURE;
}

static zend_result king_server_http3_lsquic_option_to_unsigned(
    const char *function_name,
    const char *option_name,
    zend_long value,
    unsigned *target)
{
    if (value < 0 || value > (zend_long) UINT_MAX) {
        king_server_local_set_errorf(
            "%s() cannot apply quic.%s=" ZEND_LONG_FMT " with the active LSQUIC HTTP/3 server backend; LSQUIC expects this setting to fit into an unsigned 32-bit field.",
            function_name,
            option_name,
            value
        );
        return FAILURE;
    }

    *target = (unsigned) value;
    return SUCCESS;
}

static zend_result king_server_http3_lsquic_cc_algorithm_to_id(
    const char *function_name,
    const char *algorithm,
    unsigned *target)
{
    if (algorithm == NULL || algorithm[0] == '\0' || strcasecmp(algorithm, "cubic") == 0) {
        *target = KING_SERVER_HTTP3_LSQUIC_CC_CUBIC;
        return SUCCESS;
    }

    if (strcasecmp(algorithm, "bbr") == 0) {
        *target = KING_SERVER_HTTP3_LSQUIC_CC_BBR;
        return SUCCESS;
    }

    king_server_local_set_errorf(
        "%s() received unsupported quic.cc_algorithm='%s' for the active LSQUIC HTTP/3 server backend.",
        function_name,
        algorithm
    );
    return FAILURE;
}

static zend_result king_server_http3_lsquic_apply_quic_options(
    king_server_http3_runtime_t *runtime,
    const king_server_http3_options_t *options,
    const char *function_name)
{
    unsigned value;

    if (king_server_http3_lsquic_reject_unsupported_long_if_changed(function_name, "cc_initial_cwnd_packets", options->quic_cc_initial_cwnd_packets, 32) != SUCCESS
        || king_server_http3_lsquic_reject_unsupported_long_if_changed(function_name, "cc_min_cwnd_packets", options->quic_cc_min_cwnd_packets, 4) != SUCCESS
        || king_server_http3_lsquic_reject_unsupported_bool_if_changed(function_name, "cc_enable_hystart_plus_plus", options->quic_cc_enable_hystart_plus_plus, true) != SUCCESS
        || king_server_http3_lsquic_reject_unsupported_long_if_changed(function_name, "pacing_max_burst_packets", options->quic_pacing_max_burst_packets, 10) != SUCCESS
        || king_server_http3_lsquic_reject_unsupported_long_if_changed(function_name, "max_ack_delay_ms", options->quic_max_ack_delay_ms, 25) != SUCCESS
        || king_server_http3_lsquic_reject_unsupported_long_if_changed(function_name, "ack_delay_exponent", options->quic_ack_delay_exponent, 3) != SUCCESS
        || king_server_http3_lsquic_reject_unsupported_long_if_changed(function_name, "pto_timeout_ms_initial", options->quic_pto_timeout_ms_initial, 1000) != SUCCESS
        || king_server_http3_lsquic_reject_unsupported_long_if_changed(function_name, "pto_timeout_ms_max", options->quic_pto_timeout_ms_max, 60000) != SUCCESS
        || king_server_http3_lsquic_reject_unsupported_long_if_changed(function_name, "max_pto_probes", options->quic_max_pto_probes, 5) != SUCCESS
        || king_server_http3_lsquic_reject_unsupported_long_if_changed(function_name, "active_connection_id_limit", options->quic_active_connection_id_limit, 8) != SUCCESS
        || king_server_http3_lsquic_reject_unsupported_long_if_changed(function_name, "dgram_recv_queue_len", options->quic_dgram_recv_queue_len, 1024) != SUCCESS
        || king_server_http3_lsquic_reject_unsupported_long_if_changed(function_name, "dgram_send_queue_len", options->quic_dgram_send_queue_len, 1024) != SUCCESS) {
        return FAILURE;
    }

    if (options->quic_ping_interval_ms % 1000 != 0) {
        king_server_local_set_errorf(
            "%s() cannot apply quic.ping_interval_ms=" ZEND_LONG_FMT " exactly with the active LSQUIC HTTP/3 server backend; LSQUIC exposes ping period in whole seconds.",
            function_name,
            options->quic_ping_interval_ms
        );
        return FAILURE;
    }

    if (king_server_http3_lsquic_cc_algorithm_to_id(function_name, options->quic_cc_algorithm, &value) != SUCCESS) {
        return FAILURE;
    }
    runtime->lsquic_settings.es_cc_algo = value;
    runtime->lsquic_settings.es_pace_packets = options->quic_pacing_enable ? 1 : 0;
    runtime->lsquic_settings.es_ping_period = (unsigned) (options->quic_ping_interval_ms / 1000);

    if (king_server_http3_lsquic_option_to_unsigned(function_name, "initial_max_data", options->quic_initial_max_data, &runtime->lsquic_settings.es_init_max_data) != SUCCESS
        || king_server_http3_lsquic_option_to_unsigned(function_name, "initial_max_stream_data_bidi_local", options->quic_initial_max_stream_data_bidi_local, &runtime->lsquic_settings.es_init_max_stream_data_bidi_local) != SUCCESS
        || king_server_http3_lsquic_option_to_unsigned(function_name, "initial_max_stream_data_bidi_remote", options->quic_initial_max_stream_data_bidi_remote, &runtime->lsquic_settings.es_init_max_stream_data_bidi_remote) != SUCCESS
        || king_server_http3_lsquic_option_to_unsigned(function_name, "initial_max_stream_data_uni", options->quic_initial_max_stream_data_uni, &runtime->lsquic_settings.es_init_max_stream_data_uni) != SUCCESS
        || king_server_http3_lsquic_option_to_unsigned(function_name, "initial_max_streams_bidi", options->quic_initial_max_streams_bidi, &runtime->lsquic_settings.es_init_max_streams_bidi) != SUCCESS
        || king_server_http3_lsquic_option_to_unsigned(function_name, "initial_max_streams_uni", options->quic_initial_max_streams_uni, &runtime->lsquic_settings.es_init_max_streams_uni) != SUCCESS) {
        return FAILURE;
    }

    runtime->lsquic_settings.es_support_srej = options->quic_stateless_retry_enable ? 1 : 0;
    runtime->lsquic_settings.es_grease_quic_bit = options->quic_grease_enable ? 1 : 0;
    runtime->lsquic_settings.es_datagrams = options->quic_datagrams_enable ? 1 : 0;

    return SUCCESS;
}

#endif
