static void king_http3_set_error(const char *format, ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
}

static void king_http3_throw(
    zend_class_entry *ce,
    const char *format,
    ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
    zend_throw_exception_ex(
        ce != NULL ? ce : king_ce_exception,
        0,
        "%s",
        message
    );
}

static zend_class_entry *king_http3_lsquic_loader_exception_class(void)
{
    switch (king_http3_lsquic.load_error_kind) {
        case KING_HTTP3_LSQUIC_LOAD_ERROR_LIBRARY:
        case KING_HTTP3_LSQUIC_LOAD_ERROR_SYMBOL:
        case KING_HTTP3_LSQUIC_LOAD_ERROR_GLOBAL_INIT:
            return king_ce_system_exception;

        case KING_HTTP3_LSQUIC_LOAD_ERROR_NONE:
        default:
            return king_ce_protocol_exception;
    }
}

static void king_http3_throw_lsquic_unavailable(const char *function_name)
{
    const char *detail = king_http3_lsquic.load_error[0] != '\0'
        ? king_http3_lsquic.load_error
        : "The LSQUIC runtime did not report a loader error.";

    king_http3_throw(
        king_http3_lsquic_loader_exception_class(),
        "%s() HTTP/3 LSQUIC runtime is unavailable: %s",
        function_name,
        detail
    );
}

#if !defined(KING_HTTP3_BACKEND_LSQUIC)
static void king_http3_throw_lsquic_build_required(const char *function_name)
{
    king_http3_throw(
        king_ce_protocol_exception,
        "%s() requires an LSQUIC-enabled HTTP/3 client build.",
        function_name
    );
}
#endif

#if !defined(KING_HTTP3_BACKEND_LSQUIC)
static void king_http3_format_close_reason(
    const uint8_t *reason_bytes,
    size_t reason_len,
    char *buffer,
    size_t buffer_len)
{
    size_t i;
    size_t copy_len;

    if (buffer_len == 0) {
        return;
    }

    if (reason_bytes == NULL || reason_len == 0) {
        buffer[0] = '\0';
        return;
    }

    copy_len = reason_len;
    if (copy_len > buffer_len - 1) {
        copy_len = buffer_len - 1;
    }

    for (i = 0; i < copy_len; ++i) {
        unsigned char c = reason_bytes[i];
        buffer[i] = isprint(c) ? (char) c : '?';
    }

    buffer[copy_len] = '\0';
}

static void king_http3_throw_handshake_close(
    const char *function_name,
    const char *origin,
    bool is_app,
    uint64_t error_code,
    const char *reason)
{
    if (reason != NULL && reason[0] != '\0') {
        king_http3_throw(
            is_app ? king_ce_protocol_exception : king_ce_quic_exception,
            "%s() did not complete the QUIC handshake because %s closed it with a %s error (code %" PRIu64 ", reason \"%s\").",
            function_name,
            origin,
            is_app ? "protocol" : "transport",
            error_code,
            reason
        );
        return;
    }

    king_http3_throw(
        is_app ? king_ce_protocol_exception : king_ce_quic_exception,
        "%s() did not complete the QUIC handshake because %s closed it with a %s error (code %" PRIu64 ").",
        function_name,
        origin,
        is_app ? "protocol" : "transport",
        error_code
    );
}

static void king_http3_throw_closed_connection(
    quiche_conn *conn,
    const char *function_name)
{
    bool established = king_http3_quiche.quiche_conn_is_established_fn(conn);
    bool is_app = false;
    uint64_t error_code = 0;
    const uint8_t *reason_bytes = NULL;
    size_t reason_len = 0;
    char reason[128];

    memset(reason, 0, sizeof(reason));

    if (king_http3_quiche.quiche_conn_peer_error_fn != NULL
        && king_http3_quiche.quiche_conn_peer_error_fn(
            conn,
            &is_app,
            &error_code,
            &reason_bytes,
            &reason_len
        )) {
        king_http3_format_close_reason(reason_bytes, reason_len, reason, sizeof(reason));

        if (!established) {
            king_http3_throw_handshake_close(
                function_name,
                "the peer",
                is_app,
                error_code,
                reason
            );
            return;
        }

        if (reason[0] != '\0') {
            king_http3_throw(
                is_app ? king_ce_protocol_exception : king_ce_quic_exception,
                "%s() received a %s close before the HTTP/3 response completed (code %" PRIu64 ", reason \"%s\").",
                function_name,
                is_app ? "protocol" : "QUIC transport",
                error_code,
                reason
            );
            return;
        }

        king_http3_throw(
            is_app ? king_ce_protocol_exception : king_ce_quic_exception,
            "%s() received a %s close before the HTTP/3 response completed (code %" PRIu64 ").",
            function_name,
            is_app ? "protocol" : "QUIC transport",
            error_code
        );
        return;
    }

    if (king_http3_quiche.quiche_conn_local_error_fn != NULL
        && king_http3_quiche.quiche_conn_local_error_fn(
            conn,
            &is_app,
            &error_code,
            &reason_bytes,
            &reason_len
        )) {
        king_http3_format_close_reason(reason_bytes, reason_len, reason, sizeof(reason));

        if (!established) {
            king_http3_throw_handshake_close(
                function_name,
                "the local runtime",
                is_app,
                error_code,
                reason
            );
            return;
        }

        if (reason[0] != '\0') {
            king_http3_throw(
                is_app ? king_ce_protocol_exception : king_ce_quic_exception,
                "%s() closed the active %s connection before the HTTP/3 response completed (code %" PRIu64 ", reason \"%s\").",
                function_name,
                is_app ? "protocol" : "QUIC transport",
                error_code,
                reason
            );
            return;
        }

        king_http3_throw(
            is_app ? king_ce_protocol_exception : king_ce_quic_exception,
            "%s() closed the active %s connection before the HTTP/3 response completed (code %" PRIu64 ").",
            function_name,
            is_app ? "protocol" : "QUIC transport",
            error_code
        );
        return;
    }

    if (king_http3_quiche.quiche_conn_is_timed_out_fn != NULL
        && king_http3_quiche.quiche_conn_is_timed_out_fn(conn)) {
        if (!established) {
            king_http3_throw(
                king_ce_quic_exception,
                "%s() did not complete the QUIC handshake before the negotiated idle timeout expired.",
                function_name
            );
            return;
        }

        king_http3_throw(
            king_ce_quic_exception,
            "%s() observed the active QUIC connection hit the negotiated idle timeout before the HTTP/3 response completed.",
            function_name
        );
        return;
    }

    if (!established) {
        king_http3_throw(
            king_ce_quic_exception,
            "%s() did not complete the QUIC handshake.",
            function_name
        );
        return;
    }

    king_http3_throw(
        king_ce_quic_exception,
        "%s() observed a closed QUIC connection before the HTTP/3 response completed.",
        function_name
    );
}

static void king_http3_throw_recv_error(
    quiche_conn *conn,
    const char *function_name,
    ssize_t error_code)
{
    bool established = king_http3_quiche.quiche_conn_is_established_fn(conn);

    if (!established
        && (error_code == QUICHE_ERR_TLS_FAIL || error_code == QUICHE_ERR_CRYPTO_FAIL)) {
        king_http3_throw(
            king_ce_tls_exception,
            "%s() failed during the QUIC/TLS handshake (%zd).",
            function_name,
            error_code
        );
        return;
    }

    if (!established) {
        king_http3_throw(
            king_ce_quic_exception,
            "%s() did not complete the QUIC handshake (%zd).",
            function_name,
            error_code
        );
        return;
    }

    king_http3_throw(
        king_ce_quic_exception,
        "%s() failed while processing an incoming QUIC datagram (%zd).",
        function_name,
        error_code
    );
}

static void king_http3_throw_request_body_send_error(
    const char *function_name,
    ssize_t error_code)
{
    switch (error_code) {
        case QUICHE_H3_TRANSPORT_ERR_STREAM_STOPPED:
            king_http3_throw(
                king_ce_protocol_exception,
                "%s() received QUIC STOP_SENDING while sending the active HTTP/3 request body.",
                function_name
            );
            return;

        case QUICHE_H3_TRANSPORT_ERR_STREAM_RESET:
            king_http3_throw(
                king_ce_protocol_exception,
                "%s() received QUIC RESET_STREAM while sending the active HTTP/3 request body.",
                function_name
            );
            return;

        default:
            king_http3_throw(
                king_ce_protocol_exception,
                "%s() failed while sending the active HTTP/3 request body (%zd).",
                function_name,
                error_code
            );
            return;
    }
}

static void king_http3_throw_multi_request_body_send_error(
    const char *function_name,
    zend_string *url,
    ssize_t error_code)
{
    switch (error_code) {
        case QUICHE_H3_TRANSPORT_ERR_STREAM_STOPPED:
            king_http3_throw(
                king_ce_protocol_exception,
                "%s() received QUIC STOP_SENDING while sending the HTTP/3 request body for request '%s'.",
                function_name,
                ZSTR_VAL(url)
            );
            return;

        case QUICHE_H3_TRANSPORT_ERR_STREAM_RESET:
            king_http3_throw(
                king_ce_protocol_exception,
                "%s() received QUIC RESET_STREAM while sending the HTTP/3 request body for request '%s'.",
                function_name,
                ZSTR_VAL(url)
            );
            return;

        default:
            king_http3_throw(
                king_ce_protocol_exception,
                "%s() failed while sending the HTTP/3 request body for request '%s' (%zd).",
                function_name,
                ZSTR_VAL(url),
                error_code
            );
            return;
    }
}

static void king_http3_throw_active_request_stream_reset(
    const char *function_name,
    quiche_h3_event *event)
{
    if (king_http3_quiche.quiche_h3_event_reset_error_fn != NULL) {
        king_http3_throw(
            king_ce_protocol_exception,
            "%s() received an HTTP/3 stream reset for the active request (code %" PRIu64 ").",
            function_name,
            king_http3_quiche.quiche_h3_event_reset_error_fn(event)
        );
        return;
    }

    king_http3_throw(
        king_ce_protocol_exception,
        "%s() received an HTTP/3 stream reset for the active request.",
        function_name
    );
}

static void king_http3_throw_multi_request_stream_reset(
    const char *function_name,
    zend_string *url,
    quiche_h3_event *event)
{
    if (king_http3_quiche.quiche_h3_event_reset_error_fn != NULL) {
        king_http3_throw(
            king_ce_protocol_exception,
            "%s() received an HTTP/3 stream reset for request '%s' (code %" PRIu64 ").",
            function_name,
            ZSTR_VAL(url),
            king_http3_quiche.quiche_h3_event_reset_error_fn(event)
        );
        return;
    }

    king_http3_throw(
        king_ce_protocol_exception,
        "%s() received an HTTP/3 stream reset for request '%s'.",
        function_name,
        ZSTR_VAL(url)
    );
}
#endif

static bool king_http3_is_token_char(unsigned char c)
{
    return isalnum(c)
        || c == '!'
        || c == '#'
        || c == '$'
        || c == '%'
        || c == '&'
        || c == '\''
        || c == '*'
        || c == '+'
        || c == '-'
        || c == '.'
        || c == '^'
        || c == '_'
        || c == '`'
        || c == '|'
        || c == '~';
}

static bool king_http3_string_has_crlf(const char *value, size_t value_len)
{
    size_t i;

    for (i = 0; i < value_len; ++i) {
        if (value[i] == '\r' || value[i] == '\n') {
            return true;
        }
    }

    return false;
}

static bool king_http3_runtime_is_in_early_data(
    const king_http3_request_runtime_t *runtime)
{
#if defined(KING_HTTP3_BACKEND_LSQUIC)
    (void) runtime;
    return false;
#else
    return runtime != NULL
        && runtime->conn != NULL
        && king_http3_quiche.quiche_conn_is_in_early_data_fn != NULL
        && king_http3_quiche.quiche_conn_is_in_early_data_fn(runtime->conn);
#endif
}

static bool king_http3_runtime_can_open_h3(
    const king_http3_request_runtime_t *runtime)
{
#if defined(KING_HTTP3_BACKEND_LSQUIC)
    (void) runtime;
    return false;
#else
    return runtime != NULL
        && runtime->conn != NULL
        && (
            king_http3_quiche.quiche_conn_is_established_fn(runtime->conn)
            || king_http3_runtime_is_in_early_data(runtime)
        );
#endif
}

static void king_http3_refresh_transport_stats(
    king_http3_request_runtime_t *runtime)
{
#if !defined(KING_HTTP3_BACKEND_LSQUIC)
    king_http3_quiche_stats_t stats;
#endif

#if defined(KING_HTTP3_BACKEND_LSQUIC)
    if (runtime != NULL && runtime->lsquic_backend_active) {
        king_http3_lsquic_refresh_transport_stats(runtime);
        return;
    }
#else

    if (runtime == NULL
        || runtime->conn == NULL
        || king_http3_quiche.quiche_conn_stats_fn == NULL) {
        return;
    }

    memset(&stats, 0, sizeof(stats));
    king_http3_quiche.quiche_conn_stats_fn(runtime->conn, &stats);

    runtime->quic_packets_sent = (zend_long) stats.sent;
    runtime->quic_packets_received = (zend_long) stats.recv;
    runtime->quic_packets_lost = (zend_long) stats.lost;
    runtime->quic_packets_retransmitted = (zend_long) stats.retrans;
    runtime->quic_lost_bytes = (zend_long) stats.lost_bytes;
    runtime->quic_stream_retransmitted_bytes =
        (zend_long) stats.stream_retrans_bytes;
#endif
}

static zend_result king_http3_validate_method(
    const char *method_str,
    size_t method_len,
    const char *function_name)
{
    size_t i;

    if (method_len == 0) {
        king_http3_set_error("%s() requires a non-empty HTTP method.", function_name);
        return FAILURE;
    }

    for (i = 0; i < method_len; ++i) {
        if (!king_http3_is_token_char((unsigned char) method_str[i])) {
            king_http3_set_error(
                "%s() method contains invalid HTTP token characters.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static zend_result king_http3_validate_positive_timeout(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        king_http3_set_error(
            "%s() option '%s' must be provided as an integer.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    if (Z_LVAL_P(value) <= 0) {
        king_http3_set_error(
            "%s() option '%s' must be > 0.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_result king_http3_parse_options(
    zval *options_array,
    king_http3_request_options_t *options,
    const char *function_name)
{
    zval *option_value;

    options->config = NULL;
    options->connect_timeout_ms = king_tcp_transport_config.connect_timeout_ms > 0
        ? king_tcp_transport_config.connect_timeout_ms
        : 5000;
    options->timeout_ms = KING_HTTP3_DEFAULT_TIMEOUT_MS;
    options->tls_verify_peer = king_tls_and_crypto_config.tls_verify_peer;
    options->tls_enable_early_data = king_tls_and_crypto_config.tls_enable_early_data;
    options->tls_default_ca_file = king_tls_and_crypto_config.tls_default_ca_file;
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
    options->cancel_token = king_transport_cancel_token_from_options(options_array);
    options->cancel_function_name = king_transport_cancel_function_name_from_options(options_array);
    options->cancel_exception_ce = king_transport_cancel_exception_ce_from_options(options_array);

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return SUCCESS;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "connection_config",
        sizeof("connection_config") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        options->config = (king_cfg_t *) king_fetch_config(option_value);
        if (options->config == NULL) {
            king_http3_set_error(
                "%s() option 'connection_config' must be a King\\Config resource or object.",
                function_name
            );
            return FAILURE;
        }

        options->connect_timeout_ms = options->config->tcp.connect_timeout_ms > 0
            ? options->config->tcp.connect_timeout_ms
            : options->connect_timeout_ms;
        options->timeout_ms = options->connect_timeout_ms > KING_HTTP3_DEFAULT_TIMEOUT_MS
            ? options->connect_timeout_ms
            : KING_HTTP3_DEFAULT_TIMEOUT_MS;
        options->tls_verify_peer = options->config->tls.tls_verify_peer;
        options->tls_enable_early_data = options->config->tls.tls_enable_early_data;
        options->tls_default_ca_file = options->config->tls.tls_default_ca_file;
        options->tls_default_cert_file = options->config->tls.tls_default_cert_file;
        options->tls_default_key_file = options->config->tls.tls_default_key_file;
        options->quic_cc_algorithm = options->config->quic.cc_algorithm;
        options->quic_cc_initial_cwnd_packets = options->config->quic.cc_initial_cwnd_packets;
        options->quic_cc_min_cwnd_packets = options->config->quic.cc_min_cwnd_packets;
        options->quic_cc_enable_hystart_plus_plus = options->config->quic.cc_enable_hystart_plus_plus;
        options->quic_pacing_enable = options->config->quic.pacing_enable;
        options->quic_pacing_max_burst_packets = options->config->quic.pacing_max_burst_packets;
        options->quic_max_ack_delay_ms = options->config->quic.max_ack_delay_ms;
        options->quic_ack_delay_exponent = options->config->quic.ack_delay_exponent;
        options->quic_pto_timeout_ms_initial = options->config->quic.pto_timeout_ms_initial;
        options->quic_pto_timeout_ms_max = options->config->quic.pto_timeout_ms_max;
        options->quic_max_pto_probes = options->config->quic.max_pto_probes;
        options->quic_ping_interval_ms = options->config->quic.ping_interval_ms;
        options->quic_initial_max_data = options->config->quic.initial_max_data;
        options->quic_initial_max_stream_data_bidi_local = options->config->quic.initial_max_stream_data_bidi_local;
        options->quic_initial_max_stream_data_bidi_remote = options->config->quic.initial_max_stream_data_bidi_remote;
        options->quic_initial_max_stream_data_uni = options->config->quic.initial_max_stream_data_uni;
        options->quic_initial_max_streams_bidi = options->config->quic.initial_max_streams_bidi;
        options->quic_initial_max_streams_uni = options->config->quic.initial_max_streams_uni;
        options->quic_active_connection_id_limit = options->config->quic.active_connection_id_limit;
        options->quic_stateless_retry_enable = options->config->quic.stateless_retry_enable;
        options->quic_grease_enable = options->config->quic.grease_enable;
        options->quic_datagrams_enable = options->config->quic.datagrams_enable;
        options->quic_dgram_recv_queue_len = options->config->quic.dgram_recv_queue_len;
        options->quic_dgram_send_queue_len = options->config->quic.dgram_send_queue_len;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "connect_timeout_ms",
        sizeof("connect_timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_http3_validate_positive_timeout(
                option_value,
                "connect_timeout_ms",
                &options->connect_timeout_ms,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "timeout_ms",
        sizeof("timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_http3_validate_positive_timeout(
                option_value,
                "timeout_ms",
                &options->timeout_ms,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    return SUCCESS;
}
